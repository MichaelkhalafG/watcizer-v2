import type { useT } from '@/lib/i18n';

type Translator = ReturnType<typeof useT>;

/**
 * The words for the stored tokens the dashboard shows (item 10, 2026-09-18).
 *
 * ── The defect this file removes ─────────────────────────────────────────────────────────────
 *
 * The developer's example was the brands screen printing
 * `catalog_product_watch_specs.case_size_unit_id` at a data-entry operator, and the instruction was
 * to sweep the whole class: *"say what the number means and what the operator can do, never where
 * it comes from."*
 *
 * A survey of every Manage screen found the same shape in about thirty places, and almost all of it
 * was one of six small vocabularies: a payment provider, a payment method, a stock bucket, a product
 * family, a media type, an ability. Each already had a translation SOMEWHERE — `methodLabel()` lived
 * in `Payments/Index.tsx`, `familyLabels` in `Products/Index.tsx`, `BUCKET_LABEL` in two ledger
 * files — and the screens that showed the raw token were simply the ones that had not been given a
 * copy.
 *
 * ── Why one module and not thirty fixes ──────────────────────────────────────────────────────
 *
 * Copying each map to each screen is how this happened the first time. Three copies of the bucket
 * words already existed and a fourth screen still said `express`. One vocabulary per concept means
 * the next screen that shows a provider gets the right word by default, and the day somebody
 * renames one it is renamed once.
 *
 * ── The fallback is the raw token, ON PURPOSE ────────────────────────────────────────────────
 *
 * Every function below ends in `default: return value`. A token these maps have not heard of is
 * real data the operator needs to see — a new payment method, a family added to config — and
 * showing it verbatim is how they find out it exists. Swallowing it to an empty string or to
 * "unknown" would hide the one case worth noticing.
 */

/**
 * `express` / `market` — the two stock buckets.
 *
 * Both are real warehouse names this business uses out loud, which is why they keep their identity
 * rather than becoming "warehouse 1" and "warehouse 2".
 */
export function bucketLabel(t: Translator, bucket: string | null): string {
    switch (bucket) {
        case 'express':
            return t('common.stock_express', 'إكسبريس');
        case 'market':
            return t('common.stock_market', 'ماركت');
        default:
            return bucket ?? '—';
    }
}

/** One of the seven product families. */
export function familyLabel(t: Translator, family: string | null): string {
    switch (family) {
        case 'watch':
            return t('products.family_watch', 'ساعات');
        case 'fashion':
            return t('products.family_fashion', 'أزياء');
        case 'bag':
            return t('products.family_bag', 'حقائب');
        case 'wallet':
            return t('products.family_wallet', 'محافظ');
        case 'perfume':
            return t('products.family_perfume', 'عطور');
        case 'electronics':
            return t('products.family_electronics', 'إلكترونيات');
        case 'other':
            return t('products.family_other', 'أخرى');
        default:
            return family ?? '—';
    }
}

/**
 * A payment PROVIDER — the company, or the absence of one.
 *
 * `offline` is not a company: it is the row that says "this method takes no keys because no money
 * moves online". Naming it after what it does beats quoting the stored word.
 */
export function providerLabel(t: Translator, provider: string | null): string {
    switch (provider) {
        case 'paymob':
            // A brand name, and brand names are not translated. Returned through the seam anyway so
            // a future transliteration is a lang entry rather than an edit here.
            return t('payments.provider_paymob', 'Paymob');
        case 'cod':
            return t('payments.method_cod', 'دفع عند الاستلام');
        case 'whatsapp':
            return t('payments.method_whatsapp', 'واتساب');
        case 'offline':
            return t('payments.provider_offline', 'بدون مزوّد إلكتروني');
        default:
            return provider ?? '—';
    }
}

/** A payment METHOD — what the customer chose at checkout. */
export function methodLabel(t: Translator, method: string | null): string {
    switch (method) {
        case 'card':
            return t('payments.method_card', 'بطاقة');
        case 'valu':
            return 'valU'; // i18n-exempt: a brand name, written the way the brand writes it
        case 'tamara':
            return 'Tamara'; // i18n-exempt: a brand name
        case 'wallet':
            return t('payments.method_wallet', 'محفظة');
        case 'fawry_code':
            return t('payments.method_fawry_code', 'كود فوري');
        case 'cod':
            return t('payments.method_cod', 'دفع عند الاستلام');
        case 'cash':
            return t('payments.method_cash', 'نقدًا');
        case 'online':
            return t('payments.method_online', 'دفع إلكتروني');
        case 'whatsapp':
            return t('payments.method_whatsapp', 'واتساب');
        case 'paymob':
            /*
             * `orders.payment_method` is the LEGACY chosen-method column, and the comment that used
             * to sit beside its call site said it "holds its own vocabulary (`cod`, `cash`,
             * `online`) — the same map covers it". It did not: 7 of the 9 orders in the live
             * database carry `paymob`, so the raw fallback was the branch almost every order took
             * and the queue printed `paymob` in a column of Arabic words (D-10).
             *
             * A provider name appearing in a METHOD column is a legacy shape rather than a mistake
             * to correct in the data — the same brand name `providerLabel` returns, through the
             * same key, so the two columns cannot disagree about how to spell it.
             */
            return t('payments.provider_paymob', 'Paymob');
        default:
            return method ?? '—';
    }
}

