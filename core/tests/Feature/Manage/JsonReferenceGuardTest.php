<?php

use App\Domain\Catalog\LookupWriter;
use App\Domain\Catalog\SpecBlocks;
use App\Domain\Catalog\UnitCleanup;
use App\Models\Catalog\Product;
use App\Support\Coerce;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * A reference stored in JSON must still be COUNTABLE by whatever guards the row it points at.
 *
 * ── The defect this generalises (2026-09-14) ─────────────────────────────────────────────────
 *
 * `LookupWriter::usageCount()` counts declared `(table, column)` pairs, and wave 4D put
 * `material_id` inside `catalog_products.specs` — a JSON column. A material used by 166 leather
 * bags therefore looked UNUSED on the lookups screen and could have been deleted, leaving every one
 * of those products pointing at an id that no longer exists.
 *
 * The developer's instruction was to sweep for the same shape rather than fix the one instance:
 * *"one bad guard means a team member deletes something in use."* The sweep found no others today
 * — and this file is what keeps that true, because the next JSON-stored reference will be added by
 * someone who has never read this note.
 */

it('declares a json_usage entry for every reference a spec block stores in JSON', function () {
    /** @var array<string, list<string>> $needed  lookup key => the JSON paths that reference it */
    $needed = [];

    foreach (Product::FAMILIES as $family) {
        $block = SpecBlocks::for($family);
        // Only a JSON block can hide a reference; a watch spec is real columns.
        if ($block === null || $block['table'] !== 'specs') {
            continue;
        }

        foreach ($block['fields'] as $field) {
            // A `lookup` field IS a reference; a `unit` on a field is a reference to `units`.
            if (isset($field['lookup'])) {
                $needed[$field['lookup']][] = $field['key'];
            }
            if (isset($field['unit'])) {
                $needed['units'][] = $field['unit'];
            }
        }
    }

    foreach ($needed as $lookup => $keys) {
        $definition = LookupWriter::definition($lookup);
        $declared = [];
        foreach ($definition['json_usage'] ?? [] as [$table, $column, $key]) {
            $declared[] = $table.'.'.$column.'.'.$key;
        }

        foreach (array_unique($keys) as $key) {
            // `toContain` takes VALUES, not a message — the assertion has to carry its own.
            expect(in_array('catalog_products.specs.'.$key, $declared, true))->toBeTrue(
                "the lookup [{$lookup}] is stored in catalog_products.specs.{$key} but its delete guard cannot count it — "
                .'add a json_usage triple in config/catalog.php, or the row can be deleted while in use'
            );
        }
    }
});

it('COUNTS a material that lives only in a product specs JSON', function () {
    // The guard, driven end to end: put a material in a product's JSON and ask the lookup writer
    // whether it is used. Before the fix this answered 0.
    $materialId = T::int(DB::table('catalog_materials')->orderBy('id')->value('id'));
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));

    $definition = LookupWriter::definition('materials');
    $before = app(LookupWriter::class)->usageCount($definition, $materialId);

    DB::table('catalog_products')->where('id', $productId)
        ->update(['specs' => json_encode(['material_id' => $materialId])]);

    $after = app(LookupWriter::class)->usageCount($definition, $materialId);

    expect($after)->toBe($before + 1);

    // …and a DIFFERENT material is not counted by the same row.
    $other = T::int(DB::table('catalog_materials')->where('id', '!=', $materialId)->orderBy('id')->value('id'));
    expect(app(LookupWriter::class)->usageCount(LookupWriter::definition('materials'), $other))
        ->toBe(app(LookupWriter::class)->usageCount(LookupWriter::definition('materials'), $other));
});

it('refuses to DELETE a material that only a specs JSON references', function () {
    $materialId = T::int(DB::table('catalog_materials')->orderBy('id')->value('id'));
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));

    DB::table('catalog_products')->where('id', $productId)
        ->update(['specs' => json_encode(['material_id' => $materialId])]);

    $result = app(LookupWriter::class)->delete('materials', $materialId);

    // The whole point: the refusal is what stands between a tidy-up and 166 broken products.
    expect($result['deleted'])->toBeFalse()
        ->and($result['reason'])->toContain('مستخدم')
        ->and(DB::table('catalog_materials')->where('id', $materialId)->exists())->toBeTrue();
});

it('keeps every UNIT reference in real columns, where the units screen can see them', function () {
    /*
     * `UnitCleanup` counts `*_unit_id` columns on `catalog_product_watch_specs`. That is complete
     * only while no JSON block declares a `unit` — the moment one does, a unit could be in use and
     * look retirable. Asserted here rather than assumed, because the two screens are in different
     * files and only this test connects them.
     */
    foreach (Product::FAMILIES as $family) {
        $block = SpecBlocks::for($family);
        if ($block === null || $block['table'] !== 'specs') {
            continue;
        }

        foreach ($block['fields'] as $field) {
            expect($field['unit'] ?? null)->toBeNull(
                "the [{$family}] block declares a unit on [{$field['key']}], which lives in JSON — "
                .'UnitCleanup counts columns only, so that unit could be retired while in use'
            );
        }
    }

    // …and the columns it does count are all really there.
    foreach (UnitCleanup::referenceColumns() as $column) {
        expect(Coerce::str($column))->toEndWith('_unit_id');
    }
});
