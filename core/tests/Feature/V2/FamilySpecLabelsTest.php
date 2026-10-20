<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * FAMILY SPEC LABELS (2026-10-23).
 *
 * `catalog_products.specs` holds what a non-watch product has instead of watch specs — a wallet's dimensions
 * and card slots, a perfume's volume, a power bank's battery. v2 labelled every one of those rows
 * `ucfirst(str_replace('_', ' ', $key))` in BOTH locales and passed the stored value straight through, so a
 * wallet read "Wallet card slots — 8" and "Coin pocket — 1", and an Arabic page got English labels. The
 * storefront had the curated labels privately, in one component's map, which is why nobody noticed.
 *
 * Found by diffing v2 against what the product page already does on compat, BEFORE moving the page there.
 * These tests pin the labels where the fields are.
 */

/**
 * The slug of a visible product whose `catalog_products.specs` holds one of the declared keys.
 *
 * @return array{slug: string, specs: array<array-key, mixed>}|null
 */
function familySpecProduct(): ?array
{
    $row = DB::table('catalog_products as p')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'p.id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)
        ->whereNull('p.deleted_at')->where('p.is_active', 1)
        ->whereNotNull('p.specs')
        ->select(['sp.slug', 'p.specs'])
        ->first();
    if ($row === null) {
        return null;
    }
    $specs = json_decode(T::str($row->specs ?? null), true);

    return ['slug' => T::str($row->slug ?? null), 'specs' => is_array($specs) ? $specs : []];
}

/**
 * The served spec rows for a slug, keyed by their `key`.
 *
 * @return array<string, array<array-key, mixed>>
 */
function servedSpecRows(string $slug): array
{
    $rows = T::arr(getJson(H::base('products/'.$slug))->assertOk()->json('product.specs'));
    $out = [];
    foreach ($rows as $row) {
        $r = T::arr($row);
        $out[T::str($r['key'] ?? '')] = $r;
    }

    return $out;
}

