<?php

use Tests\Feature\V2\ApiTestHelpers as H;

use function Pest\Laravel\getJson;

/*
 * Hostile query parameters on the v2 listing and detail: every input answers 200 (with a
 * sane page), 404 or 422 — never 500, never a leak, never a server-side error page.
 */

beforeEach(fn () => H::flush());

it('never 500s on hostile listing parameters', function (string $query, int $status) {
    $res = getJson(H::base('products?'.$query));
    $res->assertStatus($status);
    $body = (string) $res->getContent();
    expect($body)->not->toContain('App'.chr(92));
    expect($body)->not->toContain('D:'.chr(92));
})->with([
    ['page=0', 422], ['page=-1', 422], ['page=abc', 422], ['page=999999', 200], ['per_page=0', 422], ['per_page=abc', 422],
    ['sort=DROP%20TABLE', 422], ['category=..%2F..%2Fetc', 404], ['category=%00', 200], ['brand=%27%20OR%201%3D1', 200],
    ['gender=1,x,-3', 200], ['color=999999', 200], ['price_min=-1', 422], ['price_max=abc', 422], ['price_min=1e308', 200],
    ['q=%25%25%25', 200], ['q=%5F', 200], ['q=%22%27%3C%3E', 200], ['q=%2B-%3E%3C%28%29~%2A', 200], ['q='.str_repeat('a', 121), 422],
    ['in_stock=maybe', 422], ['locale=..', 200], ['locale='.str_repeat('x', 6), 422],
]);

it('answers a huge page number with an empty page, not an error', function () {
    $res = getJson(H::base('products?page=99999'))->assertOk();
    expect($res->json('data'))->toBe([])->and($res->json('links.next'))->toBeNull();
});

it('404s hostile product slugs and category paths without leaking', function (string $path) {
    $res = getJson(H::base($path));
    expect($res->getStatusCode())->toBeIn([404, 422]);
    $body = (string) $res->getContent();
    expect($body)->not->toContain('App'.chr(92));
    expect($body)->not->toContain('SQLSTATE');
})->with(['products/%00', 'products/..', "products/'%20OR%201=1", 'categories/watches/..', 'categories/%27', 'products/'.str_repeat('a', 300)]);
