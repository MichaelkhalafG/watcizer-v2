<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Typed access to an Inertia page's props from a test.
 *
 * `$response->viewData('page')['props']['nav']` is `mixed` all the way down, and PHPStan runs at
 * level 10 over `tests/` too. Narrowing once here — loudly, with an assertion rather than a cast —
 * keeps the shell tests readable and stops a broken prop from silently comparing null to null.
 *
 * Companion to {@see T}, which does the same job for query-builder reads.
 */
final class Props
{
    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    public static function of(TestResponse $response): array
    {
        $page = $response->viewData('page');
        Assert::assertIsArray($page, 'the response did not render an Inertia page');
        $props = $page['props'] ?? null;
        Assert::assertIsArray($props, 'the Inertia page carries no props');

        $out = [];
        foreach ($props as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * The shell's navigation, flattened to `key => item` so a test can ask about one entry
     * without walking groups.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, array<string, mixed>>
     */
    public static function navItems(TestResponse $response): array
    {
        $nav = self::of($response)['nav'] ?? null;
        Assert::assertIsArray($nav, 'the page shares no nav');

        $items = [];
        foreach ($nav as $group) {
            Assert::assertIsArray($group);
            $groupItems = $group['items'] ?? null;
            Assert::assertIsArray($groupItems, 'a nav group carries no items');
            foreach ($groupItems as $item) {
                Assert::assertIsArray($item);
                $key = $item['key'] ?? null;
                Assert::assertIsString($key, 'a nav item carries no key');
                $normalised = [];
                foreach ($item as $field => $value) {
                    $normalised[(string) $field] = $value;
                }
                $items[$key] = $normalised;
            }
        }

        return $items;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string> the nav keys, in the order the shell will render them
     */
    public static function navKeys(TestResponse $response): array
    {
        return array_keys(self::navItems($response));
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string> the keys of every nav item marked active
     */
    public static function activeNavKeys(TestResponse $response): array
    {
        $active = [];
        foreach (self::navItems($response) as $key => $item) {
            if (($item['active'] ?? false) === true) {
                $active[] = $key;
            }
        }

        return $active;
    }
}
