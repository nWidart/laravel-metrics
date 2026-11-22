---
title: Requirements
description: The PHP, Laravel, Redis and HTTP client versions the package needs, and its dependencies.
---

## Runtime

| Requirement | Version | Needed for |
|---|---|---|
| PHP | 8.2 or later | Always |
| Laravel (`illuminate/contracts`, `illuminate/support`) | 12 or 13 | Always |
| Redis, with phpredis (`ext-redis`) or `predis/predis` | Tested with 7.4 | The `redis` store |
| A Laravel cache store with locks | Every Laravel cache driver except `apc` | The `cache` store |
| A PSR-18 HTTP client, such as Guzzle | | `metrics:push` |

The package does not need `ext-opentelemetry`.

## Dependencies

| Package | Constraint |
|---|---|
| `nwidart/metrics-store` | `^0.1.1` |
| `open-telemetry/sdk` | `^1.17` |
| `open-telemetry/exporter-otlp` | `^1.6` |
| `illuminate/contracts` | `^12.0\|^13.0` |
| `illuminate/support` | `^12.0\|^13.0` |

## Tested combinations

CI runs the tests on PHP 8.2, 8.3, 8.4 and 8.5, with Laravel 12 and 13, at the lowest and the newest dependency versions. Laravel 13 is not tested on PHP 8.2. The tests use Redis 7.4.

## Not tested

- Laravel Octane.
- Redis Cluster. A phpredis cluster connection is not supported.

## Service provider

`Nwidart\Metrics\MetricsServiceProvider` registers through package discovery (`extra.laravel.providers` in `composer.json`).
