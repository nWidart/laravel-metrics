---
title: Scrape or push
description: The trade-offs between serving GET /metrics to Prometheus and pushing with metrics:push over OTLP.
---

The package has two ways to get metrics out of the application. Prometheus can scrape `GET /metrics`, or `php artisan metrics:push` can send them to an OTLP endpoint on a schedule. Both read the same store and send the same export, so the choice is about your backend and your operations, not about the data.

## What both have in common

An export always reads the store. It never reads one process. The store holds the cumulative totals of every worker and host that share it, so one export describes the whole application.

Both send cumulative values. A cumulative value is the total so far, not the change since the last read. This makes every export idempotent:

- A lost push or a failed scrape loses nothing. The next one carries the totals, including what the lost one would have sent.
- A repeated push does no harm. It sends the same totals again.

Delta values would have the opposite property: every lost delta is lost for good, and every repeated delta counts twice.

## Scrape

The scrape fits applications that already run Prometheus, or an agent that scrapes Prometheus targets.

- **Nothing to schedule.** Prometheus decides when to read.
- **Prometheus sees failures.** If the store cannot be read, the route answers 503, and Prometheus marks the target down. An empty answer would look like an application with no traffic.
- **The route is an attack surface.** Metrics can tell more about the application than it should share: routes, job names, error rates. The route is off by default, and it needs middleware or a network rule to protect it.
- **Scrape one address.** Every web server serves the same store. Scraping each server would read the same totals several times and add them up. Scrape one address, such as the load balancer.
- **The scrape is a request.** It records its own `http.server.request.duration`, unless you ignore the route.

## Push

The push fits backends that accept OTLP: an OpenTelemetry Collector, or a vendor that takes OTLP directly.

- **No inbound route.** The application only makes outbound calls, so there is nothing to protect.
- **It needs the scheduler.** `metrics:push` runs from the schedule, typically every minute.
- **Run one pusher.** The data point timestamp is the time of the export. Two pushers with clocks out of step can send an older timestamp after a newer one, and Prometheus's OTLP receiver rejects out-of-order samples. The values are cumulative, so the next push repairs the gap, but one pusher avoids it. When several servers run the scheduler, `onOneServer()` keeps the push on one.
- **Push more often than the backend forgets.** The Collector's Prometheus exporter drops a series after `metric_expiration`, five minutes by default, without a push. A push every minute stays well inside that window.

### The resource of a push

OTLP carries a resource: attributes that describe who sent the data. A typical OpenTelemetry application fills it with resource detectors: host name, process ID, container ID.

The push sends only `service.name`, `deployment.environment.name`, and the attributes you configure. It sends no host or process attributes on purpose. The store holds the totals of every process and host, so a host attribute would claim that one server did all the work. The request side has no resource at all, because the resource detectors cost time on every request and the store does not keep a resource.

## Metrics read on export

Some metrics are not stored. The [metrics read on export](/laravel-metrics/reference/metrics/export-time/), such as OPcache and PHP-FPM statistics, load average and disk usage, are read by the process that runs the export. This makes the choice of export visible in those numbers:

- **On a scrape**, the export runs in a PHP-FPM request on the server that the load balancer picked. OPcache and PHP-FPM metrics describe that server's pool.
- **On a push**, the export runs on the command line. OPcache is off on the command line by default, so a push usually has no OPcache metrics. There are no PHP-FPM metrics either, because the command does not run under PHP-FPM.

In both cases these values describe one process and one server, not the whole application. That is a limit of reading at export time, and the reason the package does not store them: adding the OPcache memory of each request would make no sense.

## Which one to choose

Use the scrape when Prometheus is already your backend and you can protect the route. Use the push when your backend speaks OTLP, when you cannot expose a route, or when you want the resource attributes that OTLP carries. Nothing stops you from running both against the same store.

## Related

- [Scrape with Prometheus](/laravel-metrics/guides/scrape-with-prometheus/)
- [Push over OTLP](/laravel-metrics/guides/push-over-otlp/)
- [Prometheus endpoint reference](/laravel-metrics/reference/prometheus-endpoint/)
- [metrics:push reference](/laravel-metrics/reference/metrics-push/)
