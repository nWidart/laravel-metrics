---
title: Ignore routes
description: Keep health probes, scrapes and dashboards out of http.server.request.duration.
---

To leave routes out of `http.server.request.duration`, list them in `ignore_routes`. Requests to these routes are not recorded.

## 1. Publish the configuration

```bash
php artisan vendor:publish --tag=metrics-config
```

## 2. List the routes

```php title="config/metrics.php"
'ignore_routes' => ['/up', 'horizon.*'],
```

Each pattern matches one of two things:

- A pattern that starts with `/` matches the URI template of the route, such as `/up` or `/users/{user}`.
- Any other pattern matches the route name, such as `horizon.index`.

`*` is a wildcard in both.

## Common patterns

| To ignore | Pattern |
|---|---|
| Laravel's health route | `'/up'` |
| The scrape route of this package | `'metrics.prometheus'` |
| Laravel Horizon's dashboard and API | `'horizon.*'` |
| Laravel Telescope | `'/telescope*'` |
| Laravel Pulse | `'/pulse'` |
| Every route under a prefix | `'/internal/*'` |

Check the names and URIs of your routes with `php artisan route:list`. A route without a name matches only a `/` pattern.

## 3. Rebuild the configuration cache

If the configuration is cached:

```bash
php artisan config:cache
```

## What is not affected

- Only `http.server.request.duration` leaves these routes out. Queries, cache operations and exceptions inside an ignored request are still recorded.
- A request that matches no route, such as a 404, has no `http.route` and is never ignored.

## Related

- [Built-in metrics: HTTP](/laravel-metrics/reference/metrics/http/)
- [Configuration reference](/laravel-metrics/reference/configuration/)
- [Reduce the overhead](/laravel-metrics/guides/reduce-overhead/)
