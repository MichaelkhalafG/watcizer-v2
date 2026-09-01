<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CategoryType;
use App\Models\ClosureType;
use App\Models\Color;
use App\Models\DisplayType;
use App\Models\Feature;
use App\Models\Gender;
use App\Models\Grade;
use App\Models\Material;
use App\Models\MovementType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shape;
use App\Models\SizeType;
use App\Models\SubType;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\SizeTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * HTTP-level regression tests for the product create/edit flow. These drive the
 * ACTUAL controller (store/update) + ProductRequest validation through the admin
 * routes — the layer where the "fields vanish on save" bugs lived.
 *
 * NOTE on the duplicate-name form bug (Bug 1): that was a browser-DOM issue (many
 * hidden category blocks posting the same field name, PHP keeping the empty last
 * one). The server fix is disabling hidden inputs in create.blade.php so only the
 * visible block's value is sent. At the HTTP layer a form field has ONE value per
 * key, so `test_store_persists_all_watch_fields` sends exactly what the FIXED form
 * emits and asserts every field lands — that is the server-side regression guard.
 * `test_empty_value_overwrites_document_why_client_fix_is_required` characterises
 * the pre-fix behaviour (an empty value DOES null the column) to show the fix had
 * to be client-side.
 */
class ProductCrudFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<string,int> */
    private array $ref = [];

    /** Files ImageService writes to public/ during tests — cleaned up after. */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([SizeTypeSeeder::class, MasterDataSeeder::class]);

        // `type` is NOT in User::$fillable, so it must be set explicitly (mass
        // assignment would silently drop it, leaving the user a non-admin and the
        // admin middleware would bounce every request to '/').
        $this->admin = User::create([
            'first_name' => 'Test',
            'last_name'  => 'Admin',
            'email'      => 'audit-admin@example.com',
            'password'   => bcrypt('password'),
        ]);
        $this->admin->type = 'SuperAdmin';
        $this->admin->save();

        // First available id from each lookup (two where a distinct "changed"
        // value is needed for the update test — resolved inline below).
        $this->ref = [
            'category_type' => CategoryType::value('id'),
            'brand'         => Brand::value('id'),
            'grade'         => Grade::value('id'),
            'sub_type'      => SubType::value('id'),
            'closure'       => ClosureType::value('id'),
            'display'       => DisplayType::value('id'),
            'size'          => SizeType::value('id'),
            'shape'         => Shape::value('id'),
            'material'      => Material::value('id'),
            'movement'      => MovementType::value('id'),
            'gender'        => Gender::value('id'),
            'feature'       => Feature::value('id'),
            'color'         => Color::value('id'),
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    // ── Payload builders ─────────────────────────────────────────────────────

    private function watchPayload(array $overrides = []): array
    {
        $s = $this->ref['size'];

        return array_merge([
            'product_title'                 => ['en' => 'Audit Watch', 'ar' => 'ساعة اختبار'],
            'short_description'             => ['en' => 'Short EN', 'ar' => 'وصف قصير'],
            'long_description'              => ['en' => 'Long EN', 'ar' => 'وصف طويل'],
            'category_type_id'              => $this->ref['category_type'],
            'brand_id'                      => $this->ref['brand'],
            'grade_id'                      => $this->ref['grade'],
            'sub_type_id'                   => $this->ref['sub_type'],
            'gender_id'                     => [$this->ref['gender']],
            'feature_id'                    => [$this->ref['feature']],
            'dial_color_id'                 => [$this->ref['color']],
            'band_color_id'                 => [$this->ref['color']],
            'purchase_price'                => 1000,
            'selling_price'                 => 1500,
            'sale_price_after_discount'     => 1400,
            'percentage_discount'           => 6,
            'stock'                         => 5,
            'market_stock'                  => 2,
            'low_stock_threshold'           => 2,
            'sku_unique'                    => 'AUDIT-SKU-' . uniqid(),
            'wa_code'                       => 'AUDIT-WA-' . uniqid(),
            'band_closure_id'               => $this->ref['closure'],
            'dial_display_type_id'          => $this->ref['display'],
            'case_size'                     => 41,
            'case_size_type_id'             => $s,
            'case_shape_id'                 => $this->ref['shape'],
            'band_material_id'              => $this->ref['material'],
            'watch_movement_id'             => $this->ref['movement'],
            'band_length'                   => 220,
            'band_size_type_id'             => $s,
            'water_resistance'              => 300,
            'water_resistance_size_type_id' => $s,
            'band_width'                    => 20,
            'band_width_size_type_id'       => $s,
            'case_thickness'                => 12,
            'case_thickness_size_type_id'   => $s,
            'dial_case_material_id'         => $this->ref['material'],
            'dial_glass_material_id'        => $this->ref['material'],
            'watch_height'                  => 48,
            'watch_height_size_type_id'     => $s,
            'watch_width'                   => 41,
            'watch_width_size_type_id'      => $s,
            'watch_length'                  => 47,
            'watch_length_size_type_id'     => $s,
            'model_name'                    => ['en' => 'Model EN', 'ar' => 'موديل'],
            'model_number'                  => 'MODEL-123',
            'warranty_years'                => '5',
            'interchangeable_dial'          => 1,
            'interchangeable_strap'         => 0,
            'watch_box'                     => 1,
            'country'                       => ['en' => 'Switzerland', 'ar' => 'سويسرا'],
            'stone'                         => ['en' => 'Diamond', 'ar' => 'ألماس'],
            'search_keywords'               => 'audit keywords',
            'active'                        => 1,
            'seo_title'                     => 'Audit SEO',
            'seo_slug'                      => 'audit-slug-' . uniqid(),
            'seo_meta_description'          => 'Audit meta',
            'image'                         => $this->fakeImage('main.jpg'),
        ], $overrides);
    }

    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 600);
    }

    /**
     * GET the admin route bypassing mcamara's locale-redirect middleware. All of
     * web.php is wrapped in ->prefix(LaravelLocalization::setLocale()) + the
     * localizationRedirect filter (RouteServiceProvider). Under test setLocale()
     * is empty, so routes register at /admin/... but the filter still 302s GETs to
     * a localized URL that doesn't exist. Skipping the two redirect filters lets
     * the GET reach the controller. (POST isn't redirected, so it needs no skip.)
     */
    private function adminGet(string $routeName, $params = [])
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware([LaravelLocalizationRedirectFilter::class, LocaleSessionRedirect::class])
            ->get(route($routeName, $params));
    }

    private function trackImage(Product $p): void
    {
        if ($p->image) {
            $this->tempFiles[] = public_path('Uploads_Images/Product/' . $p->image);
        }
        foreach ($p->productImages as $img) {
            $this->tempFiles[] = public_path('Uploads_Images/' . ProductImage::FOLDER . '/' . $img->image);
        }
    }

    // ── 1. STORE — every watch field persists ────────────────────────────────

    public function test_store_persists_all_watch_fields(): void
    {
        $payload = $this->watchPayload();

        $res = $this->actingAs($this->admin)->post(route('product.store'), $payload);
        $res->assertRedirect(route('product.index'));

        $p = Product::with(['translations', 'feature', 'gender', 'dialColor', 'bandColor'])->latest('id')->first();
        $this->assertNotNull($p, 'Product was not created');
        $this->trackImage($p);

        // The fields the team reported vanishing — must all match the watch values.
        $this->assertEquals($payload['case_shape_id'], $p->case_shape_id, 'case_shape_id lost');
        $this->assertEquals($payload['band_closure_id'], $p->band_closure_id, 'band_closure_id lost');
        $this->assertEquals($payload['band_material_id'], $p->band_material_id, 'band_material_id lost');
        $this->assertEquals('5', (string) $p->warranty_years, 'warranty_years lost');
        $this->assertEquals($payload['case_size_type_id'], $p->case_size_type_id, 'case size unit lost');

        // Fields that always persisted (sanity).
        $this->assertEquals(41, (int) $p->case_size);
        $this->assertEquals(300, (int) $p->water_resistance);
        $this->assertEquals(20, (int) $p->band_width);
        $this->assertEquals(12, (int) $p->case_thickness);

        // The 4 sizes the create form was missing before the fix.
        $this->assertEquals(220, (int) $p->band_length, 'band_length not saved');
        $this->assertEquals(48, (int) $p->watch_height, 'watch_height not saved');
        $this->assertEquals(41, (int) $p->watch_width, 'watch_width not saved');
        $this->assertEquals(47, (int) $p->watch_length, 'watch_length not saved');
        $this->assertEquals($this->ref['size'], $p->band_size_type_id);
        $this->assertEquals($this->ref['size'], $p->watch_height_size_type_id);

        // Flags + identity.
        $this->assertEquals(1, (int) $p->interchangeable_dial);
        $this->assertEquals(0, (int) $p->interchangeable_strap);
        $this->assertEquals(1, (int) $p->watch_box);
        $this->assertEquals('MODEL-123', $p->model_number);

        // Translations.
        $this->assertEquals('Audit Watch', $p->translate('en')->product_title);
        $this->assertEquals('ساعة اختبار', $p->translate('ar')->product_title);
        $this->assertEquals('Model EN', $p->translate('en')->model_name);
        $this->assertEquals('موديل', $p->translate('ar')->model_name);
        $this->assertEquals('Switzerland', $p->translate('en')->country);
        $this->assertEquals('Diamond', $p->translate('en')->stone);

        // Relations.
        $this->assertEqualsCanonicalizing([$this->ref['feature']], $p->feature->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->ref['gender']], $p->gender->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->ref['color']], $p->dialColor->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->ref['color']], $p->bandColor->pluck('id')->all());
    }

    // ── 2. UPDATE — every field changes, nothing nulled ──────────────────────

    public function test_update_changes_every_field_and_nulls_nothing(): void
    {
        // Create first.
        $this->actingAs($this->admin)->post(route('product.store'), $this->watchPayload());
        $p = Product::latest('id')->first();
        $this->trackImage($p);

        // Distinct "changed" lookup ids where a second row exists (fallback to same).
        $shape2   = Shape::orderBy('id', 'desc')->value('id');
        $material2 = Material::orderBy('id', 'desc')->value('id');
        $closure2 = ClosureType::orderBy('id', 'desc')->value('id');
        $s        = $this->ref['size'];

        $update = $this->watchPayload([
            'product_title'         => ['en' => 'Audit Watch V2', 'ar' => 'ساعة اختبار ٢'],
            'model_name'            => ['en' => 'Model V2', 'ar' => 'موديل ٢'],
            'country'               => ['en' => 'Japan', 'ar' => 'اليابان'],
            'stone'                 => ['en' => 'Ruby', 'ar' => 'ياقوت'],
            'case_size'             => 43,
            'water_resistance'      => 100,
            'band_width'            => 22,
            'case_thickness'        => 14,
            'band_length'           => 240,
            'watch_height'          => 50,
            'watch_width'           => 43,
            'watch_length'          => 49,
            'warranty_years'        => '3',
            'interchangeable_dial'  => 0,
            'interchangeable_strap' => 1,
            'watch_box'             => 0,
            'model_number'          => 'MODEL-999',
            'case_shape_id'         => $shape2,
            'band_material_id'      => $material2,
            'band_closure_id'       => $closure2,
            'wa_code'               => $p->wa_code, // keep (unique-ignore-self)
        ]);
        unset($update['image']); // keep existing image on update

        $res = $this->actingAs($this->admin)->put(route('product.update', $p->id), $update);
        $res->assertRedirect(route('product.index'));

        $p->refresh()->load('translations');
        $this->assertEquals(43, (int) $p->case_size);
        $this->assertEquals(100, (int) $p->water_resistance);
        $this->assertEquals(22, (int) $p->band_width);
        $this->assertEquals(14, (int) $p->case_thickness);
        $this->assertEquals(240, (int) $p->band_length);
        $this->assertEquals(50, (int) $p->watch_height);
        $this->assertEquals(43, (int) $p->watch_width);
        $this->assertEquals(49, (int) $p->watch_length);
        $this->assertEquals('3', (string) $p->warranty_years);
        $this->assertEquals(0, (int) $p->interchangeable_dial);
        $this->assertEquals(1, (int) $p->interchangeable_strap);
        $this->assertEquals(0, (int) $p->watch_box);
        $this->assertEquals('MODEL-999', $p->model_number);
        $this->assertEquals($shape2, $p->case_shape_id);
        $this->assertEquals($material2, $p->band_material_id);
        $this->assertEquals($closure2, $p->band_closure_id);
        $this->assertEquals('Audit Watch V2', $p->translate('en')->product_title);
        $this->assertEquals('Model V2', $p->translate('en')->model_name);
        $this->assertEquals('Japan', $p->translate('en')->country);
        $this->assertEquals('Ruby', $p->translate('en')->stone);

        // Nothing critical nulled.
        foreach (['case_shape_id', 'band_closure_id', 'band_material_id', 'warranty_years', 'case_size', 'band_length', 'watch_height'] as $col) {
            $this->assertNotNull($p->{$col}, "{$col} was nulled on update");
        }
    }

    // ── 3. PARTIAL update — untouched fields keep their values ────────────────

    public function test_partial_update_preserves_untouched_fields(): void
    {
        $this->actingAs($this->admin)->post(route('product.store'), $this->watchPayload());
        $p = Product::latest('id')->first();
        $this->trackImage($p);

        $originalShape   = $p->case_shape_id;
        $originalWarranty = $p->warranty_years;
        $originalCountry = $p->translate('en')->country;

        // Minimal valid PUT: only the required fields + one changed value. Omits
        // case_shape_id, warranty_years, country entirely.
        $partial = [
            'product_title'     => ['en' => 'Audit Watch', 'ar' => 'ساعة اختبار'],
            'short_description' => ['en' => 'Short EN', 'ar' => 'وصف قصير'],
            'long_description'  => ['en' => 'Long EN', 'ar' => 'وصف طويل'],
            'brand_id'          => $this->ref['brand'],
            'gender_id'         => [$this->ref['gender']],
            'purchase_price'    => 1000,
            'selling_price'     => 1600, // changed
            'active'            => 1,
            'wa_code'           => $p->wa_code,
        ];

        $res = $this->actingAs($this->admin)->put(route('product.update', $p->id), $partial);
        $res->assertRedirect(route('product.index'));

        $p->refresh()->load('translations');
        $this->assertEquals(1600, (int) $p->selling_price, 'changed field did not persist');
        $this->assertEquals($originalShape, $p->case_shape_id, 'case_shape_id nulled by partial update');
        $this->assertEquals($originalWarranty, $p->warranty_years, 'warranty_years nulled by partial update');
        $this->assertEquals($originalCountry, $p->translate('en')->country, 'country nulled by partial update');
    }

    // ── 4. FASHION — extra_attributes JSON persists + updates ────────────────

    public function test_fashion_extra_attributes_persist_and_update(): void
    {
        $payload = $this->watchPayload([
            'product_title' => ['en' => 'Audit Belt', 'ar' => 'حزام اختبار'],
            'seo_slug'      => 'audit-belt-' . uniqid(),
            'wa_code'       => 'AUDIT-BELT-' . uniqid(),
            'sku_unique'    => 'AUDIT-BELT-' . uniqid(),
            // Bag/extra fields the create form's fashion blocks post.
            'bag_strap_type'   => 'Removable',
            'bag_compartments' => 3,
            'waterproof'       => 1,
        ]);

        $this->actingAs($this->admin)->post(route('product.store'), $payload)
            ->assertRedirect(route('product.index'));

        $p = Product::latest('id')->first();
        $this->trackImage($p);

        $this->assertIsArray($p->extra_attributes, 'extra_attributes not cast to array');
        $this->assertEquals('Removable', $p->extra_attributes['bag_strap_type'] ?? null);
        $this->assertEquals(3, $p->extra_attributes['bag_compartments'] ?? null);
        $this->assertEquals(1, $p->extra_attributes['waterproof'] ?? null);

        // Update the extras.
        $update = $this->watchPayload([
            'product_title'  => ['en' => 'Audit Belt', 'ar' => 'حزام اختبار'],
            'wa_code'        => $p->wa_code,
            'seo_slug'       => $p->seo_slug,
            'bag_strap_type' => 'Fixed',
            'waterproof'     => 0,
        ]);
        unset($update['image']);

        $this->actingAs($this->admin)->put(route('product.update', $p->id), $update)
            ->assertRedirect(route('product.index'));

        $p->refresh();
        $this->assertEquals('Fixed', $p->extra_attributes['bag_strap_type'] ?? null, 'extra_attributes did not update');
        $this->assertEquals(0, $p->extra_attributes['waterproof'] ?? null);
    }

    // ── 5. IMAGES — main → Product/, gallery → Product_image/ ────────────────

    public function test_main_and_gallery_images_land_in_correct_folders(): void
    {
        $payload = $this->watchPayload([
            'gallery_images' => [$this->fakeImage('g1.jpg'), $this->fakeImage('g2.jpg')],
        ]);

        $this->actingAs($this->admin)->post(route('product.store'), $payload)
            ->assertRedirect(route('product.index'));

        $p = Product::with('productImages')->latest('id')->first();
        $this->trackImage($p);

        $this->assertNotNull($p->image, 'main image filename not stored');
        $this->assertFileExists(public_path('Uploads_Images/Product/' . $p->image), 'main image file missing from Product/');

        $this->assertCount(2, $p->productImages, 'gallery rows not created');
        foreach ($p->productImages as $img) {
            $this->assertFileExists(
                public_path('Uploads_Images/' . ProductImage::FOLDER . '/' . $img->image),
                'gallery image missing from Product_image/',
            );
        }
        // First gallery image is the cover.
        $this->assertEquals(1, (int) $p->productImages->firstWhere('sort', 0)->is_cover);
    }

    // ── 6. VALIDATION bounce — errors + old input preserved ──────────────────

    public function test_store_validation_failure_redirects_with_errors_and_old_input(): void
    {
        $payload = $this->watchPayload();
        unset($payload['brand_id']); // required → must fail
        $payload['product_title'] = ['en' => 'Kept Title', 'ar' => 'عنوان محفوظ'];

        $res = $this->actingAs($this->admin)
            ->from(route('product.create'))
            ->post(route('product.store'), $payload);

        $res->assertRedirect(route('product.create'));
        $res->assertSessionHasErrors('brand_id');
        // The team's typed data must survive the bounce (withInput contract).
        $res->assertSessionHasInput('product_title', ['en' => 'Kept Title', 'ar' => 'عنوان محفوظ']);
        $res->assertSessionHasInput('warranty_years', '5');
    }

    // ── 7. Characterisation: empty value DOES null (why client fix is needed) ──

    public function test_empty_value_overwrites_document_why_client_fix_is_required(): void
    {
        $this->actingAs($this->admin)->post(route('product.store'), $this->watchPayload());
        $p = Product::latest('id')->first();
        $this->trackImage($p);
        $this->assertNotNull($p->case_shape_id);

        // Simulate what the OLD form did: a hidden block posted case_shape_id=''
        // last, so PHP handed the controller an empty string.
        $update = $this->watchPayload(['case_shape_id' => '', 'wa_code' => $p->wa_code]);
        unset($update['image']);
        $this->actingAs($this->admin)->put(route('product.update', $p->id), $update)
            ->assertRedirect(route('product.index'));

        $p->refresh();
        $this->assertNull($p->case_shape_id, 'Empty override nulled the field — this is why disabling hidden inputs (create.blade) is the fix.');
    }

    // ── 8. FASHION DIMENSIONS — width/height/depth/strap persist + update ────

    public function test_fashion_dimensions_persist_and_update(): void
    {
        $payload = $this->watchPayload([
            'product_title'   => ['en' => 'Audit Bag', 'ar' => 'حقيبة اختبار'],
            'seo_slug'        => 'audit-bag-' . uniqid(),
            'wa_code'         => 'AUDIT-BAG-' . uniqid(),
            'sku_unique'      => 'AUDIT-BAG-' . uniqid(),
            'width_cm'        => 30,
            'height_cm'       => 20,
            'depth_cm'        => 10,
            'strap_length_cm' => 120,
        ]);

        $this->actingAs($this->admin)->post(route('product.store'), $payload)
            ->assertRedirect(route('product.index'));

        $p = Product::latest('id')->first();
        $this->trackImage($p);

        $this->assertIsArray($p->extra_attributes);
        $this->assertEquals(30, $p->extra_attributes['width_cm'] ?? null, 'width_cm not saved');
        $this->assertEquals(20, $p->extra_attributes['height_cm'] ?? null, 'height_cm not saved');
        $this->assertEquals(10, $p->extra_attributes['depth_cm'] ?? null, 'depth_cm not saved');
        $this->assertEquals(120, $p->extra_attributes['strap_length_cm'] ?? null, 'strap_length_cm not saved');

        // Update dimensions (edit form now carries these inputs).
        $update = $this->watchPayload([
            'product_title'   => ['en' => 'Audit Bag', 'ar' => 'حقيبة اختبار'],
            'wa_code'         => $p->wa_code,
            'seo_slug'        => $p->seo_slug,
            'width_cm'        => 33,
            'height_cm'       => 22,
            'depth_cm'        => 11,
            'strap_length_cm' => 130,
        ]);
        unset($update['image']);

        $this->actingAs($this->admin)->put(route('product.update', $p->id), $update)
            ->assertRedirect(route('product.index'));

        $p->refresh();
        $this->assertEquals(33, $p->extra_attributes['width_cm'] ?? null, 'width_cm did not update');
        $this->assertEquals(130, $p->extra_attributes['strap_length_cm'] ?? null, 'strap_length_cm did not update');
    }

    // ── 9. Pages render (Blade compiles; fieldset guard + dimensions present) ─

    public function test_create_page_renders_with_disabled_category_fieldsets(): void
    {
        $res = $this->adminGet('product.create');
        $res->assertOk();
        // Category blocks are <fieldset ... disabled> (server-side guard, not JS-only).
        $res->assertSee('fieldset class="cat-fields', false);
        $res->assertSee('disabled', false);
        // Fashion dimension inputs exist.
        $res->assertSee('name="width_cm"', false);
        $res->assertSee('name="strap_length_cm"', false);
    }

    public function test_edit_page_renders_and_binds_extra_attributes(): void
    {
        // Create a fashion product with dimensions, then confirm the edit form
        // shows them back (round-trip on the DISPLAY side, not just DB).
        $this->actingAs($this->admin)->post(route('product.store'), $this->watchPayload([
            'product_title'   => ['en' => 'Audit Bag2', 'ar' => 'حقيبة'],
            'seo_slug'        => 'audit-bag2-' . uniqid(),
            'wa_code'         => 'AUDIT-BAG2-' . uniqid(),
            'sku_unique'      => 'AUDIT-BAG2-' . uniqid(),
            'width_cm'        => 42,
            'strap_length_cm' => 99,
        ]));
        $p = Product::latest('id')->first();
        $this->trackImage($p);

        $res = $this->adminGet('product.edit', $p->id);
        $res->assertOk();
        $res->assertSee('value="42"', false);  // width_cm bound
        $res->assertSee('value="99"', false);  // strap_length_cm bound
        $res->assertSee('name="width_cm"', false);
    }

    public function test_manage_images_route_redirects_to_edit(): void
    {
        $this->actingAs($this->admin)->post(route('product.store'), $this->watchPayload());
        $p = Product::latest('id')->first();
        $this->trackImage($p);

        $this->adminGet('product.images.index', $p->id)
            ->assertRedirect(route('product.edit', $p->id));
    }

    public function test_index_page_renders_with_search_and_delete_guard(): void
    {
        $this->actingAs($this->admin)->post(route('product.store'), $this->watchPayload());
        $p = Product::latest('id')->first();
        $this->trackImage($p);

        $res = $this->adminGet('product.index');
        $res->assertOk();
        $res->assertSee($p->wa_code);
        // Delete has a confirm() guard against accidental deletion.
        $res->assertSee('onsubmit="return confirm', false);
    }
}
