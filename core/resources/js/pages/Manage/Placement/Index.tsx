import { router, usePage } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { DataTable, type Column } from '@/components/table/DataTable';
import ManageLayout from '@/layouts/ManageLayout';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import type { PreSwitchState, SharedProps, TablePayload } from '@/types';
import { Ltr } from '@/components/ui/bidi';
import { ProductName } from '@/components/manage/ProductName';
import { useLocale, useT } from '@/lib/i18n';
import { titleOrCode } from '@/lib/title';

interface PlacementRow {
    product_id: number;
    wa_code: string;
    title: { ar: string; en: string };
    has_arabic: boolean;
    is_visible: boolean;
    is_featured: boolean;
    sort_order: number;
    slug: string;
    primary_category_id: number | null;
    placements: number;
    /** Live carts holding this product. Hiding it is allowed; doing it silently is not. */
    in_carts: number;
    selling_price: string;
    cover: string | null;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: Array<{ value: string; label: string }>;
    categories: Array<{ value: string; label: string }>;
    table: TablePayload<PlacementRow>;
    slug_warning: string;
    /** Whether slug editing is open yet, and the reason when it is not. */
    slug_lock: PreSwitchState;
    /**
     * Whether THIS operator may type a slug (item 6) — a different rule from `slug_lock`.
     *
     * `slug_lock` is about the calendar and is the same for everybody; this is about the person and
     * is the same on any day. Kept apart so the screen can say which one is stopping the field.
     */
    slug_role: { allowed: boolean; message: string | null };
    /** The pre-switch banner, worded for what THIS screen loses. */
}

/**
 * /manage/storefronts/{id}/placement — one storefront, many products (scope item 5).
 *
 * The product form edits one product's placement while you are editing that product. This is the
 * other direction: the sweep the team does before a season — hide these, feature those, fix the
 * ones with no primary category, re-order a shelf.
 *
 * Three things it refuses to let the team get wrong, each with the reason ON the row:
 *
 *  • **No Arabic → cannot be visible.** Fallback is off (AGENTS §2.17), so publishing without
 *    Arabic ships a hole to an Arabic-first shop. The switch is disabled and the badge says why.
 *  • **No primary category.** The DB allows a product with placements and no primary; the read
 *    layer then picks one by "primary → deepest → lowest id", which is deterministic but not what
 *    anybody chose. The row flags it so somebody chooses.
 *  • **A slug edit is a URL change.** The warning is above the table, and the save writes the 301.
 *
 * Bulk show/hide REPORTS what it skipped. A bulk action that silently dropped half a selection is
 * how a team finds out in October that twelve products were never published.
 */
