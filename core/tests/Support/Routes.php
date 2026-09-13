<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Route;

/**
 * Typed access to the route table, for the tests that assert a door is ABSENT.
 *
 * Several wave-4C claims are negative — the ledger has no write route, the users surface writes
 * nothing but grants — and the only honest way to hold them is to enumerate the routes and fail on
 * a new one. `Route::getRoutes()` is a collection of `mixed` and `methods()` is an untyped array,
 * so each such test was repeating the same narrowings; they live here once instead.
 *
 * Companion to {@see T} (query reads) and {@see Props} (Inertia props).
 */
final class Routes
{
    /** @var list<string> */
    private const WRITE_VERBS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Every route under a URI prefix, as "VERB|VERB uri" strings — for reading a surface.
     *
     * @return list<string>
     */
    public static function under(string $prefix): array
    {
        $out = [];
        foreach (self::rows($prefix) as [$uri, $verbs]) {
            $out[] = implode('|', $verbs).' '.$uri;
        }

        sort($out);

        return $out;
    }

    /**
     * The URIs of every WRITE route under a prefix — the list a "no door here" assertion compares.
     *
     * @return list<string>
     */
    public static function writeUris(string $prefix): array
    {
        $out = [];
        foreach (self::rows($prefix) as [$uri, $verbs]) {
            if (array_intersect($verbs, self::WRITE_VERBS) !== []) {
                $out[] = $uri;
            }
        }

        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * Every route under the prefix as `[uri, verbs]`, with both narrowed to strings.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private static function rows(string $prefix): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, $prefix)) {
                continue;
            }

            $verbs = [];
            foreach ($route->methods() as $verb) {
                if (is_string($verb)) {
                    $verbs[] = $verb;
                }
            }

            $out[] = [$uri, $verbs];
        }

        return $out;
    }
}
