<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * The routes of metrics.ignore_routes, which the request metrics leave out. A pattern that starts
 * with "/" matches the URI template, any other the route name.
 *
 * @internal
 */
final class IgnoredRoutes
{
    /** @var list<string> */
    private readonly array $patterns;

    public function __construct(Repository $config)
    {
        $ignored = $config->get('metrics.ignore_routes');
        $this->patterns = array_values(array_filter(is_array($ignored) ? $ignored : [], is_string(...)));
    }

    /** The URI template of the route, as http.route holds it, such as "/users/{user}". */
    public static function template(Route $route): string
    {
        return '/'.ltrim($route->uri(), '/');
    }

    public function matches(Route $route): bool
    {
        if ($this->patterns === []) {
            return false;
        }

        $uri = self::template($route);
        $name = (string) $route->getName();
        foreach ($this->patterns as $pattern) {
            if (str_starts_with($pattern, '/') ? Str::is($pattern, $uri) : ($name !== '' && Str::is($pattern, $name))) {
                return true;
            }
        }

        return false;
    }
}
