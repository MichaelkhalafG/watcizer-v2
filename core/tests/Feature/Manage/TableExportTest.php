<?php

use App\Support\Table\TableExport;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * The dashboard's CSV export (wave 4D).
 *
 * ── The one claim worth testing ──────────────────────────────────────────────────────────────
 *
 * "The file is the view." Not the table — the VIEW: the operator's filters, their search, their
 * sort. An export that quietly returned the whole table would answer a question nobody asked, and
 * would be the single most likely place for a column somebody's role cannot see to escape.
 *
 * So the assertions are about correspondence, not formatting: the same filter that changes the
 * screen changes the file, by the same number of rows.
 */
/** The body of a streamed response — it is not built until something reads it. */
function csv(string $url): string
{
    $response = get($url);
    $response->assertOk();

    return T::str($response->streamedContent());
}

/** @return list<string> non-empty lines, BOM removed */
function csvLines(string $body): array
{
    $lines = [];
    foreach (explode("\n", str_replace(["\xEF\xBB\xBF", "\r"], '', $body)) as $line) {
        if (trim($line) !== '') {
            $lines[] = $line;
        }
    }

    return $lines;
}

beforeEach(fn () => actingAs(Staff::admin()));

it('exports the CURRENT VIEW, not the whole table', function () {
    $base = '/manage/storefronts/1/products';

    /*
     * The filter is DERIVED from the data, not written into the test. This catalogue is rebuilt
     * from the legacy app and re-imported; a hard-coded family (or a "no Arabic" flag that a
     * translation pass has since emptied) would turn a real regression into a test nobody trusts.
     * Take the biggest family that is not the whole set, and the premise holds on any rebuild.
     */
    $family = T::str(DB::table('catalog_products')
        ->whereNull('deleted_at')
        ->groupBy('family')
        ->orderByRaw('COUNT(*) DESC')
        ->value('family'));

    $all = T::int(T::arr(Props::table(get($base))['meta'] ?? null)['total'] ?? null);
    $filtered = T::int(T::arr(Props::table(get($base."?filters[p.family]={$family}"))['meta'] ?? null)['total'] ?? null);

    expect($filtered)->toBeLessThan($all)->and($filtered)->toBeGreaterThan(0);

    $rows = count(csvLines(csv($base."?filters[p.family]={$family}&export=csv"))) - 1; // minus the header

    expect($rows)->toBe($filtered);
});

it('starts with a UTF-8 BOM, or Excel renders Arabic as mojibake', function () {
    $body = csv('/manage/storefronts/1/products?export=csv&per_page=5');

    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF")
        // …and the header row is the Arabic the screen shows, not a column name.
        ->and($body)->toContain('الكود');
});

it('puts the row count in the FILENAME, so nobody has to open the file to see it', function () {
    $response = get('/manage/storefronts/1/products?filters[flag]=no_arabic&export=csv');
    $disposition = T::str($response->headers->get('content-disposition'));
    $total = T::int(T::arr(Props::table(get('/manage/storefronts/1/products?filters[flag]=no_arabic'))['meta'] ?? null)['total'] ?? null);

    expect($disposition)->toContain("-{$total}-rows.csv")
        ->and(T::str($response->headers->get('content-type')))->toContain('text/csv')
        ->and($disposition)->toStartWith('attachment;');

    // A banner line above the headers would stop Excel treating row 1 as the header row, which is
    // why the count lives in the name instead.
    expect(csvLines(T::str($response->streamedContent()))[0])->toStartWith('الكود,');
});

it('ignores per_page: an export is the whole filtered set, not one page', function () {
    $page = csvLines(csv('/manage/storefronts/1/products?export=csv&per_page=5'));
    $total = T::int(T::arr(Props::table(get('/manage/storefronts/1/products'))['meta'] ?? null)['total'] ?? null);

    expect(count($page) - 1)->toBe($total);
});

it('carries no cost price, because the products LIST never showed one', function () {
    $body = csv('/manage/storefronts/1/products?export=csv&per_page=5');
    $header = csvLines($body)[0];

    // The file is picked out of the row the screen renders, so this is structural, not a rule
    // somebody has to remember: `purchase_price` is not in the list payload at all.
    expect($header)->not->toContain('التكلفة')
        ->and($header)->not->toContain('purchase');
});

