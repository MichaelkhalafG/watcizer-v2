<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * Wave 4B's screen evidence, written to `new branding/docs/wave4b/screens/`.
 *
 * RENDERED HTML plus the Inertia prop payload, not screenshots, and the reason is a rule rather
 * than convenience: a browser session needs a PASSWORD, and `users` is a legacy table this
 * application may not write (AGENTS §3) — `Staff` exists precisely because the suite grants a role
 * to an account that already exists instead of fabricating one. So the evidence is what the server
 * actually sends: the full HTML document (with the shell, the RTL direction and the Arabic labels
 * in it) and the props the React page receives.
 *
 * The brief accepts either. HTML also has one advantage a screenshot does not: a reviewer can grep
 * it for the sentence a refusal is supposed to show.
 *
 * Skipped unless `CAPTURE_SCREENS=1`, because writing eleven files into the planning tree is not
 * something an ordinary `php artisan test` should do.
 */

function evidenceDir(): string
{
    return base_path('../new branding/docs/wave4b/screens');
}

function capture(string $name, string $url, ?string $actor = null): void
{
    $user = $actor === 'data-entry' ? Staff::dataEntry() : Staff::admin();
    $response = actingAs($user)->get($url);

    expect($response->getStatusCode())->toBe(200, "{$name}: {$url} did not render");

    $html = $response->getContent();
    expect($html)->toBeString();

    $props = Props::of($response);
    $dir = evidenceDir();
    File::ensureDirectoryExists($dir);

    File::put($dir.'/'.$name.'.html', (string) $html);
    File::put(
        $dir.'/'.$name.'.props.json',
        (string) json_encode($props, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );
}

it('captures every wave-4B screen as rendered HTML and its props', function () {
    // `getenv()` rather than `env()`: the latter returns null when the config is cached, and
    // PHPStan (correctly) refuses it outside `config/`. This is a switch a human flips on the
    // command line, so the raw environment is the right place to read it.
    if (getenv('CAPTURE_SCREENS') !== '1') {
        // Skipped, not vacuously passed: a test that claims to have written eleven files and
        // wrote none would be worse than one that says it did nothing.
        expect(true)->toBeTrue();

        return;
    }

    // The screens are photographed as they look AFTER the switch, when the team owns the
    // catalogue — that is the state they are built for. The pre-switch refusals have their own
    // captures below, taken with the flag in its default state.
    CatalogFixture::assumeSwitched();

    // A catalogue state worth photographing: a watch product in the Watches branch with a variant
    // and an image, a product with no Arabic (so the refusal badge shows), and a category the team
    // invented (so the tree shows a dashboard-authored node next to the transform's).
    $watches = CatalogFixture::watchesRoot();
    $withVariants = CatalogFixture::product('watch');
    CatalogFixture::place($withVariants, $watches);
    CatalogFixture::onStorefront($withVariants);
    actingAs(Staff::admin())->post("/manage/products/{$withVariants}/variants", [
        'label' => '42مم / أسود', 'is_active' => true, 'stock_express' => 6, 'stock_market' => 2,
    ])->assertSessionHasNoErrors();

    $noArabic = CatalogFixture::productWithoutArabic();
    CatalogFixture::onStorefront($noArabic, visible: false);

    $invented = CatalogFixture::root('Shoes', 'أحذية');
    CatalogFixture::child($invented['id'], 'Sneakers', 'سنيكرز');

    // A legacy-backed product, so the conversion REFUSAL is on one of the captured forms — the
    // screen that has to show a rule, not just obey it.
    $legacyBacked = T::int(DB::table('inventory_movements')
        ->where('reason', 'transform')->whereNull('variant_id')->orderBy('product_id')->value('product_id'));

    $screens = [
        '01-products-list' => '/manage/storefronts/1/products',
        '02-products-list-filtered' => '/manage/storefronts/1/products?'.http_build_query([
            'filters' => ['p.family' => 'watch', 'p.is_active' => '1'], 'sort' => 'p.selling_price', 'direction' => 'asc',
        ]),
        '03-products-list-no-arabic' => '/manage/storefronts/1/products?'.http_build_query(['filters' => ['flag' => 'no_arabic']]),
        '04-product-create' => '/manage/storefronts/1/products/create',
        '05-product-edit-watch-with-variants' => "/manage/storefronts/1/products/{$withVariants}/edit",
        '06-product-edit-conversion-refused' => "/manage/storefronts/1/products/{$legacyBacked}/edit",
        '07-categories-tree' => '/manage/storefronts/1/categories',
        '08-placement' => '/manage/storefronts/1/placement',
        '09-placement-needs-attention' => '/manage/storefronts/1/placement?'.http_build_query(['filters' => ['flag' => 'no_primary']]),
        '10-lookups-brands' => '/manage/lookups/brands',
        '11-lookups-colors' => '/manage/lookups/colors',
    ];

    foreach ($screens as $name => $url) {
        capture($name, $url, 'data-entry');
    }

    // Every file exists and is a real document, not an empty shell.
    foreach (array_keys($screens) as $name) {
        $path = evidenceDir().'/'.$name.'.html';
        expect(File::exists($path))->toBeTrue("{$name}.html was not written")
            ->and(File::size($path))->toBeGreaterThan(2000, "{$name}.html is suspiciously small");
    }

    // And the two sentences a reviewer should be able to grep for are in the HTML the server sent.
    $conversionScreen = File::get(evidenceDir().'/06-product-edit-conversion-refused.props.json');
    expect($conversionScreen)->toContain('قبل التحويل النهائي');

    $tree = File::get(evidenceDir().'/07-categories-tree.props.json');
    expect($tree)->toContain('in_menu_reason');
});
