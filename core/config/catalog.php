<?php

/*
|--------------------------------------------------------------------------
| Catalog dashboard (CLEAN_CORE_STUDY §3.12 — wave 4B)
|--------------------------------------------------------------------------
|
| What the product form SHOWS for a given family, and which lookup lists the
| dashboard maintains. It is configuration and not code for one specific
| reason: a product's family is derived from its CATEGORY (see
| App\Domain\Catalog\FamilyForCategory), and the team invents categories. A
| new family, or a new attribute on an existing one, must be a line here —
| never a `match` in a controller and never a hard-coded category id. The
| legacy dashboard checked the family against hard-coded category ids and had
| to be hotfixed for it; this file is the reason that cannot recur here.
|
| ── The rule that ties this file to the transform ─────────────────────────
|
| A product created in the dashboard must land in the SAME family the
| transform would give it, or a rehearsal would silently re-classify it.
| Two halves:
|
|   • Family derivation uses `config('transform.family')` — the identical
|     config the transform reads — through the identical class.
|   • The JSON spec keys below are named so that
|     `FamilyResolver::resolve('', json_encode($specs), '')` never returns a
|     family OTHER than the one whose block wrote them.
|     tests/Feature/Manage/FamilySpecsTest.php asserts exactly that.
|
| `watch` is the exception: its attributes live in the dedicated
| `catalog_product_watch_specs` table (M1), not in the `specs` JSON, so its
| block is declared with `'table' => 'watch_specs'` and its fields are real
| columns of that table.
|
*/

