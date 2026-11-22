---
title: Scrape with Prometheus
description: Serve the metrics at GET /metrics, protect the route, and point Prometheus at it.
---

To let Prometheus read the metrics, turn on the scrape route, protect it, and add one scrape target.

## 1. Turn on the route

```bash title=".env"
METRICS_PROMETHEUS=true
```

`GET /metrics` now serves the export in the Prometheus text format.

To serve it at another path:

```bash title=".env"
METRICS_PROMETHEUS_PATH=internal/metrics
```

If the application caches its routes, rebuild both caches after each change to these two variables. With cached routes, the package does not register the route itself, and the route cache is built from the cached configuration.

```bash
php artisan config:cache
php artisan route:cache
```

## 2. Protect the route

The route is open by default once it is on. Protect it with middleware, or keep it off the public network.

To require a bearer token:

1. Create a middleware:

   ```php title="app/Http/Middleware/EnsureMetricsToken.php"
   <?php

   namespace App\Http\Middleware;

   use Closure;
   use Illuminate\Http\Request;
   use Symfony\Component\HttpFoundation\Response;

   class EnsureMetricsToken
   {
       public function handle(Request $request, Closure $next): Response
       {
           $token = (string) config('services.metrics.token');

           abort_unless($token !== '' && hash_equals($token, (string) $request->bearerToken()), 403);

           return $next($request);
       }
   }
   ```

2. Add the token to `config/services.php` and `.env`:

   ```php title="config/services.php"
   'metrics' => [
       'token' => env('METRICS_TOKEN'),
   ],
   ```

3. Publish the package configuration and add the middleware to the route:

   ```bash
   php artisan vendor:publish --tag=metrics-config
   ```

   ```php title="config/metrics.php"
   'prometheus' => [
       'enabled' => env('METRICS_PROMETHEUS', false),
       'path' => env('METRICS_PROMETHEUS_PATH', 'metrics'),
       'middleware' => [\App\Http\Middleware\EnsureMetricsToken::class],
       'labels' => [],
   ],
   ```

`prometheus.middleware` takes aliases or class names. To allow only some addresses instead, write a middleware that checks `$request->ip()`, or block the path at the web server or the load balancer.

## 3. Add a scrape target

Every worker and every host merges into one store, so every address serves the same numbers. Scrape **one** address, such as the load balancer or the public host name. Scraping each server gives the same series several times, under different `instance` labels. Only the [metrics read on export](/laravel-metrics/reference/metrics/export-time/), such as OPcache and PHP-FPM, describe the server that answers the scrape.

With a plain `prometheus.yml`:

```yaml title="prometheus.yml"
scrape_configs:
  - job_name: shop
    scheme: https
    metrics_path: /metrics
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/shop-metrics-token
    static_configs:
      - targets: ['shop.example.com']
```

### On Kubernetes

A `ServiceMonitor` or a pod monitor scrapes every pod, which duplicates every series. Scrape the Service instead, with one static target. With the Prometheus Operator, use a `ScrapeConfig`:

```yaml title="scrape-config.yaml"
apiVersion: monitoring.coreos.com/v1alpha1
kind: ScrapeConfig
metadata:
  name: shop
spec:
  metricsPath: /metrics
  authorization:
    type: Bearer
    credentials:
      name: shop-metrics-token
      key: token
  staticConfigs:
    - targets: ['shop.default.svc.cluster.local:80']
```

## 4. Leave the scrape out of the HTTP metrics

The scrape is an HTTP request like the others. It appears in `http.server.request.duration` with `http.route` = `/metrics`. To leave it out, ignore the route by its name:

```php title="config/metrics.php"
'ignore_routes' => ['metrics.prometheus'],
```

## 5. Check the result

```bash
curl -H "Authorization: Bearer $METRICS_TOKEN" https://shop.example.com/metrics
```

The answer holds lines such as `http_server_request_duration_seconds_bucket{...}`. A 503 means the store cannot be read. See [Troubleshoot](/laravel-metrics/guides/troubleshoot/).

## Add labels to every sample

To tell applications apart in one Prometheus, add a label to every sample:

```php title="config/metrics.php"
'labels' => ['service' => 'shop'],
```

Prometheus can also add it with `labels` in `static_configs`.

## Example queries

Request rate per route:

```text
sum by (http_route) (rate(http_server_request_duration_seconds_count[5m]))
```

95th percentile of the request duration, per route:

```text
histogram_quantile(0.95, sum by (le, http_route) (rate(http_server_request_duration_seconds_bucket[5m])))
```

Share of requests that answer 5xx:

```text
sum(rate(http_server_request_duration_seconds_count{http_response_status_code=~"5.."}[5m]))
  / sum(rate(http_server_request_duration_seconds_count[5m]))
```

Cache hit ratio:

```text
sum(rate(laravel_cache_operations_total{laravel_cache_result="hit"}[5m]))
  / sum(rate(laravel_cache_operations_total{laravel_cache_operation="read"}[5m]))
```

## Related

- [Prometheus endpoint reference](/laravel-metrics/reference/prometheus-endpoint/)
- [Metric names](/laravel-metrics/explanation/naming/)
- [Scrape or push](/laravel-metrics/explanation/scrape-or-push/)
