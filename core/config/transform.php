<?php

/*
|--------------------------------------------------------------------------
| core:transform — legacy → clean-core transform settings (CLEAN_CORE_STUDY §2.9)
|--------------------------------------------------------------------------
| Every knob the transform reads lives here so the switch-night run and the
| weekly rehearsal are configured the same way. Nothing here is a secret.
*/

return [

    // Rows per chunked legacy read and per batched clean-table upsert.
    'chunk' => (int) env('TRANSFORM_CHUNK', 500),

    // Root of the legacy image tree (Uploads_Images/) used by audits A-11/A-12.
    // Relative paths resolve from the core app's base path. The local workstation
    // holds a PARTIAL copy; the audit reports how many files it could see.
    'images_root' => env('TRANSFORM_IMAGES_ROOT', '../backend/public/Uploads_Images'),

    // Where each run writes summary.json / audit.csv / audit.md / diff.csv /
    // reconciliation.md (under storage_path()).
    'output_dir' => env('TRANSFORM_OUTPUT_DIR', 'transform'),

    /*
    | Deterministic id allocation for rows that have NO legacy id to preserve.
    | catalog_product_images preserves product_images.id for gallery rows, so
    | the synthetic cover rows (from products.image) are placed at
    | COVER_ID_OFFSET + product id — far above any legacy gallery id. The
    | transform aborts (tripwire) if a legacy id ever reaches the offset.
    */
    'cover_image_id_offset' => 1_000_000,

    // new_colors rows that are NOT byte-identical to a legacy colors row get
    // catalog_colors.id = offset + new_colors.id (legacy colors ids are preserved).
    'new_color_id_offset' => 1_000,

    /*
    | Product family derivation (study §2.2 / §2.9.2 step 6), evaluated in order:
    |   1. category type EN name in `watch_category_type_names`   → 'watch'
    |   2. extra_attributes JSON keys matching a prefix            → mapped family
    |   3. sub type EN name in `sub_type_names`                    → mapped family
    |   4. otherwise                                               → `default`
    */
    'family' => [
        'watch_category_type_names' => ['watches'],
        'extra_attribute_prefixes' => [
            'perfume_' => 'perfume',
            'elec_' => 'electronics',
            'wallet_' => 'wallet',
            'bag_' => 'bag',
            'strap_length_cm' => 'bag',
        ],
        'sub_type_names' => [
            'bags' => 'bag',
            'wallets' => 'wallet',
            'perfumes' => 'perfume',
        ],
        'default' => 'fashion',

        /*
        | Best-effort word lists for audit code A-27 (WARNING, never blocking): they only decide
        | whether a PIN in `orphan_sub_type_parents` deserves a second look, never where anything
        | is placed. A name matching both lists (or neither) is treated as ambiguous and is not
        | reported. Substring match on the lower-cased EN and AR names.
        */
        'watch_sub_type_name_hints' => ['watch', 'automatic', 'chronograph', 'chronometer', 'diver', 'pilot', 'gmt', 'tourbillon', 'skeleton', 'moonphase', 'quartz', 'mechanical', 'ساعة', 'ساعات', 'اوتوماتيك', 'أوتوماتيك'],
        'fashion_sub_type_name_hints' => ['bag', 'wallet', 'perfume', 'cap', 'belt', 'sunglass', 'scarf', 'keychain', 'cufflink', 'jewel', 'bracelet', 'shoe', 'حقيبة', 'حقائب', 'محفظة', 'عطر', 'حزام', 'أحزمة', 'نظارة', 'مجوهرات'],
    ],

    /*
    | Step 16: a legacy sub type with no products is mirrored under the category
    | type "used by most other sub types" (study §2.9.2). Put an explicit
    | sub_type_id => category_type_id pair here to override that rule once the
    | team has confirmed the placement (audit A-15 lists the affected sub types).
    */
    // Pinned 2026-09-06 (developer decision after rehearsal #1, audit A-15): watch-natured
    // sub types under Watches (1), the rest under Fashion (2). A sub type that gains products
    // later is placed by its real (type, sub type) pair and this map stops mattering for it.
    // Corrected 2026-09-08 against the fresh production dump (rehearsal #2). The team's only new
    // sub type is id 29 `Automatic` (اوتوماتيك) — a watch complication, so it is pinned to Watches.
    // The earlier 28/30 pins were written against a previous session's placeholder ids and pointed
    // at sub types that do not exist (28 was created and deleted by the team; 30 was never used);
    // they are gone, and A-26 now BLOCKS on such a phantom pin instead of ignoring it.
    // A-26 also BLOCKS any orphan sub type missing from this map — a new sub type is never placed
    // by the majority rule, which cannot read what the name means (and whose answer flipped from
    // Fashion to Watches between rehearsals #1 and #2). A-27 warns when a pin looks inconsistent
    // with the sub type's name.
    'orphan_sub_type_parents' => [
        1 => 1,   // Diver
        3 => 1,   // Dress
        5 => 1,   // Pilot
        6 => 1,   // GMT
        7 => 1,   // Field
        8 => 1,   // Racing
        9 => 1,   // Skeleton
        10 => 1,  // Tourbillon
        11 => 1,  // Military
        12 => 1,  // Casual
        13 => 1,  // Smart Watch
        14 => 1,  // Digital Watch
        15 => 2,  // Caps
        19 => 2,  // Perfumes
        20 => 2,  // Sunglasses
        23 => 2,  // Scarves
        24 => 2,  // Keychains
        25 => 2,  // Ties
        26 => 2,  // Cufflinks
        27 => 2,  // Pen
        29 => 1,  // Automatic (اوتوماتيك) — watch complication, verified in the 2026-09-08 dump
    ],

    // Hidden root that holds the dormant legacy `categories` tree (step 17).
    'legacy_tree_root' => [
        'slug' => 'legacy-tree',
        'name_en' => 'Legacy category tree',
        'name_ar' => 'شجرة التصنيفات القديمة',
    ],

];