export default function PlacementIndex({
    storefront,
    storefronts,
    categories,
    table,
    slug_warning,
    slug_lock,
    slug_role,
}: Props) {
    const t = useT();
    const locale = useLocale();
    const { errors } = usePage<SharedProps>().props;
    const [slugs, setSlugs] = useState<Record<number, string>>({});
    const [sorts, setSorts] = useState<Record<number, string>>({});
    /** The row whose hide is waiting for a confirmation, because customers hold it in a cart. */
    const [hiding, setHiding] = useState<PlacementRow | null>(null);
    /**
     * The BULK hide waiting for a confirmation (C-GUARD-1).
     *
     * Hiding one product that sits in a cart has always asked first. Hiding a whole page of them
     * asked nothing at all — `router.post` fired on the click, with no count, no cart check and no
     * undo. Measured by the review: 200 products went from visible to hidden on a live storefront in
     * one request. The blast radius is bounded (selection is per page, and paging clears it) but a
     * page is 25 products, or 100 if somebody raised the page size.
     */
    const [bulkHiding, setBulkHiding] = useState<{ ids: Array<string | number>; clear: () => void } | null>(null);
    // The bulk category picker (W-1). Empty until chosen: both buttons stay disabled, so the
    // action cannot fire against "whatever was first in the list" the way a defaulted select would.
    const [bulkCategory, setBulkCategory] = useState('');
    const [bulkPrimary, setBulkPrimary] = useState(false);

    const base = `/manage/storefronts/${storefront.id}/placement`;

    const save = (row: PlacementRow, overrides: Partial<Record<string, unknown>>) => {
        router.put(
            `${base}/${row.product_id}`,
            {
                is_visible: row.is_visible,
                is_featured: row.is_featured,
                sort_order: sorts[row.product_id] ?? row.sort_order,
                slug: slugs[row.product_id] ?? row.slug,
                ...overrides,
            },
            { preserveScroll: true },
        );
    };

    const columns: Array<Column<PlacementRow>> = [
        {
            key: 'cover',
            header: t('placement.image', 'صورة'),
            sortable: false,
            className: 'w-14',
            cell: (row) => (
                <div className="h-10 w-10 overflow-hidden rounded border bg-muted/40">
                    {row.cover === null ? null : <img src={row.cover} alt="" className="h-full w-full object-contain" loading="lazy" />}
                </div>
            ),
        },
        {
            key: 'p.wa_code',
            header: t('common.product', 'المنتج'),
            cell: (row) => (
                <div className="min-w-[11rem] space-y-0.5">
                    {/* Item 1b — the reader's own language, the other one marked when it stands in.
                        No second line here: the code sits under it and the cell is already narrow. */}
                    <ProductName title={row.title} secondary={false} />
                    <div className="font-mono text-[11px] text-muted-foreground">
                        <Ltr>{row.wa_code}</Ltr>
                    </div>
                    <div className="flex flex-wrap gap-1">
                        {row.has_arabic ? null : <Badge variant="destructive">{t('placement.missing_arabic', 'عربي ناقص')}</Badge>}
                        {row.placements === 0 ? <Badge variant="warning">{t('placement.unplaced', 'بلا تصنيف')}</Badge> : null}
                        {row.placements > 0 && row.primary_category_id === null ? (
                            <Badge variant="warning">{t('placement.no_primary_category', 'بلا تصنيف أساسي')}</Badge>
                        ) : null}
                    </div>
                </div>
            ),
        },
        {
            key: 'sp.is_visible',
            header: t('common.visible', 'ظاهر'),
            cell: (row) => (
                <Switch
                    aria-label={t('placement.show_product', 'إظهار :name', { name: titleOrCode(row.title, locale, row.wa_code) })}
                    checked={row.is_visible}
                    disabled={!row.has_arabic && !row.is_visible}
                    onCheckedChange={(checked) => {
                        // Turning it OFF while customers hold it in a cart asks first, with the
                        // number; everything else applies straight away (task 4.4).
                        if (!checked && row.in_carts > 0) {
                            setHiding(row);

                            return;
                        }
                        save(row, { is_visible: checked });
                    }}
                />
            ),
        },
        {
            key: 'sp.is_featured',
            header: t('placement.featured', 'مميّز'),
            cell: (row) => (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={t('placement.feature_product', 'تمييز :name', { name: titleOrCode(row.title, locale, row.wa_code) })}
                    onClick={() => save(row, { is_featured: !row.is_featured })}
                >
                    <Star className={row.is_featured ? 'h-4 w-4 fill-current text-amber-500' : 'h-4 w-4 text-muted-foreground'} />
                </Button>
            ),
        },
        {
            key: 'sp.sort_order',
            header: t('common.sort', 'الترتيب'),
            cell: (row) => (
                <Input
                    dir="ltr"
                    type="number"
                    className="w-20"
                    aria-label={t('placement.sort_for_product', 'ترتيب :name', { name: titleOrCode(row.title, locale, row.wa_code) })}
                    value={sorts[row.product_id] ?? String(row.sort_order)}
                    onChange={(event) => setSorts((current) => ({ ...current, [row.product_id]: event.target.value }))}
                    onBlur={() => {
                        if ((sorts[row.product_id] ?? String(row.sort_order)) !== String(row.sort_order)) {
                            save(row, {});
                        }
                    }}
                />
            ),
        },
        {
            key: 'slug',
            header: t('placement.slug', 'الرابط'),
            sortable: false,
            hideOnMobile: true,
            cell: (row) => (
                <Input
                    dir="ltr"
                    className="min-w-[10rem]"
                    aria-label={t('placement.slug_for_product', 'رابط :name', { name: titleOrCode(row.title, locale, row.wa_code) })}
                    /*
                     * It used to be LOCKED until the write-switch, because the 301 this promises
                     * does not survive the next rebuild and § 2.27 says a real rule belongs in the
                     * form rather than beside it (review 🟠-3).
                     *
                     * Item 5 (2026-09-18) reversed that decision deliberately, not by accident: the
                     * developer wants every gated feature exercised before the switch. The sentence
                     * did not change — the same fact now arrives as `caveat` instead of `message`
                     * and rides on the field's tooltip and the screen notice above the table.
                     */
                    /*
                     * Two rules, and the field says which one is in force (item 6). The role rule
                     * is checked first because it is the one that does not change tomorrow —
                     * telling a data-entry operator about switch night when the real answer is
                     * "ask an administrator" sends them to wait for the wrong thing.
                     */
                    disabled={slug_lock.blocked || !slug_role.allowed}
                    title={
                        (slug_role.message ??
                            slug_lock.message ??
                            slug_lock.caveat) ??
                        undefined
                    }
                    value={slugs[row.product_id] ?? row.slug}
                    onChange={(event) => setSlugs((current) => ({ ...current, [row.product_id]: event.target.value }))}
                    onBlur={() => {
                        if ((slugs[row.product_id] ?? row.slug) !== row.slug) {
                            save(row, {});
                        }
                    }}
                />
            ),
        },
        {
            key: 'p.selling_price',
            header: t('common.price', 'السعر'),
            hideOnMobile: true,
            cell: (row) => (
                <span dir="ltr" className="text-sm">
                    {row.selling_price}
                </span>
            ),
        },
    ];

    return (
        <ManageLayout
            title={t('placement.title', 'العرض والترتيب')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('placement.title', 'العرض والترتيب') },
            ]}
            actions={
                storefronts.length > 1 ? (
                    <Select
                        aria-label={t('common.storefront', 'المتجر')}
                        className="w-40"
                        value={String(storefront.id)}
                        onChange={(event) => router.get(`/manage/storefronts/${event.target.value}/placement`)}
                    >
                        {storefronts.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                ) : null
            }
        >
            <Alert tone="warning" title={t('placement.slug_warning_title', 'قبل تغيير أي رابط')}>
                {slug_warning}
            </Alert>

            {errors.slug ? (
                <Alert tone="error" title={t('placement.slug_save_failed', 'تعذّر حفظ الرابط')}>
                    {errors.slug}
                </Alert>
            ) : null}

            {errors.is_visible ? (
                <Alert tone="error" title={t('placement.show_failed', 'تعذّر الإظهار')}>
                    {errors.is_visible}
                </Alert>
            ) : null}
            {errors.bulk ? (
                <Alert tone="warning" title={t('placement.bulk_partial', 'تم التنفيذ جزئيًا')}>
                    {errors.bulk}
                </Alert>
            ) : null}

            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.product_id}
                searchPlaceholder={t('placement.search_placeholder', 'ابحث بالاسم أو الكود أو الرابط…')}
                filters={(setFilter, current) => (
                    <>
                        <Select
                            aria-label={t('placement.filter_by_category', 'تصفية بالتصنيف')}
                            className="w-44"
                            value={current.category ?? ''}
                            onChange={(event) => setFilter('category', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">{t('placement.all_categories', 'كل التصنيفات')}</option>
                            {categories.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            aria-label={t('placement.filter_by_visibility', 'تصفية بالظهور')}
                            className="w-32"
                            value={current['sp.is_visible'] ?? ''}
                            onChange={(event) => setFilter('sp.is_visible', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">{t('placement.visible_and_hidden', 'ظاهر ومخفي')}</option>
                            <option value="1">{t('common.visible', 'ظاهر')}</option>
                            <option value="0">{t('common.hidden', 'مخفي')}</option>
                        </Select>
                        <Select
                            aria-label={t('placement.quick_filter', 'تصفية سريعة')}
                            className="w-44"
                            value={current.flag ?? ''}
                            onChange={(event) => setFilter('flag', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">{t('placement.no_quick_filter', 'بلا تصفية سريعة')}</option>
                            <option value="no_arabic">{t('placement.missing_arabic', 'عربي ناقص')}</option>
                            <option value="no_primary">{t('placement.no_primary_category', 'بلا تصنيف أساسي')}</option>
                            <option value="unplaced">{t('placement.unplaced', 'بلا تصنيف')}</option>
                        </Select>
                    </>
                )}
                bulkActions={(selected, clear) => (
                    <>
                        {(
                            [
                                ['show', t('placement.bulk_show', 'إظهار')],
                                ['hide', t('placement.bulk_hide', 'إخفاء')],
                                ['feature', t('placement.bulk_feature', 'تمييز')],
                                ['unfeature', t('placement.bulk_unfeature', 'إلغاء التمييز')],
                            ] as Array<[string, string]>
                        ).map(([action, label]) => (
                            <Button
                                key={action}
                                type="button"
                                size="sm"
                                variant={action === 'hide' ? 'destructive' : 'outline'}
                                onClick={() => {
                                    /*
                                     * HIDE asks first (C-GUARD-1). It is the only one of the four
                                     * that takes products off a live shop; show, feature and
                                     * unfeature are all recoverable by clicking the same button
                                     * again, and a confirmation on every action teaches people to
                                     * click through confirmations.
                                     */
                                    if (action === 'hide') {
                                        setBulkHiding({ ids: selected, clear });

                                        return;
                                    }
                                    router.post(
                                        `${base}/bulk`,
                                        { action, product_ids: selected },
                                        { preserveScroll: true, onSuccess: clear },
                                    );
                                }}
                            >
                                {label}
                            </Button>
                        ))}

                        {/* ── Filing products into a category, in bulk (W-1) ─────────────────

                            *"The single most expensive gap in the product."* Correcting a wrong
                            category on twenty products meant twenty passes through a 7.4-screen
                            form. It is ADDITIVE by design — see PlacementController::bulkCategory()
                            — so the two buttons are "add" and "remove" rather than one "set" that
                            would silently discard categories nobody on this screen can see. */}
                        <div className="flex flex-wrap items-center gap-1.5 border-s ps-3">
                            <Select
                                aria-label={t('placement.bulk_category', 'التصنيف')}
                                className="h-8 w-48"
                                value={bulkCategory}
                                onChange={(event) => setBulkCategory(event.target.value)}
                            >
                                <option value="">
                                    {t('placement.bulk_category_choose', '— اختر تصنيفًا —')}
                                </option>
                                {categories.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            <label className="flex items-center gap-1 text-xs">
                                <input
                                    type="checkbox"
                                    className="h-3.5 w-3.5"
                                    checked={bulkPrimary}
                                    onChange={(event) => setBulkPrimary(event.target.checked)}
                                />
                                {t('placement.bulk_make_primary', 'اجعله الأساسي')}
                            </label>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={bulkCategory === ''}
                                onClick={() =>
                                    router.post(
                                        `${base}/bulk`,
                                        {
                                            action: 'set_category',
                                            product_ids: selected,
                                            category_id: Number(bulkCategory),
                                            make_primary: bulkPrimary,
                                        },
                                        { preserveScroll: true, onSuccess: clear },
                                    )
                                }
                            >
                                {t('placement.bulk_category_add', 'أضِف التصنيف')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={bulkCategory === ''}
                                onClick={() =>
                                    router.post(
                                        `${base}/bulk`,
                                        {
                                            action: 'clear_category',
                                            product_ids: selected,
                                            category_id: Number(bulkCategory),
                                        },
                                        { preserveScroll: true, onSuccess: clear },
                                    )
                                }
                            >
                                {t('placement.bulk_category_remove', 'احذف التصنيف')}
                            </Button>
                        </div>
                    </>
                )}
                emptyTitle={t('placement.empty_title', 'لا توجد منتجات على هذا المتجر')}
                emptyDescription={t('placement.empty_description', 'أضف منتجات من شاشة المنتجات، أو عدّل التصفية.')}
            />
            {/* ── hiding a product that customers are holding (task 4.4) ────────────────── */}
            <Dialog open={hiding !== null} onOpenChange={(open) => (open ? null : setHiding(null))}>
                {hiding === null ? null : (
                    <DialogContent
                        title={t('placement.hide_title', 'إخفاء «:name» عن :storefront', {
                            name: titleOrCode(hiding.title, locale, hiding.wa_code),
                            storefront: storefront.name,
                        })}
                    >
                        <div className="space-y-3 text-sm text-muted-foreground">
                            <p>
                                {/* The cart count stays a bold run inside the sentence, so the key
                                    holds the whole sentence with a `:count` placeholder. */}
                                <Around
                                    text={t(
                                        'placement.hide_in_carts',
                                        'هذا المنتج موجود الآن في :count سلة مفتوحة. بعد الإخفاء لن يظهر في القوائم ولا في البحث، ومن يفتح سلته لن يتمكن من إتمام شرائه.',
                                    )}
                                    placeholder=":count"
                                >
                                    <strong>{hiding.in_carts}</strong>
                                </Around>
                            </p>
                            <p>
                                {t(
                                    'placement.hide_reassurance',
                                    'الإخفاء لا يحذف المنتج ولا يمس مخزونه ولا طلباته السابقة، ويمكن إرجاعه في أي وقت. لو كان السبب نفاد الكمية، الأفضل تركه ظاهرًا — المتجر يكتب «نفد» وحده.',
                                )}
                            </p>
                        </div>
                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setHiding(null)}>
                                {t('common.cancel', 'إلغاء')}
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => {
                                    const row = hiding;
                                    setHiding(null);
                                    save(row, { is_visible: false });
                                }}
                            >
                                {t('placement.hide_confirm', 'أخفِ المنتج')}
                            </Button>
                        </div>
                    </DialogContent>
                )}
            </Dialog>

            {/* ── hiding a WHOLE SELECTION (C-GUARD-1) ──────────────────────────────────── */}
            <Dialog open={bulkHiding !== null} onOpenChange={(open) => (open ? null : setBulkHiding(null))}>
                {bulkHiding === null ? null : (() => {
                    /*
                     * The cart numbers come from the ROWS ON SCREEN, with no extra request.
                     *
                     * That is exact rather than convenient: selection is per page and paging clears
                     * it (the reasoning is in DataTable.tsx), so every selected id is a row this
                     * component already holds, `in_carts` and all. A round trip to re-derive numbers
                     * already in memory would be slower and could disagree with what the operator is
                     * looking at.
                     */
                    const chosen = new Set(bulkHiding.ids.map((id) => Number(id)));
                    const rows = table.data.filter((row) => chosen.has(row.product_id));
                    const inCarts = rows.filter((row) => row.in_carts > 0);
                    const carts = inCarts.reduce((total, row) => total + row.in_carts, 0);

                    return (
                        <DialogContent
                            title={t('placement.bulk_hide_title', 'إخفاء :count منتجًا عن :storefront', {
                                count: bulkHiding.ids.length,
                                storefront: storefront.name,
                            })}
                        >
                            <div className="space-y-3 text-sm text-muted-foreground">
                                <p>
                                    {t(
                                        'placement.bulk_hide_body',
                                        'سيختفي :count منتجًا من القوائم ومن البحث على هذا المتجر. الإخفاء لا يحذف شيئًا ولا يمس المخزون أو الطلبات، ويمكن إرجاعه — لكنه يطبّق على المحدد كله دفعة واحدة.',
                                        { count: bulkHiding.ids.length },
                                    )}
                                </p>
                                {carts === 0 ? null : (
                                    <p className="font-medium text-foreground">
                                        {/* The number that decides whether this is safe, stated
                                            separately because it is the one a person acts on. */}
                                        {t(
                                            'placement.bulk_hide_in_carts',
                                            'من بينها :products منتجًا موجودة الآن في :carts سلة مفتوحة، ومن يفتح سلته لن يتمكن من إتمام شرائه.',
                                            { products: inCarts.length, carts },
                                        )}
                                    </p>
                                )}
                            </div>
                            <div className="mt-4 flex flex-wrap justify-end gap-2">
                                <Button type="button" variant="outline" onClick={() => setBulkHiding(null)}>
                                    {t('common.cancel', 'إلغاء')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={() => {
                                        const { ids, clear } = bulkHiding;
                                        setBulkHiding(null);
                                        router.post(
                                            `${base}/bulk`,
                                            { action: 'hide', product_ids: ids },
                                            { preserveScroll: true, onSuccess: clear },
                                        );
                                    }}
                                >
                                    {t('placement.bulk_hide_confirm', 'أخفِ المحدد')}
                                </Button>
                            </div>
                        </DialogContent>
                    );
                })()}
            </Dialog>

        </ManageLayout>
    );
}

/**
 * Render `text` with `children` substituted for its `:placeholder`.
 *
 * A sentence that wraps one fragment in markup would otherwise have to be split into two keys, and
 * a translator handed two halves cannot reorder them — which is exactly what Arabic → English
 * needs to do. So the key stays ONE sentence carrying a Laravel-style `:name` placeholder, and the
 * substitution happens here instead of in `t()`, because the value is an element and not a string.
 */
function Around({ text, placeholder, children }: { text: string; placeholder: string; children: ReactNode }) {
    const [before, ...rest] = text.split(placeholder);

    return (
        <>
            {before}
            {rest.length === 0 ? null : children}
            {rest.join(placeholder)}
        </>
    );
}