/** Where a stock movement came from — `inventory_movements.reference_type`. */
export function referenceLabel(t: Translator, reference: string | null): string {
    switch (reference) {
        case 'orders':
            return t('inventory.reference_order', 'طلب');
        case 'inventory_movements':
            return t('inventory.reference_adjustment', 'تسوية جرد');
        case 'imports':
            return t('inventory.reference_import', 'استيراد');
        case 'transform':
            return t('inventory.reference_transform', 'بناء أولي');
        default:
            return reference ?? '—';
    }
}

/** What an image is FOR — the keys of `config/media.php`. */
export function mediaTypeLabel(t: Translator, type: string | null): string {
    switch (type) {
        case 'product':
            return t('media.type_product', 'صورة منتج');
        case 'product_gallery':
            return t('media.type_product_gallery', 'معرض منتج');
        case 'brand':
            return t('media.type_brand', 'شعار ماركة');
        case 'category':
            return t('media.type_category', 'صورة تصنيف');
        case 'banner':
            return t('media.type_banner', 'بانر');
        case 'grade':
            return t('media.type_grade', 'صورة فئة');
        default:
            return type ?? '—';
    }
}

/**
 * One ability, as a thing a person can DO rather than as a permission constant.
 *
 * Shown on the profile screen, where an operator is answering "what am I allowed to do here?" —
 * and `manage-order-fulfilment` does not answer it.
 */
export function abilityLabel(t: Translator, ability: string): string {
    switch (ability) {
        case 'view-dashboard':
            return t('abilities.view_dashboard', 'فتح لوحة التحكم');
        case 'manage-catalog':
            return t('abilities.manage_catalog', 'تعديل المنتجات والتصنيفات');
        case 'edit-category-tree':
            return t('abilities.edit_category_tree', 'تعديل شكل شجرة التصنيفات');
        case 'manage-placement':
            return t('abilities.manage_placement', 'العرض والترتيب على المتجر');
        case 'edit-product-slug':
            return t('abilities.edit_product_slug', 'تعديل روابط المنتجات');
        case 'manage-legacy-content':
            return t('abilities.manage_legacy_content', 'البانرات والمقالات');
        case 'manage-media':
            return t('abilities.manage_media', 'رفع الصور');
        case 'manage-media-prune':
            return t('abilities.manage_media_prune', 'حذف الملفات غير المستخدمة');
        case 'view-orders':
            return t('abilities.view_orders', 'قراءة الطلبات والعملاء');
        case 'manage-order-fulfilment':
            return t('abilities.manage_order_fulfilment', 'تحريك حالة الطلب');
        case 'cancel-orders':
            return t('abilities.cancel_orders', 'إلغاء الطلبات وإرجاع المخزون');
        case 'manage-inventory':
            return t('abilities.manage_inventory', 'تسوية المخزون');
        case 'manage-storefronts':
            return t('abilities.manage_storefronts', 'إعدادات المتاجر');
        case 'manage-users':
            return t('abilities.manage_users', 'المستخدمون والصلاحيات');
        case 'manage-settings':
            return t('abilities.manage_settings', 'إعدادات النظام');
        case 'manage-payments':
            return t('abilities.manage_payments', 'وسائل الدفع والتسويات');
        case 'manage-promotions':
            return t('abilities.manage_promotions', 'العروض الترويجية');
        case 'manage-shipping':
            return t('abilities.manage_shipping', 'أسعار الشحن');
        case 'export-data':
            return t('abilities.export_data', 'تنزيل البيانات كملف');
        default:
            return ability;
    }
}

/**
 * A CHANGED COLUMN, as the thing it is (item 10).
 *
 * The activity log records `{is_primary: {from: false, to: true}}`, and the screen printed the key.
 * An operator reading their own audit trail sees a column name where a fact belongs.
 *
 * Only the columns this log actually writes are listed — the writers name their fields explicitly,
 * so this is a closed set in practice rather than every column in the schema. Anything else falls
 * through verbatim, which is how a newly audited field announces itself instead of hiding.
 */