return [

    /*
    | Families the dashboard shows an extra block for. The family list itself
    | is App\Models\Catalog\Product::FAMILIES; a family missing here simply
    | has no extra block.
    */
    'blocks' => [

        'watch' => [
            'label' => 'مواصفات الساعة',
            'table' => 'watch_specs',
            'fields' => [
                // A dimension and its unit are ONE control on screen, because the table pairs
                // them and a number without a unit is not a measurement.
                ['key' => 'case_size', 'label' => 'قياس العلبة', 'type' => 'decimal', 'unit' => 'case_size_unit_id'],
                ['key' => 'case_thickness', 'label' => 'سماكة العلبة', 'type' => 'decimal', 'unit' => 'case_thickness_unit_id'],
                ['key' => 'case_shape_id', 'label' => 'شكل العلبة', 'type' => 'lookup', 'lookup' => 'shapes'],
                ['key' => 'case_material_id', 'label' => 'مادة العلبة', 'type' => 'lookup', 'lookup' => 'materials'],
                ['key' => 'glass_material_id', 'label' => 'مادة الزجاج', 'type' => 'lookup', 'lookup' => 'materials'],
                ['key' => 'band_material_id', 'label' => 'مادة السوار', 'type' => 'lookup', 'lookup' => 'materials'],
                ['key' => 'band_closure_id', 'label' => 'نوع الإغلاق', 'type' => 'lookup', 'lookup' => 'closure_types'],
                ['key' => 'band_length', 'label' => 'طول السوار', 'type' => 'decimal', 'unit' => 'band_length_unit_id'],
                ['key' => 'band_width', 'label' => 'عرض السوار', 'type' => 'decimal', 'unit' => 'band_width_unit_id'],
                ['key' => 'dial_display_type_id', 'label' => 'نوع العرض', 'type' => 'lookup', 'lookup' => 'display_types'],
                ['key' => 'movement_type_id', 'label' => 'نوع الحركة', 'type' => 'lookup', 'lookup' => 'movements'],
                ['key' => 'water_resistance', 'label' => 'مقاومة الماء', 'type' => 'integer', 'unit' => 'water_resistance_unit_id'],
                ['key' => 'height', 'label' => 'الارتفاع', 'type' => 'decimal', 'unit' => 'height_unit_id'],
                ['key' => 'width', 'label' => 'العرض', 'type' => 'decimal', 'unit' => 'width_unit_id'],
                ['key' => 'length', 'label' => 'الطول', 'type' => 'decimal', 'unit' => 'length_unit_id'],
                ['key' => 'interchangeable_dial', 'label' => 'إمكانية تغيير القرص', 'type' => 'boolean'],
                ['key' => 'interchangeable_strap', 'label' => 'إمكانية تغيير السوار', 'type' => 'boolean'],
                ['key' => 'watch_box', 'label' => 'علبة أصلية', 'type' => 'boolean'],
            ],
        ],

        /*
        | The JSON families. Keys are the ones the LIVE DATA already uses —
        | verified 2026-09-11 against the production copy, where the only
        | `extra_attributes` keys in the whole catalogue are `width_cm`,
        | `height_cm`, `depth_cm`, `wallet_card_slots` and `coin_pocket` —
        | plus the prefixed keys `config('transform.family')` already reads.
        | Nothing invented that the family resolver cannot read back.
        */
        'bag' => [
            'label' => 'مواصفات الحقيبة',
            'table' => 'specs',
            'fields' => [
                ['key' => 'bag_type', 'label' => 'نوع الحقيبة', 'type' => 'string'],
                ['key' => 'strap_length_cm', 'label' => 'طول الحمّالة (سم)', 'type' => 'decimal'],
                ['key' => 'bag_compartments', 'label' => 'عدد الجيوب', 'type' => 'integer'],
                ['key' => 'width_cm', 'label' => 'العرض (سم)', 'type' => 'decimal'],
                ['key' => 'height_cm', 'label' => 'الارتفاع (سم)', 'type' => 'decimal'],
                ['key' => 'depth_cm', 'label' => 'العمق (سم)', 'type' => 'decimal'],
            ],
        ],

        'wallet' => [
            'label' => 'مواصفات المحفظة',
            'table' => 'specs',
            'fields' => [
                ['key' => 'wallet_card_slots', 'label' => 'جيوب البطاقات', 'type' => 'integer'],
                ['key' => 'coin_pocket', 'label' => 'جيب للعملات', 'type' => 'boolean'],
                ['key' => 'width_cm', 'label' => 'العرض (سم)', 'type' => 'decimal'],
                ['key' => 'height_cm', 'label' => 'الارتفاع (سم)', 'type' => 'decimal'],
            ],
        ],

        'perfume' => [
            'label' => 'مواصفات العطر',
            'table' => 'specs',
            'fields' => [
                ['key' => 'perfume_volume_ml', 'label' => 'الحجم (مل)', 'type' => 'integer'],
                ['key' => 'perfume_concentration', 'label' => 'التركيز', 'type' => 'string'],
                ['key' => 'perfume_family', 'label' => 'العائلة العطرية', 'type' => 'string'],
            ],
        ],

        'electronics' => [
            'label' => 'مواصفات الجهاز',
            'table' => 'specs',
            'fields' => [
                ['key' => 'elec_power_watts', 'label' => 'القدرة (واط)', 'type' => 'integer'],
                ['key' => 'elec_voltage', 'label' => 'الفولت', 'type' => 'string'],
                ['key' => 'elec_battery_mah', 'label' => 'البطارية (مللي أمبير)', 'type' => 'integer'],
                ['key' => 'width_cm', 'label' => 'العرض (سم)', 'type' => 'decimal'],
                ['key' => 'height_cm', 'label' => 'الارتفاع (سم)', 'type' => 'decimal'],
            ],
        ],

        /*
        | `fashion` and `other` deliberately have NO block: they are the
        | family resolver's fallbacks, and inventing attributes for
        | "whatever did not match" is how a form starts lying about the data.
        | They get the shared fields and a hint that says why.
        */
    ],

    /*
    | Lookup lists the dashboard maintains (scope item 6): a master table plus
    | its ar/en translation table. `usage` names the (table, column) pairs
    | that reference a row, which is what the screen counts before it lets
    | anyone delete one — a RESTRICT foreign key would refuse anyway, and an
    | error page is a worse answer than a number next to the delete button.
    |
    | Keys match App\Storefront\Lookups::TABLES so the storefront read layer
    | and the dashboard never disagree about what a list is called.
    */
    'lookups' => [
        /*
        | Brands are a lookup with three extra columns, not a screen of their own: a slug (the
        | live `/brand/<slug>` URL, so it is `LegacySlug`-shaped and unique), a logo in the shared
        | media tree, and an active flag. Modelling them here means one writer, one screen and one
        | usage count for eleven lists instead of a near-duplicate controller for this one.
        */
        'brands' => [
            'label' => 'الماركات',
            'master' => 'catalog_brands',
            'translations' => 'catalog_brand_translations',
            'fk' => 'brand_id',
            'extra' => [
                // NOT `required`: an empty slug is GENERATED from the English name, exactly as a
                // product's is (`LegacySlug`), which is the helpful behaviour for a form. The
                // writer refuses only when it cannot generate one — an Arabic-only name yields ''
                // through the legacy slugifier, and a brand with an empty public URL is worse than
                // a refusal.
                'slug' => ['label' => 'الرابط', 'type' => 'slug'],
                'logo_path' => ['label' => 'الشعار', 'type' => 'image', 'media_type' => 'brand'],
                'is_active' => ['label' => 'مفعّلة', 'type' => 'boolean', 'default' => true],
            ],
            'usage' => [['catalog_products', 'brand_id']],
        ],
        'colors' => [
            'label' => 'الألوان',
            'master' => 'catalog_colors',
            'translations' => 'catalog_color_translations',
            'fk' => 'color_id',
            'extra' => ['hex' => ['label' => 'الكود اللوني', 'type' => 'hex']],
            'usage' => [
                ['catalog_product_color', 'color_id'],
                ['catalog_product_variants', 'color_id'],
            ],
        ],
        'sizes' => [
            'label' => 'المقاسات',
            'master' => 'catalog_sizes',
            'translations' => 'catalog_size_translations',
            'fk' => 'size_id',
            'extra' => [
                'type' => ['label' => 'النوع', 'type' => 'string'],
                'sort' => ['label' => 'الترتيب', 'type' => 'integer'],
            ],
            'usage' => [['catalog_product_variants', 'size_id']],
        ],
        'materials' => [
            'label' => 'المواد',
            'master' => 'catalog_materials',
            'translations' => 'catalog_material_translations',
            'fk' => 'material_id',
            'extra' => [],
            'usage' => [
                ['catalog_product_watch_specs', 'case_material_id'],
                ['catalog_product_watch_specs', 'glass_material_id'],
                ['catalog_product_watch_specs', 'band_material_id'],
            ],
        ],
        'shapes' => [
            'label' => 'الأشكال',
            'master' => 'catalog_shapes',
            'translations' => 'catalog_shape_translations',
            'fk' => 'shape_id',
            'extra' => [],
            'usage' => [['catalog_product_watch_specs', 'case_shape_id']],
        ],
        'movements' => [
            'label' => 'أنواع الحركة',
            'master' => 'catalog_movement_types',
            'translations' => 'catalog_movement_type_translations',
            'fk' => 'movement_type_id',
            'extra' => [],
            'usage' => [['catalog_product_watch_specs', 'movement_type_id']],
        ],
        'closure_types' => [
            'label' => 'أنواع الإغلاق',
            'master' => 'catalog_closure_types',
            'translations' => 'catalog_closure_type_translations',
            'fk' => 'closure_type_id',
            'extra' => [],
            'usage' => [['catalog_product_watch_specs', 'band_closure_id']],
        ],
        'display_types' => [
            'label' => 'أنواع العرض',
            'master' => 'catalog_display_types',
            'translations' => 'catalog_display_type_translations',
            'fk' => 'display_type_id',
            'extra' => [],
            'usage' => [['catalog_product_watch_specs', 'dial_display_type_id']],
        ],
        'units' => [
            'label' => 'الوحدات',
            'master' => 'catalog_units',
            'translations' => 'catalog_unit_translations',
            'fk' => 'unit_id',
            'extra' => ['code' => ['label' => 'الرمز', 'type' => 'string', 'required' => true]],
            'usage' => [
                ['catalog_product_watch_specs', 'case_size_unit_id'],
                ['catalog_product_watch_specs', 'case_thickness_unit_id'],
                ['catalog_product_watch_specs', 'band_length_unit_id'],
                ['catalog_product_watch_specs', 'band_width_unit_id'],
                ['catalog_product_watch_specs', 'water_resistance_unit_id'],
                ['catalog_product_watch_specs', 'height_unit_id'],
                ['catalog_product_watch_specs', 'width_unit_id'],
                ['catalog_product_watch_specs', 'length_unit_id'],
            ],
        ],
        'genders' => [
            'label' => 'الفئات',
            'master' => 'catalog_genders',
            'translations' => 'catalog_gender_translations',
            'fk' => 'gender_id',
            'extra' => [],
            'usage' => [['catalog_product_gender', 'gender_id']],
        ],
        'features' => [
            'label' => 'الخصائص',
            'master' => 'catalog_features',
            'translations' => 'catalog_feature_translations',
            'fk' => 'feature_id',
            'extra' => [],
            'usage' => [['catalog_product_feature', 'feature_id']],
        ],
        'grades' => [
            'label' => 'الدرجات',
            'master' => 'catalog_grades',
            'translations' => 'catalog_grade_translations',
            'fk' => 'grade_id',
            'extra' => [],
            'usage' => [['catalog_products', 'grade_id']],
        ],
    ],

    // Product list page size. 25 fits a laptop screen without scrolling the header away.
    'list' => [
        'per_page' => 25,
        'per_page_max' => 100,
    ],
];
