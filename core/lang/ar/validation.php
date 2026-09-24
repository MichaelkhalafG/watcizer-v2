<?php

/*
|--------------------------------------------------------------------------
| Validation messages, in ARABIC (🟠-3, 2026-09-17)
|--------------------------------------------------------------------------
|
| ── Why this file has to exist, unlike `lang/ar/manage.php` ──────────────
|
| `manage.php` is a deliberate empty stub: every dashboard string is written
| `t('key', 'العربية')`, so the Arabic lives at the call site and there is
| nothing to put here. Validation is the opposite shape. Laravel's own
| messages have no call site of ours to carry a fallback, so without this
| file the translator falls through to `vendor/.../lang/en/validation.php`
| and an ARABIC operator is refused in ENGLISH:
|
|     "The selling price field must be a number."
|
| …on a right-to-left screen where every other word is Arabic. The dashboard
| is bilingual everywhere else in wave 4D; this was the one path where the
| language still came from Laravel rather than from the operator.
|
| ── `attributes` is the half that actually matters ───────────────────────
|
| Without it the messages are Arabic but the FIELD NAMES are the column
| names: «حقل selling_price مطلوب». The map below is built from the field
| names the dashboard really validates — collected from the `validate()`
| calls in `app/Http/Controllers/Manage`, not invented — so a refusal names
| the box the operator is looking at.
|
| A field with no entry here degrades to its snake_case name with the
| underscores replaced, which is Laravel's own behaviour and is readable
| enough for a field nobody has met yet. Adding one is one line.
|
*/