export function changedFieldLabel(t: Translator, field: string): string {
    switch (field) {
        case 'is_visible':
            return t('common.visible', 'ظاهر');
        case 'is_featured':
            return t('placement.featured', 'مميّز');
        case 'is_active':
            return t('common.active', 'مفعّل');
        case 'is_primary':
            return t('products.primary_category', 'التصنيف الأساسي');
        case 'sort_order':
            return t('common.sort', 'الترتيب');
        case 'slug':
            return t('common.slug', 'الرابط (slug)');
        case 'type_stock':
            return t('orders.bucket', 'المخزن');
        case 'status':
            return t('common.status', 'الحالة');
        case 'credentials':
            return t('payments.credentials', 'مفاتيح المزوّد');
        case 'is_enabled':
            return t('common.enabled', 'مُفعّل');
        case 'show_in_menu':
            return t('categories.in_menu', 'في القائمة');
        case 'name':
            return t('common.name', 'الاسم');
        case 'price':
        case 'selling_price':
            return t('common.price', 'السعر');
        case 'quantity':
            return t('common.quantity', 'الكمية');

        /*
         * ── D-18, 2026-09-19: the gap was measured, not guessed ─────────────────────────────
         *
         * Over the whole `core_activity_log`: 36 change entries across 7 distinct keys, and **29
         * of the 36 showed a raw column name**. The map covered 14 columns and missed the four the
         * shop floor actually generates — `role` and `user_id` (10 entries), `express` (3) and
         * `model_number`.
         *
         * The cases below are not a guess at what might appear either: they are every column in
         * the field sets that are diffed into this log — `ProductWriter::scalarColumns()`,
         * `CategoryController::categoryFields()`, the two placement snapshots, the promotion
         * snapshot, the payment-method snapshot and the role grant.
         *
         * The `default` still returns the raw name, and that is deliberate and unchanged: a
         * column this map has not heard of is a NEW audited field, and seeing it verbatim is how
         * anybody finds out it exists. The bug was never the fallback — it was relying on it.
         */
        case 'role':
            return t('users.role', 'الصلاحية');
        case 'user_id':
            return t('common.user', 'المستخدم');
        case 'express':
        case 'stock_express':
            return t('common.stock_express', 'إكسبريس');
        case 'market':
        case 'stock_market':
            return t('common.stock_market', 'ماركت');
        case 'model_number':
            return t('products.model_number', 'رقم الموديل');
        case 'wa_code':
            return t('products.code', 'الكود');
        case 'sku':
            return t('products.supplier_code', 'كود المورّد');
        case 'brand_id':
            return t('products.brand', 'الماركة');
        case 'grade_id':
            return t('products.grade', 'الدرجة');
        case 'family':
            return t('products.family', 'العائلة');
        case 'currency':
            return t('products.currency', 'العملة');
        case 'purchase_price':
            return t('products.purchase_price', 'سعر الشراء');
        case 'sale_price':
            return t('products.sale_price', 'سعر التخفيض');
        case 'low_stock_threshold':
            return t('inventory.threshold', 'حد التنبيه');
        case 'warranty_years':
            return t('products.warranty_years', 'سنوات الضمان');
        case 'hs_code':
            return t('products.hs_code', 'كود HS');
        case 'search_keywords':
            return t('products.search_keywords', 'كلمات البحث');
        case 'specs':
            return t('products.specs', 'المواصفات');
        /*
         * These four reuse an existing key, so their Arabic is copied EXACTLY from the screen that
         * already owns it. One key may not carry two different Arabic strings — `t()` returns one
         * English string per key, so the screen that loses gets English that does not match the
         * Arabic beside it, and no test reading a single file can see it. `TranslationCoverageTest`
         * checks this and it caught all four of these before they shipped.
         */
        case 'parent_id':
            return t('categories.parent', 'الأب في الشجرة');
        case 'priority':
            return t('promotions.priority', 'الأولوية (الأعلى تفوز)');
        case 'starts_at':
            return t('promotions.starts', 'يبدأ');
        case 'ends_at':
            return t('promotions.ends', 'ينتهي');
        case 'method':
            return t('payments.method_column', 'طريقة الدفع');
        case 'integration_id':
            return t('payments.integration_id', 'رقم العملية لدى المزوّد');
        case 'icon':
            return t('common.icon', 'الأيقونة');
        case 'sort':
            return t('common.sort', 'الترتيب');
        case 'nodes_reordered':
            return t('categories.nodes_reordered', 'تصنيفات أُعيد ترتيبها');
        default:
            return field;
    }
}
