# Laravel Metrics

OpenTelemetry metrics for Laravel that work under PHP-FPM.

> **Status:** early development. Not usable yet.

## Requirements

- PHP 8.2 or later
- Laravel 12 or 13
- Redis

## Configuration

To change the defaults, publish the configuration file:

```bash
php artisan vendor:publish --tag=metrics-config
```
