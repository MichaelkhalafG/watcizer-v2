<?php

/*
|--------------------------------------------------------------------------
| M1 — build the clean core (CLEAN_CORE_STUDY §2.8.1)
|--------------------------------------------------------------------------
| Creates the catalog_*, storefront_*, inventory_* and integration_* tables
| BESIDE the legacy tables in the shared database. It never touches a legacy
| table; the only cross-reference is catalog_products.created_by/updated_by
| → users (shared, D5). Runs under the `mariadb` driver.
|
| Engine notes (drift report §5): json() becomes longtext + CHECK(json_valid);
| the FULLTEXT index on catalog_product_search is plain word-based InnoDB
| (no ngram on MariaDB). Nothing here needs MariaDB > 10.2.
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * astrotomic-style translation table.
     *
     * @param  array<string, 'string'|'string?'|'text?'|'long?'>  $columns
     */
    private function translations(string $table, string $fk, string $parent, array $columns): void
    {
        // Explicit short index names: Laravel's generated
        // "<table>_<fk>_locale_unique" exceeds MariaDB/MySQL's 64-character
        // identifier limit for the longer translation tables (e.g.
        // catalog_movement_type_translations, storefront_category_translations).
        $idx = str_replace(['catalog_', 'storefront_', '_translations'], ['c_', 's_', ''], $table).'_tr';

        Schema::create($table, function (Blueprint $t) use ($fk, $parent, $columns, $idx) {
            $t->id();
            $t->foreignId($fk)->constrained($parent)->cascadeOnDelete();
            $t->char('locale', 2)->index("{$idx}_locale_idx");
            foreach ($columns as $name => $def) {
                match ($def) {
                    'string' => $t->string($name),
                    'string?' => $t->string($name)->nullable(),
                    'text?' => $t->text($name)->nullable(),
                    'long?' => $t->longText($name)->nullable(),
                };
            }
            $t->unique([$fk, 'locale'], "{$idx}_fk_locale_unique");
        });
    }

    /** id + timestamps master table */
    private function master(string $table, ?callable $extra = null): void
    {
        Schema::create($table, function (Blueprint $t) use ($extra) {
            $t->id();
            if ($extra) {
                $extra($t);
            }
            $t->timestamps();
        });
    }

    public function up(): void
    {
        // ── master data ──────────────────────────────────────────────────
        $this->master('catalog_brands', function (Blueprint $t) {
            $t->string('slug', 120)->unique();
            $t->string('logo_path')->nullable();
            $t->boolean('is_active')->default(true);
        });
        $this->translations('catalog_brand_translations', 'brand_id', 'catalog_brands', ['name' => 'string']);

        $this->master('catalog_grades', fn (Blueprint $t) => $t->string('image_path')->nullable());
        $this->translations('catalog_grade_translations', 'grade_id', 'catalog_grades', ['name' => 'string', 'description' => 'text?']);

        $this->master('catalog_colors', fn (Blueprint $t) => $t->char('hex', 7)->nullable());
        $this->translations('catalog_color_translations', 'color_id', 'catalog_colors', ['name' => 'string']);

        $this->master('catalog_sizes', function (Blueprint $t) {
            $t->string('type', 24)->default('general')->index();
            $t->smallInteger('sort')->default(0);
        });
        $this->translations('catalog_size_translations', 'size_id', 'catalog_sizes', ['name' => 'string']);

        $this->master('catalog_units', fn (Blueprint $t) => $t->string('code', 16)->unique());
        $this->translations('catalog_unit_translations', 'unit_id', 'catalog_units', ['name' => 'string']);

        foreach ([
            'materials' => 'material', 'shapes' => 'shape', 'movement_types' => 'movement_type',
            'closure_types' => 'closure_type', 'display_types' => 'display_type',
            'features' => 'feature', 'genders' => 'gender',
        ] as $plural => $singular) {
            $this->master("catalog_{$plural}");
            $this->translations("catalog_{$singular}_translations", "{$singular}_id", "catalog_{$plural}", ['name' => 'string']);
        }

        // ── products ─────────────────────────────────────────────────────
        Schema::create('catalog_products', function (Blueprint $t) {
            $t->id();                                                   // preserved from legacy products.id
            $t->string('family', 24)->default('watch');                 // watch | fashion | bag | wallet | perfume | electronics | other
            $t->foreignId('brand_id')->constrained('catalog_brands')->restrictOnDelete();
            $t->foreignId('grade_id')->nullable()->constrained('catalog_grades')->restrictOnDelete();
            $t->string('wa_code', 64)->unique();
            $t->string('sku', 64)->nullable()->unique();
            $t->string('model_number', 100)->nullable();
            $t->string('hs_code', 32)->nullable();                     // NEW column — no legacy source (drift report S-01)
            $t->decimal('purchase_price', 12, 2)->default(0);
            $t->decimal('selling_price', 12, 2);
            $t->decimal('sale_price', 12, 2)->nullable();               // valid only when 0 < sale < selling (service-enforced)
            $t->char('currency', 3)->default('EGP');
            $t->integer('stock_express')->default(0);
            $t->integer('stock_market')->default(0);
            $t->boolean('in_stock')->default(false);                    // denormalised: stock_express > 0 OR stock_market > 0
            $t->unsignedSmallInteger('low_stock_threshold')->default(5);
            $t->unsignedTinyInteger('warranty_years')->nullable();
            $t->boolean('is_active')->default(true);
            $t->decimal('rating_avg', 3, 2)->nullable();
            $t->unsignedInteger('rating_count')->default(0);
            $t->text('search_keywords')->nullable();
            $t->json('specs')->nullable();                              // family-specific attributes (legacy extra_attributes)
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['brand_id', 'is_active'], 'cp_brand_active_idx');
            $t->index(['family', 'is_active'], 'cp_family_active_idx');
            $t->index(['is_active', 'created_at'], 'cp_active_created_idx');
            $t->index(['is_active', 'in_stock'], 'cp_active_instock_idx');
            $t->index('selling_price', 'cp_selling_price_idx');
            $t->index('rating_avg', 'cp_rating_idx');
        });

        $this->translations('catalog_product_translations', 'product_id', 'catalog_products', [
            'title' => 'string', 'short_description' => 'text?', 'long_description' => 'long?',
            'model_name' => 'string?', 'country' => 'string?', 'stone' => 'string?',
            'meta_title' => 'string?', 'meta_description' => 'string?',
        ]);
        Schema::table('catalog_product_translations', fn (Blueprint $t) => $t->index(['locale', 'title'], 'cpt_locale_title_idx'));

        Schema::create('catalog_product_watch_specs', function (Blueprint $t) {
            $t->foreignId('product_id')->primary()->constrained('catalog_products')->cascadeOnDelete();
            $t->decimal('case_size', 8, 2)->nullable();
            $t->foreignId('case_size_unit_id')->nullable()->constrained('catalog_units')->restrictOnDelete();
            $t->foreignId('case_shape_id')->nullable()->constrained('catalog_shapes')->restrictOnDelete();
            $t->foreignId('case_material_id')->nullable()->constrained('catalog_materials')->restrictOnDelete();
            $t->foreignId('glass_material_id')->nullable()->constrained('catalog_materials')->restrictOnDelete();
            $t->decimal('case_thickness', 8, 2)->nullable();
            $t->foreignId('case_thickness_unit_id')->nullable()->constrained('catalog_units')->restrictOnDelete();
            $t->foreignId('band_material_id')->nullable()->constrained('catalog_materials')->restrictOnDelete();
            $t->foreignId('band_closure_id')->nullable()->constrained('catalog_closure_types')->restrictOnDelete();
            $t->decimal('band_length', 8, 2)->nullable();
            $t->foreignId('band_length_unit_id')->nullable()->constrained('catalog_units')->restrictOnDelete();
            $t->decimal('band_width', 8, 2)->nullable();
            $t->foreignId('band_width_unit_id')->nullable()->constrained('catalog_units')->restrictOnDelete();
            $t->foreignId('dial_display_type_id')->nullable()->constrained('catalog_display_types')->restrictOnDelete();
            $t->foreignId('movement_type_id')->nullable()->constrained('catalog_movement_types')->restrictOnDelete();
            $t->integer('water_resistance')->nullable();
            $t->foreignId('water_resistance_unit_id')->nullable()->constrained('catalog_units')->restrictOnDelete();
            $t->decimal('height', 8, 2)->nullable();
            $t->foreignId('height_unit_id')->nullable()->constrained('catalog_units')->restrictOnDelete();
            $t->decimal('width', 8, 2)->nullable();
            $t->foreignId('width_unit_id')->nullable()->constrained('catalog_units')->restrictOnDelete();
            $t->decimal('length', 8, 2)->nullable();
            $t->foreignId('length_unit_id')->nullable()->constrained('catalog_units')->restrictOnDelete();
            $t->boolean('interchangeable_dial')->nullable();
            $t->boolean('interchangeable_strap')->nullable();
            $t->boolean('watch_box')->nullable();
        });

        Schema::create('catalog_product_images', function (Blueprint $t) {
            $t->id();                                                   // preserved from product_images.id; cover rows get new ids
            $t->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $t->string('path');                                         // relative to Uploads_Images/, e.g. "Product/169_x.webp"
            $t->boolean('is_cover')->default(false);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->unsignedSmallInteger('width')->nullable();
            $t->unsignedSmallInteger('height')->nullable();
            $t->string('alt_en')->nullable();
            $t->string('alt_ar')->nullable();
            $t->json('renditions')->nullable();                         // {"avif":{"320":"…","640":"…"},"webp":{…}}
            $t->timestamps();
            $t->index(['product_id', 'is_cover', 'sort'], 'cpi_product_cover_sort_idx');
        });

        Schema::create('catalog_product_feature', function (Blueprint $t) {
            $t->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $t->foreignId('feature_id')->constrained('catalog_features')->restrictOnDelete();
            $t->primary(['product_id', 'feature_id']);
        });
        Schema::create('catalog_product_gender', function (Blueprint $t) {
            $t->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $t->foreignId('gender_id')->constrained('catalog_genders')->restrictOnDelete();
            $t->primary(['product_id', 'gender_id']);
            $t->index('gender_id');
        });
        Schema::create('catalog_product_color', function (Blueprint $t) {
            $t->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $t->foreignId('color_id')->constrained('catalog_colors')->restrictOnDelete();
            $t->string('role', 8);                                      // dial | band | main
            $t->primary(['product_id', 'color_id', 'role']);
            $t->index(['role', 'color_id'], 'cpc_role_color_idx');
        });

        Schema::create('catalog_product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $t->string('sku', 64)->nullable()->unique();
            $t->string('label', 100);
            $t->foreignId('color_id')->nullable()->constrained('catalog_colors')->restrictOnDelete();
            $t->foreignId('size_id')->nullable()->constrained('catalog_sizes')->restrictOnDelete();
            $t->decimal('price_delta', 12, 2)->default(0);
            $t->integer('stock_express')->default(0);
            $t->integer('stock_market')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->index(['product_id', 'is_active', 'sort'], 'cpv_product_active_idx');
        });

        Schema::create('catalog_product_search', function (Blueprint $t) {
            $t->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $t->char('locale', 2);
            $t->text('body');
            $t->primary(['product_id', 'locale']);
        });
        DB::statement('ALTER TABLE catalog_product_search ADD FULLTEXT INDEX cps_body_ft (body)');

        // ── storefronts ──────────────────────────────────────────────────
        Schema::create('storefronts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->string('name', 100);
            $t->string('domain')->nullable();
            $t->json('locales');                                        // ["ar","en"]
            $t->char('default_locale', 2)->default('ar');
            $t->char('currency', 3)->default('EGP');
            $t->boolean('is_active')->default(true);
            $t->json('settings')->nullable();
            $t->timestamps();
        });

        Schema::create('storefront_product', function (Blueprint $t) {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $t->boolean('is_visible')->default(true);
            $t->boolean('is_featured')->default(false);
            $t->integer('sort_order')->default(0);
            $t->string('slug', 191);
            $t->decimal('price_override', 12, 2)->nullable();
            $t->decimal('sale_price_override', 12, 2)->nullable();
            $t->decimal('effective_price', 12, 2);                     // COALESCE(price_override, product.selling_price), service-maintained
            $t->decimal('effective_sale_price', 12, 2)->nullable();    // valid sale after override rule, else NULL
            $t->timestamp('published_at')->nullable();
            $t->timestamps();

            $t->unique(['storefront_id', 'product_id'], 'sp_storefront_product_unique');
            $t->unique(['storefront_id', 'slug'], 'sp_storefront_slug_unique');
            $t->index(['storefront_id', 'is_visible', 'published_at', 'sort_order', 'product_id'], 'sp_list_position_idx');
            $t->index(['storefront_id', 'is_visible', 'effective_price'], 'sp_list_price_idx');
            $t->index(['storefront_id', 'is_featured', 'sort_order'], 'sp_featured_idx');
        });

        Schema::create('storefront_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedSmallInteger('depth')->default(1);
            $t->string('path', 1000);                                   // "/12/57/" (ids, incl. self)
            $t->string('slug', 191);
            $t->string('image_path')->nullable();
            $t->string('icon', 64)->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('show_in_menu')->default(true);
            $t->integer('sort_order')->default(0);
            $t->string('legacy_source', 32)->nullable();                // category_type | sub_type | category
            $t->unsignedBigInteger('legacy_id')->nullable();
            $t->unsignedBigInteger('legacy_parent_id')->nullable();     // category_type id for mirrored sub types (pairing key)
            $t->timestamps();

            $t->foreign('parent_id')->references('id')->on('storefront_categories')->cascadeOnDelete();
            $t->unique(['storefront_id', 'slug'], 'sc_storefront_slug_unique');
            $t->unique(['storefront_id', 'legacy_source', 'legacy_id', 'legacy_parent_id'], 'sc_legacy_unique');
            $t->index(['storefront_id', 'parent_id', 'sort_order'], 'sc_parent_sort_idx');
            $t->index(['storefront_id', 'is_active', 'show_in_menu', 'sort_order'], 'sc_menu_idx');
        });
        Schema::table('storefront_categories', fn (Blueprint $t) => $t->rawIndex('storefront_id, path(191)', 'sc_path_idx'));
        $this->translations('storefront_category_translations', 'storefront_category_id', 'storefront_categories', [
            'name' => 'string', 'description' => 'text?', 'meta_title' => 'string?', 'meta_description' => 'string?',
        ]);

        Schema::create('storefront_category_product', function (Blueprint $t) {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->foreignId('storefront_category_id')->constrained('storefront_categories')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_primary')->default(false);
            $t->timestamps();

            $t->unique(['storefront_category_id', 'product_id'], 'scp_category_product_unique');
            $t->index(['storefront_category_id', 'sort_order', 'product_id'], 'scp_category_sort_idx');
            $t->index(['storefront_id', 'product_id', 'is_primary'], 'scp_storefront_product_idx');
        });

        Schema::create('storefront_banners', function (Blueprint $t) {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->string('placement', 32);
            $t->string('image_path');
            $t->string('type_show', 8)->nullable();
            $t->string('link_url')->nullable();
            $t->foreignId('product_id')->nullable()->constrained('catalog_products')->cascadeOnDelete();
            $t->foreignId('storefront_category_id')->nullable()->constrained('storefront_categories')->nullOnDelete();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
            $t->index(['storefront_id', 'placement', 'is_active', 'sort_order'], 'sb_placement_idx');
        });

        Schema::create('storefront_redirects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->char('from_hash', 40);                                  // sha1(lower(from_path))
            $t->string('from_path', 1000);
            $t->string('to_path', 1000);
            $t->unsignedSmallInteger('status')->default(301);
            $t->string('source', 32);                                   // woocommerce | slug_change | legacy_id | manual
            $t->unsignedInteger('hits')->default(0);
            $t->timestamp('last_hit_at')->nullable();
            $t->timestamps();
            $t->unique(['storefront_id', 'from_hash'], 'sr_storefront_hash_unique');
        });

        // ── inventory ledger + integration outbox (§4) ───────────────────
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('catalog_product_variants')->nullOnDelete();
            $t->string('bucket', 8);                                    // express | market
            $t->integer('quantity_delta');
            $t->integer('quantity_after');
            $t->string('reason', 32);                                   // order | order_cancel | payment_failed | restock | manual | import | adjustment | erp_sync | transform
            $t->string('reference_type', 64)->nullable();               // App\Models\… (morph)
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('actor_type', 16)->default('system');            // user | admin | system | erp
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->foreignId('storefront_id')->nullable()->constrained('storefronts')->nullOnDelete();
            $t->string('external_ref', 100)->nullable();                // Morabaa document id
            $t->string('note', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['product_id', 'created_at'], 'im_product_created_idx');
            $t->index(['reference_type', 'reference_id'], 'im_reference_idx');
            $t->index(['reason', 'created_at'], 'im_reason_created_idx');
            $t->index('external_ref', 'im_external_ref_idx');
        });

        Schema::create('integration_outbox', function (Blueprint $t) {
            $t->id();
            $t->string('channel', 32);                                  // morabaa | search | webhook …
            $t->string('event', 64);                                    // stock.changed | product.updated | order.placed …
            $t->string('aggregate_type', 64);
            $t->unsignedBigInteger('aggregate_id');
            $t->json('payload');
            $t->string('status', 16)->default('pending');               // pending | sent | failed | skipped
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('available_at')->useCurrent();
            $t->timestamp('processed_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['channel', 'status', 'available_at'], 'io_channel_status_idx');
            $t->index(['aggregate_type', 'aggregate_id'], 'io_aggregate_idx');
        });
    }

    public function down(): void
    {
        foreach ([
            'integration_outbox', 'inventory_movements', 'storefront_redirects', 'storefront_banners',
            'storefront_category_product', 'storefront_category_translations', 'storefront_categories',
            'storefront_product', 'storefronts', 'catalog_product_search', 'catalog_product_variants',
            'catalog_product_color', 'catalog_product_gender', 'catalog_product_feature',
            'catalog_product_images', 'catalog_product_watch_specs', 'catalog_product_translations',
            'catalog_products',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        foreach ([
            'gender', 'feature', 'display_type', 'closure_type', 'movement_type', 'shape', 'material',
            'unit', 'size', 'color', 'grade', 'brand',
        ] as $s) {
            Schema::dropIfExists("catalog_{$s}_translations");
        }
        foreach ([
            'genders', 'features', 'display_types', 'closure_types', 'movement_types', 'shapes', 'materials',
            'units', 'sizes', 'colors', 'grades', 'brands',
        ] as $p) {
            Schema::dropIfExists("catalog_{$p}");
        }
    }
};
