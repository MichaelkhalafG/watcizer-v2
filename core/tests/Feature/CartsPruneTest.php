<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\T;

use function Pest\Laravel\artisan;

/*
 * carts:prune (2026-09-28, C3) — core's own guest-cart prune, replacing the legacy one that stops
 * with the legacy site's cron line. The case that matters most is the one the LEGACY rule got
 * wrong: a guest still shopping on day 8 — their cart's `expires_at` long past, its own
 * `updated_at` old, but a line touched yesterday. That cart must survive.
 */

/** A cart with one line, stamped as if created and last touched at the given ages. */
function pruneCart(?int $userId, int $cartIdleDays, ?int $lineIdleDays): int
{
    $at = fn (int $days): string => now()->subDays($days)->toDateTimeString();
    $id = (int) DB::table('carts')->insertGetId([
        'user_id' => $userId,
        'guest_token' => $userId === null ? (string) Str::uuid() : null,
        'created_at' => $at($cartIdleDays + 1),
        'updated_at' => $at($cartIdleDays),
        'expires_at' => $userId === null ? $at($cartIdleDays - 6) : null, // core's creation + 7 days, never extended
    ]);
    if ($lineIdleDays !== null) {
        DB::table('cart_items')->insert([
            'cart_id' => $id, 'product_id' => T::int(DB::table('products')->min('id')), 'quantity' => 1,
            'piece_price' => '100.00', 'total_price' => '100.00', 'type_stock' => 'Market',
            'created_at' => $at($lineIdleDays), 'updated_at' => $at($lineIdleDays),
        ]);
    }

    return $id;
}

/** @param  array<string, mixed>  $args */
function runPrune(array $args = []): void
{
    $pending = artisan('carts:prune', $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->assertSuccessful()->run();
}

function cartExists(int $id): bool
{
    return DB::table('carts')->where('id', $id)->exists();
}

it('deletes a guest cart idle 30+ days, with its lines, and keeps everything that is not', function () {
    $userId = T::int(DB::table('users')->min('id'));
    $idle = pruneCart(null, 40, 40);
    $empty = pruneCart(null, 40, null);
    $stillShopping = pruneCart(null, 40, 1);     // expires_at long past, a line touched yesterday
    $recent = pruneCart(null, 10, 10);
    $signedIn = pruneCart($userId, 100, 100);    // an account's cart: never pruned

    runPrune();

    expect(cartExists($idle))->toBeFalse()
        ->and(DB::table('cart_items')->where('cart_id', $idle)->exists())->toBeFalse()
        ->and(cartExists($empty))->toBeFalse()
        ->and(cartExists($stillShopping))->toBeTrue()
        ->and(cartExists($recent))->toBeTrue()
        ->and(cartExists($signedIn))->toBeTrue();
});

it('changes nothing on a dry run', function () {
    $idle = pruneCart(null, 40, 40);

    runPrune(['--dry-run' => true]);

    expect(cartExists($idle))->toBeTrue();
});

it('deletes a backlog larger than one batch', function () {
    $ids = [];
    for ($i = 0; $i < 520; $i++) {
        $ids[] = pruneCart(null, 45, null);
    }

    runPrune();

    expect(DB::table('carts')->whereIn('id', $ids)->count())->toBe(0);
});

it('refuses an idle window shorter than 14 days', function () {
    $recent = pruneCart(null, 10, 10);
    $pending = artisan('carts:prune', ['--days' => 7]);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->assertFailed()->run();

    expect(cartExists($recent))->toBeTrue();
});