it('defuses a cell a spreadsheet would EXECUTE', function () {
    // A supplier sheet is exactly where `=HYPERLINK(...)` comes from, and our titles come from one.
    $columns = [['key' => 'title', 'label' => 'الاسم', 'value' => null]];
    $rows = [['title' => '=HYPERLINK("http://evil","click")'], ['title' => '+1+1'], ['title' => 'ساعة']];

    $response = TableExport::respond('probe', 3, $columns, $rows);
    ob_start();
    $response->sendContent();
    $body = T::str(ob_get_clean());

    expect($body)->toContain("'=HYPERLINK")
        ->and($body)->toContain("'+1+1")
        // …and an ordinary value is untouched.
        ->and($body)->toContain('ساعة')
        ->and($body)->not->toContain("'ساعة");
});

it('refuses the export to anyone the SCREEN refuses — there is no second route', function () {
    // `?export=csv` is answered by the index action, so the gate is the index's gate.
    actingAs(Staff::customer());

    get('/manage/storefronts/1/products?export=csv')->assertForbidden();
});

it('offers the SAME export on every table-driven screen', function () {
    /*
     * One mechanism, eleven screens. The point of walking them here is that a new screen which
     * forgets `exportable()` shows up as a missing button rather than as nothing at all — and that
     * the shape of the file (BOM, header row, filename) is identical everywhere, because it is
     * written in exactly one place.
     */
    $screens = [
        'المنتجات' => '/manage/storefronts/1/products',
        'العرض والترتيب' => '/manage/storefronts/1/placement',
        'المخزون' => '/manage/inventory',
        'سجل الحركات' => '/manage/inventory/ledger',
        'الطلبات' => '/manage/orders',
        'العروض الترويجية' => '/manage/promotions',
        'المتاجر' => '/manage/storefronts',
        'البانرات' => '/manage/storefronts/1/banners',
    ];

    foreach ($screens as $label => $url) {
        // The screen advertises it…
        $meta = T::arr(Props::table(get($url))['meta'] ?? null);
        expect($meta['exportable'] ?? null)->toBeTrue("{$label} offers no export");

        // …and the file is there, with the shape every other one has.
        $response = get($url.'?export=csv');
        $response->assertOk();
        $body = T::str($response->streamedContent());

        expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF", "{$label} has no BOM")
            ->and(T::str($response->headers->get('content-disposition')))
            ->toContain('-'.T::int($meta['total'] ?? null).'-rows.csv');

        // The header row is the FIRST row: no banner line above it.
        $lines = csvLines($body);
        expect($lines[0] ?? '')->not->toBe('', "{$label} exported no header");
        expect(count($lines) - 1)->toBe(T::int($meta['total'] ?? null), "{$label} exported the wrong number of rows");
    }
});

it('gives the SAME file to the four screens that are not on a paginated table', function () {
    /*
     * The category tree, the lookup lists, the unit cleanup and the dashboard grants ship a
     * prepared list rather than a query. Forcing them onto pagination to gain an export would be
     * the tail wagging the dog, so they call the same WRITER instead — and this asserts that the
     * file they produce is indistinguishable from the paginated ones.
     */
    foreach ([
        'التصنيفات' => '/manage/storefronts/1/categories',
        'الماركات' => '/manage/lookups/brands',
        'وحدات القياس' => '/manage/units',
        'الصلاحيات' => '/manage/users',
    ] as $label => $url) {
        $response = get($url.'?export=csv');
        $response->assertOk();
        $body = T::str($response->streamedContent());

        expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF", "{$label} has no BOM")
            ->and(T::str($response->headers->get('content-disposition')))->toContain('-rows.csv')
            ->and(count(csvLines($body)))->toBeGreaterThan(1, "{$label} exported no rows");
    }
});

it('exports the dashboard GRANTS and never the customer account search', function () {
    $body = csv('/manage/users?export=csv');

    // `users` holds real customers; the screen refuses to page through them and the file does too.
    expect(str_getcsv(csvLines($body)[0]))->toBe(['البريد', 'الاسم', 'الصلاحية', 'النطاق', 'منحها', 'التاريخ', 'النوع في النظام القديم'])
        ->and($body)->not->toContain('password')
        ->and($body)->not->toContain('remember_token');
});