return [

    // ── the rules ───────────────────────────────────────────────────────
    'accepted' => 'يجب قبول :attribute.',
    'active_url' => ':attribute ليس رابطًا صحيحًا.',
    'after' => 'يجب أن يكون :attribute تاريخًا بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخًا بعد أو يساوي :date.',
    'alpha' => 'يجب أن يحتوي :attribute على حروف فقط.',
    'alpha_dash' => 'يجب أن يحتوي :attribute على حروف وأرقام وشرطات فقط.',
    'alpha_num' => 'يجب أن يحتوي :attribute على حروف وأرقام فقط.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'before' => 'يجب أن يكون :attribute تاريخًا قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخًا قبل أو يساوي :date.',
    'between' => [
        'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و :max.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و :max حرفًا.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute صح أو خطأ.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخًا صحيحًا.',
    'date_equals' => 'يجب أن يكون :attribute تاريخًا يساوي :date.',
    'date_format' => ':attribute لا يطابق الصيغة :format.',
    'declined' => 'يجب رفض :attribute.',
    'different' => 'يجب أن يكون :attribute مختلفًا عن :other.',
    'digits' => 'يجب أن يتكوّن :attribute من :digits رقمًا.',
    'digits_between' => 'يجب أن يتكوّن :attribute من عدد أرقام بين :min و :max.',
    'dimensions' => 'أبعاد صورة :attribute غير صحيحة.',
    'distinct' => 'قيمة :attribute مكرّرة.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًا صحيحًا.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد التالي: :values.',
    'exists' => ':attribute المحدد غير موجود.',
    'file' => 'يجب أن يكون :attribute ملفًا.',
    'filled' => 'حقل :attribute مطلوب.',
    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول :attribute أكبر من :value حرفًا.',
    ],
    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عنصرًا أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكبر.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أكبر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفًا أو أكثر.',
    ],
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => ':attribute المحدد غير صحيح.',
    'in_array' => ':attribute غير موجود في :other.',
    'integer' => 'يجب أن يكون :attribute رقمًا صحيحًا.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صحيحًا.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صحيحًا.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صحيحًا.',
    'json' => 'يجب أن يكون :attribute نص JSON صحيحًا.',
    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute أقل من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أقل من :value.',
        'string' => 'يجب أن يكون طول :attribute أقل من :value حرفًا.',
    ],
    'lte' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أقل.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أقل.',
        'string' => 'يجب أن يكون طول :attribute :value حرفًا أو أقل.',
    ],
    'max' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصرًا.',
        'file' => 'يجب ألا يزيد حجم :attribute عن :max كيلوبايت.',
        'numeric' => 'يجب ألا تزيد قيمة :attribute عن :max.',
        'string' => 'يجب ألا يزيد طول :attribute عن :max حرفًا.',
    ],
    'mimes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصرًا على الأقل.',
        'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'string' => 'يجب ألا يقل طول :attribute عن :min حرفًا.',
    ],
    'not_in' => ':attribute المحدد غير صحيح.',
    'not_regex' => 'صيغة :attribute غير صحيحة.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'present' => 'يجب أن يكون :attribute موجودًا.',
    'prohibited' => 'حقل :attribute ممنوع.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_unless' => 'حقل :attribute مطلوب ما لم يكن :other ضمن :values.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without' => 'حقل :attribute مطلوب عند عدم وجود :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند عدم وجود أيٍّ من :values.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',
    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute :size.',
        'string' => 'يجب أن يكون طول :attribute :size حرفًا.',
    ],
    'starts_with' => 'يجب أن يبدأ :attribute بأحد التالي: :values.',
    'string' => 'يجب أن يكون :attribute نصًا.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صحيحة.',
    'unique' => 'قيمة :attribute مستخدمة من قبل.',
    'uploaded' => 'فشل رفع :attribute.',
    'url' => 'صيغة رابط :attribute غير صحيحة.',
    'uuid' => 'يجب أن يكون :attribute معرّف UUID صحيحًا.',

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | The field names, as the screen calls them
    |--------------------------------------------------------------------------
    |
    | Collected from the `validate()` calls in app/Http/Controllers/Manage —
    | every name below is one the dashboard really refuses on. Without these a
    | message reads «حقل selling_price مطلوب», which names a database column
    | rather than the box the operator is looking at.
    |
    */
    'attributes' => [
        // identity and naming
        'name' => 'الاسم',
        'title' => 'العنوان',
        'label' => 'الاسم',
        'slug' => 'الرابط',
        'domain' => 'النطاق',
        'icon' => 'الأيقونة',
        'type' => 'النوع',
        'action' => 'الإجراء',

        // catalogue
        'wa_code' => 'كود watchizer',
        'sku' => 'كود المورّد',
        'model_number' => 'رقم الموديل',
        'model_name' => 'اسم الموديل',
        'brand_id' => 'الماركة',
        'grade_id' => 'الدرجة',
        'color_id' => 'اللون',
        'colors' => 'الألوان',
        'size_id' => 'المقاس',
        'gender_ids' => 'الفئة',
        'feature_ids' => 'الخصائص',
        'category_ids' => 'التصنيفات',
        'primary_category_id' => 'التصنيف الأساسي',
        'storefront_category_id' => 'التصنيف',
        'parent_id' => 'التصنيف الأب',
        'product_id' => 'المنتج',
        'product_ids' => 'المنتجات',
        'variant_id' => 'المقاس/اللون',
        'country' => 'بلد المنشأ',
        'stone' => 'الحجر',
        'hs_code' => 'كود HS',
        'warranty_years' => 'الضمان (سنوات)',
        'short_description' => 'الوصف المختصر',
        'long_description' => 'الوصف الكامل',
        'meta_title' => 'عنوان SEO',
        'meta_description' => 'وصف SEO',
        'search_keywords' => 'كلمات البحث',

        // money and stock
        'selling_price' => 'سعر البيع',
        'sale_price' => 'سعر التخفيض',
        'purchase_price' => 'سعر الشراء',
        'price_delta' => 'فرق السعر',
        'currency' => 'العملة',
        'quantity' => 'الكمية',
        'stock_express' => 'مخزون إكسبريس',
        'stock_market' => 'مخزون ماركت',
        'low_stock_threshold' => 'حد التنبيه',
        'bucket' => 'المخزن',
        'mode' => 'نوع التعديل',
        'reason' => 'السبب',
        'note' => 'ملاحظة',

        // visibility and ordering
        'is_active' => 'التفعيل',
        'is_visible' => 'الظهور',
        'is_featured' => 'التمييز',
        'is_enabled' => 'التفعيل',
        'show_in_menu' => 'الظهور في القائمة',
        'sort' => 'الترتيب',
        'sort_order' => 'الترتيب',
        'priority' => 'الأولوية',

        // storefronts, locales, access
        'storefront_id' => 'المتجر',
        'storefronts' => 'المتاجر',
        'locale' => 'اللغة',
        'locales' => 'اللغات',
        'default_locale' => 'اللغة الافتراضية',
        'money_rewards' => 'السماح بالخصومات',
        'email' => 'البريد الإلكتروني',
        'role' => 'الصلاحية',

        // orders and payment
        'status' => 'الحالة',
        'payment_method' => 'طريقة الدفع',
        'provider' => 'مزوّد الدفع',
        'method' => 'طريقة الدفع',
        'integration_id' => 'رقم التكامل',
        'credentials' => 'المفاتيح',
        'storefront_payment_provider_id' => 'عقد الدفع',

        // promotions
        'starts_at' => 'تاريخ البداية',
        'ends_at' => 'تاريخ الانتهاء',
        'conditions' => 'الشروط',
        'rewards' => 'المكافآت',
        'lines' => 'السطور',
        'into' => 'الوحدة الهدف',

        // media and content
        'image_path' => 'الصورة',
        'images' => 'الصور',
        'link_url' => 'الرابط',
        'target' => 'الوجهة',

        // filters and misc
        'from' => 'من تاريخ',
        'to' => 'إلى تاريخ',
        'ids' => 'العناصر',
    ],
];
