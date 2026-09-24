<?php

use App\Domain\Access\Role;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The export ROUTE refuses a data-entry user — server-side, on every screen (review 🔴-2).
 *
 * ── The decision this encodes ───────────────────────────────────────────────────────────────
 *
 * The SCREENS keep full visibility for both roles. Data-entry handle orders and telephone
 * customers, so hiding a phone number from them was the wrong fix and has been reversed. What is
 * restricted is the FILE: a screen shows one page, inside a session, on a device the shop
 * controls; a CSV is the whole filtered set, detached, forwardable and permanent.
 *
 * ── Why the test calls URLs rather than checking a flag ─────────────────────────────────────
 *
 * Because "the button is hidden" is not a control, and a review that accepted a hidden button
 * would have accepted exactly the defect this is. Every export in the application is listed below
 * and every one is requested directly, as data-entry, with the query string a curious operator
 * would type.
 */

/**
 * EVERY export the dashboard offers.
 *
 * Listed by hand on purpose: a screen that gains an export and is not added here is a screen whose
 * refusal nobody checked. The completeness assertion at the bottom is what makes the list honest.
 *
 * @return array<string, string>
 */
function everyExportUrl(): array
{
    return [
        // Paginated screens, through `TableQuery::wantsExport()`.
        'products' => '/manage/storefronts/1/products?export=csv',
        'placement' => '/manage/storefronts/1/placement?export=csv',
        'inventory' => '/manage/inventory?export=csv',
        'stock ledger' => '/manage/inventory/ledger?export=csv',
        'orders' => '/manage/orders?export=csv',
        'customers' => '/manage/customers?export=csv',
        'promotions' => '/manage/promotions?export=csv',
        'storefronts' => '/manage/storefronts?export=csv',
        'banners' => '/manage/storefronts/1/banners?export=csv',
        // Prepared-list screens, through `TableExport::wanted()`.
        'categories' => '/manage/storefronts/1/categories?export=csv',
        'lookups' => '/manage/lookups/brands?export=csv',
        'units' => '/manage/units?export=csv',
        'dashboard grants' => '/manage/users?export=csv',
        'shipping' => '/manage/shipping?export=csv',
        'activity' => '/manage/activity?export=csv',
    ];
}

it('REFUSES every export to a data-entry user, called directly by URL', function () {
    actingAs(Staff::dataEntry());

    foreach (everyExportUrl() as $screen => $url) {
        $response = get($url);

        /*
         * 403 and not "the page rendered instead". A screen that quietly ignores `?export=csv` and
         * serves HTML would look like a refusal in a browser and be no refusal at all to `curl`.
         */
        expect($response->status())->toBe(403, "[{$screen}] did not refuse the export: {$url}");
    }
});

it('refuses with a SENTENCE, not a bare status', function () {
    actingAs(Staff::dataEntry());

    // The operator is not doing anything wrong — they simply do not hold this. A blank 403 sends
    // them to ask somebody why.
    //
    // The sentence was shortened in the 2026-09-18 copy sweep: it used to open with "exporting data
    // is not one of your permissions" and then add that the screen itself was fully open, which the
    // operator could see for themselves. What is asserted here is the part that carries the fact —
    // that this needs an administrator — not the wording around it.
    //
    // Since 2026-09-19 a refusal inside `/manage` renders the dashboard's own error page (D-17),
    // so the sentence arrives as a PROP rather than as text in the HTML. Read as a prop for that
    // reason — the raw body carries it `\u`-escaped inside the Inertia payload, so a substring
    // match on the markup would fail while the operator reads the sentence perfectly well.
    $response = get('/manage/orders?export=csv');
    $response->assertForbidden();

    $props = Props::of($response);

    expect(T::str($props['body'] ?? null))->toContain('يحتاج صلاحية مدير');
});

it('keeps a specific refusal specific, instead of talking over it with a generic page', function () {
    /*
     * The trap the error page created and this catches: several refusals in this application
     * already say exactly WHY, and a page that replaced every 403 with one general sentence would
     * be a step backwards — a reason the operator can act on, swapped for one they cannot.
     *
     * So the generic body is a FALLBACK. Where a caller wrote a sentence, that sentence wins.
     */
    actingAs(Staff::dataEntry());
    $specific = T::str(Props::of(get('/manage/orders?export=csv'))['body'] ?? null);

    // …and a refusal that never had words falls back to the general explanation.
    $generic = T::str(Props::of(get('/manage/users'))['body'] ?? null);

    expect($specific)->not->toBe($generic)
        ->and($generic)->toContain('الصلاحيات');
});

it('still gives data-entry the SCREEN, with the customer data on it', function () {
    /*
     * The other half of the decision, and the one that would be easy to break while fixing the
     * first: data-entry must keep seeing everything on the order queue. They are the people who
     * ring the customer back.
     */
    actingAs(Staff::dataEntry());

    $rows = Props::rows(Props::table(get('/manage/orders')));
    expect($rows)->not->toBe([]);

    $first = $rows[0];
    expect($first)->toHaveKey('customer')
        ->and($first)->toHaveKey('phone');
});

it('ALLOWS every export to an administrator, or the refusals prove nothing', function () {
    actingAs(Staff::admin());

    foreach (everyExportUrl() as $screen => $url) {
        // `assertOk()` rather than `->status()`: a successful export is a StreamedResponse, which
        // carries no `status()` helper of its own.
        $response = get($url)->assertOk();

        // `toContain()` is VARIADIC — a second argument is another needle, not a message. Passing
        // one here made the assertion search for its own failure text (the project's own note).
        expect(T::str($response->headers->get('content-disposition')))
            ->toContain('.csv');
    }
});

it('hides the button from data-entry and shows it to an administrator', function () {
    // `?? null` on a mixed array value is still mixed, so the flag is COERCED rather than
    // asserted-by-annotation: a screen that stopped sending `exportable` must read as "not
    // exportable" and fail the admin case, not slip through as a truthy string.
    $exportable = function (string $url): ?bool {
        $meta = T::arr(Props::table(get($url))['meta'] ?? null);

        return array_key_exists('exportable', $meta) ? (bool) $meta['exportable'] : null;
    };

    actingAs(Staff::dataEntry());
    expect($exportable('/manage/orders'))->toBeFalse();

    actingAs(Staff::admin());
    expect($exportable('/manage/orders'))->toBeTrue();
});

it('names EXPORT_DATA as an admin ability and withholds it from data-entry', function () {
    expect(Role::ABILITIES)->toContain(Role::EXPORT_DATA)
        ->and(Role::DataEntry->abilities())->not->toContain(Role::EXPORT_DATA)
        ->and(Role::Admin->abilities())->toContain(Role::EXPORT_DATA);
});

it('lists EVERY export route, so a new one cannot skip this file', function () {
    /*
     * The completeness check. `exportable()` is declared per screen in a controller, so the way a
     * new export appears is a new `->exportable(...)` call — this counts them and compares against
     * the list above.
     */
    $controllers = glob(app_path('Http/Controllers/Manage/*.php')) ?: [];
    $declared = 0;
    foreach ($controllers as $file) {
        $declared += substr_count(T::str(file_get_contents($file)), '->exportable(');
        $declared += substr_count(T::str(file_get_contents($file)), 'TableExport::wanted(');
    }

    expect($declared)->toBe(count(everyExportUrl()),
        'an export was added or removed without updating everyExportUrl() — every one must be proved to refuse');
});
