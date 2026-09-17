<?php

use App\Domain\Catalog\LookupWriter;
use App\Domain\Catalog\SpecBlocks;
use App\Support\ManageText;
use Illuminate\Support\Facades\Lang;
use Tests\Support\T;

/*
 * The labels `config/catalog.php` declares really are translated (🟠-4, 2026-09-17).
 *
 * ── Why this file is the important half ──────────────────────────────────────────────────────
 *
 * `config/catalog.php` carries an `i18n-exempt-file` marker, so the Arabic ratchet skips it. That
 * marker is only honest because of the assertions below. Without them it would be a mute switch on
 * 589 Arabic characters — the exact shape the required-reason rule exists to prevent.
 *
 * The config cannot call the seam itself: `config:cache` resolves the file once and freezes the
 * result in whatever locale built the cache. So the Arabic there is a FALLBACK and the CONSUMER
 * translates — `SpecBlocks::for()` and `LookupWriter::all()`. These tests check both ends: that
 * every declared label has an English entry, and that asking in English really returns it.
 */

/**
 * Every translation key the config-driven labels ask for, derived from the config itself.
 *
 * Derived rather than listed, so a field added to `config/catalog.php` is covered the moment it is
 * added — which is the only version of this worth having. A hand-maintained list would pass on the
 * day somebody adds a field and forgets it, which is the day it matters.
 *
 * @return array<string, string> key => the Arabic fallback declared in config
 */
function configLabelKeys(): array
{
    $catalog = T::arr(config('catalog'));
    $out = [];

    foreach (T::arr($catalog['blocks'] ?? []) as $family => $block) {
        $block = T::arr($block);
        if (is_string($block['label'] ?? null)) {
            $out['specs.block_'.$family] = $block['label'];
        }
        foreach (T::arr($block['fields'] ?? []) as $field) {
            $field = T::arr($field);
            if (is_string($field['key'] ?? null) && is_string($field['label'] ?? null)) {
                $out['specs.field_'.$field['key']] = $field['label'];
            }
        }
    }

    foreach (T::arr($catalog['lookups'] ?? []) as $key => $entry) {
        $entry = T::arr($entry);
        if (is_string($entry['label'] ?? null)) {
            $out['lookups.list_'.$key] = $entry['label'];
        }
        foreach (T::arr($entry['extra'] ?? []) as $column => $declared) {
            $declared = T::arr($declared);
            if (is_string($declared['label'] ?? null)) {
                $out['lookups.column_'.$key.'_'.$column] = $declared['label'];
            }
        }
    }

    return $out;
}

it('declares at least the labels this catalogue is known to have', function () {
    /*
     * A floor, so the derivation cannot quietly return nothing and make every test below vacuous —
     * the failure mode of a test that builds its own subject. 58 keys at the time of writing.
     */
    expect(count(configLabelKeys()))->toBeGreaterThanOrEqual(50);
});

it('has an English entry for every label config/catalog.php declares', function () {
    $missing = [];

    foreach (configLabelKeys() as $key => $arabic) {
        $english = Lang::get('manage.'.$key, [], 'en', false);

        if (! is_string($english) || $english === 'manage.'.$key) {
            $missing[$key] = $arabic;
        }
    }

    expect($missing)->toBe(
        [],
        "These labels are declared in config/catalog.php and have no English entry, so an English\n"
        ."operator reads the specifications panel and the reference lists in Arabic.\n\n"
        .'Add each to lang/en/manage.php. The key is derived from the config’s own stable `key`.'
    );
});

it('really resolves them — the consumers call the seam, not just the config', function () {
    /*
     * The half a key-coverage check cannot see. An English entry that nothing asks for changes
     * nothing on screen, so this drives the actual consumers with the locale set and asserts the
     * ENGLISH comes back.
     *
     * `SpecBlocks::for('watch')` and `LookupWriter::all()` are the two readers named in the config's
     * `i18n-exempt-file` marker; if either stopped translating, this fails and the marker stops
     * being true.
     */
    $original = app()->getLocale();

    try {
        app()->setLocale('en');

        $watch = SpecBlocks::for('watch');
        expect($watch)->not->toBeNull();

        $block = T::arr($watch);
        expect(T::str($block['label'] ?? ''))->toBe('Watch specifications');

        $labels = [];
        foreach (T::arr($block['fields'] ?? []) as $field) {
            $field = T::arr($field);
            $labels[T::str($field['key'] ?? '')] = T::str($field['label'] ?? '');
        }

        expect($labels['case_size'] ?? null)->toBe('Case size')
            ->and($labels['water_resistance'] ?? null)->toBe('Water resistance')
            ->and($labels['band_material_id'] ?? null)->toBe('Band material');

        $brands = T::arr(LookupWriter::all()['brands'] ?? []);
        expect(T::str($brands['label'] ?? ''))->toBe('Brands')
            ->and(T::str(T::arr(T::arr($brands['extra'] ?? [])['logo_path'] ?? [])['label'] ?? ''))->toBe('Logo');

        // …and in ARABIC the fallback comes back, with lang/ar still an empty stub.
        app()->setLocale('ar');
        $arabicBlock = T::arr(SpecBlocks::for('watch'));
        expect(T::str($arabicBlock['label'] ?? ''))->toBe('مواصفات الساعة');
    } finally {
        app()->setLocale($original);
    }
});

it('keeps the config’s exemption marker honest by naming its consumers', function () {
    /*
     * The marker earns the ratchet's silence by saying WHO translates the file's contents. If
     * somebody removes a consumer's name without removing the label it covers, the assertions above
     * still pass while the marker has become a lie — so the names themselves are pinned.
     */
    $source = T::str(file_get_contents(config_path('catalog.php')));

    expect($source)->toContain('i18n-exempt-file:')
        ->and($source)->toContain('SpecBlocks')
        ->and($source)->toContain('LookupWriter')
        // …and it says why the seam cannot simply be called here.
        ->and($source)->toContain('config:cache');
});

it('translates through ManageText, so the keys behave like every other string', function () {
    // A spot check that the derived key namespace is the real one and not a parallel scheme.
    $original = app()->getLocale();

    try {
        app()->setLocale('en');
        expect(ManageText::t('specs.field_case_size', 'قياس العلبة'))->toBe('Case size');

        app()->setLocale('ar');
        expect(ManageText::t('specs.field_case_size', 'قياس العلبة'))->toBe('قياس العلبة');
    } finally {
        app()->setLocale($original);
    }
});
