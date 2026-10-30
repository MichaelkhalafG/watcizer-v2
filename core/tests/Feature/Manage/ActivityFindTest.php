<?php

use App\Domain\Access\UserWriteGuard;
use App\Domain\Activity\ActivityLog;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * THE ACTIVITY LOG, MADE ANSWERABLE (M1w, 2026-10-30): find a product's rows by any part of its SKU or its name;
 * filter by kind (price, stock, bulk, sign-in); a sign-in is recorded; a bulk action tags its rows; a product row
 * carries its SKU and a link; and the product form's History tab shows the log AND the system's stock movements.
 *
 * The product is chosen from the data and given a known SKU inside the test transaction.
 */

/** @return array{id: int, sku: string} */
function afProduct(string $sku = 'NIGHTSKU-7731'): array
{
    $id = T::int(DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1))
        ->whereNull('p.deleted_at')->orderBy('p.id')->value('p.id'));
    DB::table('catalog_products')->where('id', $id)->update(['sku' => $sku]);

    return ['id' => $id, 'sku' => $sku];
}

/** @return list<array<string, mixed>> */
function afRows(string $query): array
{
    return Props::rows(Props::table(get('/manage/activity'.$query)));
}

it('finds a product\'s rows by ANY part of its SKU, and by a word of its title', function () {
    $p = afProduct();
    actingAs(Staff::admin());
    ActivityLog::record('catalog_products', $p['id'], ActivityLog::UPDATED, ['selling_price' => '100.00'], ['selling_price' => '120.00'], label: 'zzz not the sku');

    $bySku = afRows('?q=SKU-77');
    expect(array_column($bySku, 'subject_id'))->toContain($p['id']);

    $title = T::str(DB::table('catalog_product_translations')->where('product_id', $p['id'])->where('locale', 'ar')->value('title'));
    $word = mb_substr($title, (int) (mb_strlen($title) / 2), 4);
    expect(array_column(afRows('?q='.rawurlencode($word)), 'subject_id'))->toContain($p['id']);

    // the row says which product, by its code, and where to open it
    $row = collect($bySku)->firstWhere('subject_id', $p['id']);
    expect(T::arr($row)['sku'] ?? null)->toBe($p['sku'])
        ->and(T::str(T::arr($row)['link'] ?? null))->toContain('/products/'.$p['id'].'/edit');
});

it('filters by KIND: a price change, a stock change, a bulk change, a sign-in', function () {
    $p = afProduct();
    actingAs(Staff::admin());
    ActivityLog::record('catalog_products', $p['id'], ActivityLog::UPDATED, ['selling_price' => '100.00'], ['selling_price' => '150.00'], label: 'kind price');
    ActivityLog::record('catalog_products', $p['id'], ActivityLog::UPDATED, ['wa_code' => 'A'], ['wa_code' => 'B'], label: 'kind other');
    ActivityLog::record('catalog_products', $p['id'], ActivityLog::ADJUSTED, ['express' => 1], ['express' => 4], label: 'kind stock');
    ActivityLog::within(ActivityLog::CONTEXT_BULK, fn () => ActivityLog::record('catalog_products', $p['id'], ActivityLog::UPDATED, ['is_active' => 1], ['is_active' => 0], label: 'kind bulk'));
    $q = '&filters[subject_id]='.$p['id'].'&filters[subject_type]=catalog_products';

    // the screen shows a product's CURRENT name, so the rows are told apart by what changed in them
    $fields = function (string $kind) use ($q): array {
        $out = [];
        foreach (afRows('?filters[kind]='.$kind.$q) as $row) {
            $out[] = implode(',', array_keys(T::arr($row['changes'] ?? null)));
        }
        sort($out);

        return $out;
    };
    $price = $fields('price');
    $stock = $fields('stock');
    $bulk = $fields('bulk');
    // the product may carry older rows of its own in the data, so each kind is checked for what it must and must not hold
    expect($price)->toContain('selling_price');
    expect($price)->not->toContain('wa_code');
    expect($stock)->toContain('express');
    expect($stock)->not->toContain('selling_price');
    expect($bulk)->toContain('is_active');
    expect($bulk)->not->toContain('selling_price');

    $bulk = collect(afRows('?filters[kind]=bulk'.$q))->first();
    expect(T::arr($bulk)['bulk'] ?? null)->toBeTrue();
});

it('records a dashboard sign-in, with the person as the actor', function () {
    $user = Staff::admin();
    UserWriteGuard::fixture(fn () => DB::table('users')->where('id', $user->id)->update([
        'password' => '$2y$10$'.substr(password_hash('night-check-secret', PASSWORD_BCRYPT, ['cost' => 10]), 7),
    ]));

    post('/manage/login', ['email' => $user->email, 'password' => 'night-check-secret'])->assertRedirect('/manage');

    $row = T::row(DB::table(ActivityLog::TABLE)->where('action', ActivityLog::LOGIN)->orderByDesc('id')->first(['user_id', 'subject_type', 'subject_id']));
    expect(T::int($row->user_id))->toBe(T::int($user->id))->and($row->subject_type)->toBe('users')
        ->and(array_column(afRows('?filters[kind]=login'), 'action'))->toContain(ActivityLog::LOGIN);
});

it('tags every row a bulk action writes', function () {
    $p = afProduct();
    $before = T::int(DB::table(ActivityLog::TABLE)->max('id'));

    actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk', ['action' => 'deactivate', 'ids' => [$p['id']]])->assertSessionHasNoErrors();

    $contexts = DB::table(ActivityLog::TABLE)->where('id', '>', $before)->where('subject_id', $p['id'])->pluck('context')->map(fn ($c) => T::str($c))->all();
    expect($contexts)->not->toBe([])->and(array_unique($contexts))->toBe([ActivityLog::CONTEXT_BULK]);
});

it('shows the product\'s whole history on its form: the price change AND the system\'s stock movement', function () {
    $p = afProduct();
    actingAs(Staff::admin());
    ActivityLog::record('catalog_products', $p['id'], ActivityLog::UPDATED, ['selling_price' => '100.00'], ['selling_price' => '175.00'], label: 'history price');
    app(InventoryService::class)->set(StockTarget::product($p['id']), 'express', 3, 'restock', actor: Actor::system(), note: 'history system move');

    $history = T::arr(Props::of(get('/manage/storefronts/1/products/'.$p['id'].'/edit'))['history'] ?? null);
    $sources = array_column($history, 'source');
    $fields = [];
    foreach ($history as $entry) {
        foreach (T::arr(T::arr($entry)['changes'] ?? null) as $change) {
            $fields[] = T::arr($change)['field'] ?? null;
        }
    }
    expect($sources)->toContain('product', 'stock')
        ->and($fields)->toContain('selling_price', 'stock_express')
        ->and(array_column($history, 'note'))->toContain('history system move');
});
