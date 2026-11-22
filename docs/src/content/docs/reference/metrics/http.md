---
title: HTTP requests
description: The http.server.request.duration histogram, its attributes and when it is recorded.
---

`Nwidart\Metrics\Instrumentation\HttpServerMetrics` records the duration of each HTTP request, as the OpenTelemetry HTTP semantic conventions define it.

## Instrument

| Property | Value |
|---|---|
| Name | `http.server.request.duration` |
| Kind | Histogram |
| Unit | `s` |
| Buckets | `0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10` |
| Description | `Duration of HTTP server requests.` |
| Meter | `nwidart/laravel-metrics` |
| Prometheus name | `http_server_request_duration_seconds` |

The duration runs from `REQUEST_TIME_FLOAT`, the time the request arrived, to the `RequestHandled` event, when the response is ready to send. Without `REQUEST_TIME_FLOAT`, the duration is `0`. A negative duration is recorded as `0`.

## Attributes

| Attribute | Type | Values | Present when |
|---|---|---|---|
| `http.request.method` | string | `CONNECT`, `DELETE`, `GET`, `HEAD`, `OPTIONS`, `PATCH`, `POST`, `PUT`, `TRACE`, or `_OTHER` for any other method | Always |
| `http.response.status_code` | int | `200`, `404`, ... | Always |
| `url.scheme` | string | `http`, `https` | Always |
| `http.route` | string | The URI template with a leading `/`, such as `/orders/{order}` | The request matched a route |
| `network.protocol.version` | string | `1.0`, `1.1`, `2.0` ... | `SERVER_PROTOCOL` starts with `HTTP/` |
| `error.type` | string | The status code, such as `500` | The status code is 500 or more |

`http.request.method` is the real method (`getRealMethod()`), not the method that a `_method` field overrides.

## Recorded when

| Event | Effect |
|---|---|
| `Illuminate\Foundation\Http\Events\RequestHandled` | Records one value |

## Not recorded

- Requests whose route matches [`ignore_routes`](/laravel-metrics/reference/configuration/#ignore_routes).
- Requests that end before Laravel dispatches `RequestHandled`.

A request that matched no route, such as a 404, is recorded without `http.route`.

## Attribute contributor context

A [`Metrics::attributes()`](/laravel-metrics/reference/facade/#attributes) callback for `http.server.request.duration` receives the `RequestHandled` event, with `$event->request` and `$event->response`.

## Switch

| Key | Variable | Default |
|---|---|---|
| `instrumentation.http` | `METRICS_HTTP` | `true` |