it('labels a declared family spec in BOTH languages, never the humanized key in Arabic', function () {
    $product = familySpecProduct();
    if ($product === null) {
        expect(true)->toBeTrue('no product on this storefront carries family specs');

        return;
    }
    $served = servedSpecRows($product['slug']);
    expect($served)->not->toBeEmpty();

    $checked = 0;
    foreach ($served as $key => $row) {
        $label = T::arr(T::arr($row)['label'] ?? []);
        $en = T::str($label['en'] ?? '');
        $ar = T::str($label['ar'] ?? '');
        expect($en)->not->toBe('')->and($ar)->not->toBe('');

        // the old behaviour: both locales got the same humanized key. A DECLARED key must not look like that.
        $humanized = ucfirst(str_replace('_', ' ', (string) $key));
        if ($en !== $humanized) {
            // it is a declared key, so its Arabic must be Arabic — at least one Arabic code point
            expect(preg_match('/\p{Arabic}/u', $ar))->toBe(1, "spec {$key} has a non-Arabic Arabic label: {$ar}");
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThan(0, 'no declared family spec was served, so this proved nothing');
});

it('renders a flag as a word in both languages rather than as 1 or 0', function () {
    // every declared flag key, so the test keeps up with the map rather than naming one product
    $flags = ['coin_pocket', 'rfid_protection', 'waterproof', 'laptop_compartment'];
    $rows = DB::table('catalog_products as p')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'p.id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)
        ->whereNull('p.deleted_at')->where('p.is_active', 1)
        ->whereNotNull('p.specs')
        ->select(['sp.slug', 'p.specs'])
        ->limit(40)->get();

    $seen = 0;
    foreach ($rows as $row) {
        $specs = json_decode(T::str($row->specs ?? null), true);
        if (! is_array($specs) || array_intersect(array_keys($specs), $flags) === []) {
            continue;
        }
        $served = servedSpecRows(T::str($row->slug ?? null));
        foreach ($flags as $flag) {
            if (! isset($served[$flag])) {
                continue;
            }
            $value = T::arr($served[$flag])['value'] ?? null;
            // a word per locale, not a number and not a bare string
            expect($value)->toBeArray("flag {$flag} is not localized")
                ->and(T::str(T::arr($value)['en'] ?? null))->toBeIn(['Yes', 'No'])
                ->and(T::str(T::arr($value)['ar'] ?? null))->toBeIn(['نعم', 'لا']);
            $seen++;
        }
        if ($seen > 0) {
            break;
        }
    }
    expect($seen)->toBeGreaterThan(0, 'no product carried a declared flag, so this proved nothing');
});

it('carries a unit on a dimension, so the value is not a bare number', function () {
    $rows = DB::table('catalog_products as p')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'p.id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)
        ->whereNull('p.deleted_at')->where('p.is_active', 1)
        ->whereNotNull('p.specs')
        ->select(['sp.slug', 'p.specs'])
        ->limit(40)->get();

    foreach ($rows as $row) {
        $specs = json_decode(T::str($row->specs ?? null), true);
        if (! is_array($specs) || ! array_key_exists('width_cm', $specs)) {
            continue;
        }
        $served = servedSpecRows(T::str($row->slug ?? null));
        expect($served)->toHaveKey('width_cm');
        $unit = T::arr($served['width_cm'])['unit'] ?? null;
        expect($unit)->toBeArray('width_cm lost its unit')
            ->and(T::str(T::arr($unit)['code'] ?? null))->toBe('cm');

        return;
    }
    expect(true)->toBeTrue('no product on this storefront carries width_cm');
});

it('still serves an UNDECLARED key readably, so a new dashboard field appears instead of vanishing', function () {
    // the fallback is what makes the map safe to be incomplete; asserted on the rule, not on a row
    $product = familySpecProduct();
    if ($product === null) {
        expect(true)->toBeTrue('no product on this storefront carries family specs');

        return;
    }
    $served = servedSpecRows($product['slug']);
    // collected, not asserted one by one: toHaveKey's second argument is the expected VALUE, not a message
    $missing = [];
    foreach (array_keys($product['specs']) as $key) {
        $value = $product['specs'][$key];
        if ($value === null || $value === '' || is_array($value)) {
            continue;
        }
        if (! array_key_exists((string) $key, $served)) {
            $missing[] = (string) $key;
        }
    }
    expect($missing)->toBe([], 'stored specs that v2 does not serve at all: '.implode(', ', $missing));
});

it('serves a WATCH flag as a word too, not as a raw boolean', function () {
    // interchangeable_dial / interchangeable_strap / watch_box come from catalog_product_watch_specs, through
    // a different builder than the family specs — they had the same defect and must not regress separately.
    $flags = ['interchangeable_dial', 'interchangeable_strap', 'watch_box'];
    $row = DB::table('catalog_product_watch_specs as ws')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'ws.product_id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)
        ->where(function (Builder $q) use ($flags): void {
            foreach ($flags as $f) {
                $q->orWhereNotNull('ws.'.$f);
            }
        })
        ->select(['sp.slug'])->first();
    if ($row === null) {
        expect(true)->toBeTrue('no visible product carries a watch flag');

        return;
    }
    $served = servedSpecRows(T::str($row->slug ?? null));
    $seen = 0;
    foreach ($flags as $flag) {
        if (! isset($served[$flag])) {
            continue;
        }
        $value = T::arr($served[$flag])['value'] ?? null;
        expect($value)->toBeArray("watch flag {$flag} is not localized")
            ->and(T::str(T::arr($value)['en'] ?? null))->toBeIn(['Yes', 'No'])
            ->and(T::str(T::arr($value)['ar'] ?? null))->toBeIn(['نعم', 'لا']);
        $seen++;
    }
    expect($seen)->toBeGreaterThan(0, 'the chosen product served no watch flag, so this proved nothing');
});
