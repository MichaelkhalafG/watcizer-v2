import { Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/input';
import { DataTable, type Column } from '@/components/table/DataTable';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import type { TablePayload } from '@/types';
import { Ltr } from '@/components/ui/bidi';

/**
 * The promotions list (wave 4D).
 *
 * ── Its whole job is the STATE column ────────────────────────────────────────────────────────
 *
 * The developer's requirement: *an admin should never have to open a rule to know whether it's
 * doing anything.* `is_active` is what somebody TYPED; the state is what the shop is DOING. A rule
 * can be switched on, inside its window, and give away nothing — because the gift is out of stock,
 * because it is on no storefront, or because the gift is not visible where the rule runs. None of
 * that shows in the rule's own columns.
 *
 * Beside the state sit the two things that explain it, and they are shaped differently on purpose:
 * the STOCK miss count is one figure for the whole rule, because stock is one shared pool; the
 * VISIBILITY problems are listed per storefront, because visibility is a per-storefront column.
 * Rendering the stock one per storefront would be a lie dressed as detail.
 */

interface StorefrontState {
    id: number;
    name: string;
    storefront_active: boolean;
    reward_visible: boolean;
}

interface Skips {
    /** Aggregate — stock is ONE pool, so one figure for the rule. */
    stock: number;
    /** Per storefront — visibility is a per-storefront column. */
    visibility: Record<number, number>;
    last_at: string | null;
}

interface Rule {
    id: number;
    name: string;
    priority: number;
    is_active: boolean;
    starts_at: string | null;
    ends_at: string | null;
    summary: string;
    state: string;
    label: string;
    tone: string;
    storefronts: StorefrontState[];
    skips: Skips;
    /** How many orders the gift's stock would still cover, or null when there is nothing to count. */
    reward_stock: number | null;
    edit_url: string;
}

interface Props {
    table: TablePayload<Rule>;
    storefronts: { id: number; name: string }[];
    stacking_notice: string;
}

const TONE: Record<string, 'default' | 'neutral' | 'success' | 'warning' | 'destructive' | 'outline'> = {
    success: 'success',
    default: 'default',
    neutral: 'neutral',
    destructive: 'destructive',
    outline: 'outline',
};

export default function PromotionsIndex({ table, storefronts, stacking_notice }: Props) {
    const t = useT();

    const columns: Array<Column<Rule>> = [
        {
            key: 'r.name',
            header: t('promotions.list_promotion', 'العرض'),
            sortable: true,
            cell: (row) => (
                <div className="space-y-0.5">
                    <Link href={row.edit_url} className="font-medium hover:underline">
                        {row.name}
                    </Link>
                    <div className="text-xs text-muted-foreground">{row.summary}</div>
                </div>
            ),
        },
        {
            // THE column this screen exists for.
            key: 'state',
            header: t('promotions.list_state', 'ماذا تفعل الآن'),
            cell: (row) => (
                <div className="space-y-1">
                    <Badge variant={TONE[row.tone] ?? 'outline'}>{row.label}</Badge>

                    {row.skips.stock > 0 ? (
                        <div className="text-xs text-destructive">
                            {t('promotions.gift_missed', 'فات العميل الهدية :count مرة لعدم توفر المخزون', {
                                count: row.skips.stock,
                            })}
                        </div>
                    ) : null}

                    {row.storefronts
                        .filter((storefront) => !storefront.reward_visible)
                        .map((storefront) => (
                            // Two WHOLE sentences rather than one plus a conditional tail. The tail
                            // alone ("— missed :count times") is a fragment a translator cannot
                            // place, and in English it would have to move to the front.
                            <div key={storefront.id} className="text-xs text-destructive">
                                {row.skips.visibility[storefront.id]
                                    ? t(
                                          'promotions.list_reward_hidden_missed',
                                          'الهدية غير معروضة على :storefront — فاتت :count مرة',
                                          {
                                              storefront: storefront.name,
                                              count: row.skips.visibility[storefront.id],
                                          },
                                      )
                                    : t('promotions.list_reward_hidden', 'الهدية غير معروضة على :storefront', {
                                          storefront: storefront.name,
                                      })}
                            </div>
                        ))}

                    {row.state === 'running' && row.reward_stock !== null && row.reward_stock > 0 ? (
                        <div className="text-xs text-muted-foreground">
                            {t('promotions.list_stock_covers', 'المخزون يكفي :count طلب', {
                                count: row.reward_stock,
                            })}
                        </div>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'storefronts',
            header: t('common.storefronts', 'المتاجر'),
            hideOnMobile: true,
            cell: (row) =>
                row.storefronts.length === 0 ? (
                    <span className="text-xs text-destructive">
                        {t('promotions.list_no_storefronts', 'لا يوجد')}
                    </span>
                ) : (
                    <div className="flex flex-wrap gap-1">
                        {row.storefronts.map((storefront) => (
                            <Badge key={storefront.id} variant={storefront.reward_visible ? 'outline' : 'destructive'}>
                                {storefront.name}
                            </Badge>
                        ))}
                    </div>
                ),
        },
        {
            key: 'r.priority',
            header: t('promotions.list_priority', 'الأولوية'),
            sortable: true,
            cell: (row) => <span dir="ltr">{row.priority}</span>,
        },
        {
            key: 'r.ends_at',
            header: t('promotions.list_period', 'الفترة'),
            sortable: true,
            hideOnMobile: true,
            cell: (row) => (
                <div className="space-y-0.5 text-xs">
                    <Ltr>
                        <div>{row.starts_at?.slice(0, 16) ?? '—'}</div>
                        <div className="text-muted-foreground">{row.ends_at?.slice(0, 16) ?? '—'}</div>
                    </Ltr>
                </div>
            ),
        },
    ];

    return (
        <ManageLayout
            title={t('promotions.title', 'العروض الترويجية')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('promotions.title', 'العروض الترويجية') },
            ]}
            // The layout owns the heading and the action slot; a second <h1> here would give the
            // page two, which is what the other list screens deliberately avoid.
            actions={
                <Button onClick={() => router.visit('/manage/promotions/create')}>
                    {t('promotions.list_new', 'عرض جديد')}
                </Button>
            }
        >
            <div className="space-y-4">
                {/*
                 * The no-stacking statement, on the page an admin reads BEFORE they create the
                 * second rule — not in a help article nobody opens. One string, shipped by the
                 * server, so this screen and the form cannot drift into saying different things.
                 */}
                <div
                    className="rounded-lg border border-amber-300/60 bg-amber-50/60 p-4 text-sm leading-relaxed dark:border-amber-900/50 dark:bg-amber-950/20"
                    role="note"
                >
                    {stacking_notice}
                </div>

                <DataTable
                    table={table}
                    columns={columns}
                    rowId={(row) => row.id}
                    searchPlaceholder={t('promotions.list_search_placeholder', 'ابحث باسم العرض')}
                    emptyTitle={t('promotions.list_empty_title', 'لا توجد عروض بعد')}
                    emptyDescription={t('promotions.list_empty_description', 'أنشئ عرضًا ليظهر هنا مع حالته الفعلية.')}
                    filters={(setFilter, current) => (
                        <>
                            <Select
                                className="w-full sm:w-48"
                                aria-label={t('common.status', 'الحالة')}
                                value={current.is_active ?? ''}
                                onChange={(event) => setFilter('is_active', event.target.value || null)}
                            >
                                <option value="">{t('common.all_statuses', 'كل الحالات')}</option>
                                <option value="1">{t('promotions.list_active', 'مفعّلة')}</option>
                                <option value="0">{t('promotions.list_suspended', 'موقوفة')}</option>
                            </Select>

                            <Select
                                className="w-full sm:w-48"
                                aria-label={t('common.storefront', 'المتجر')}
                                value={current.storefront_id ?? ''}
                                onChange={(event) => setFilter('storefront_id', event.target.value || null)}
                            >
                                <option value="">{t('common.all_storefronts', 'كل المتاجر')}</option>
                                {storefronts.map((storefront) => (
                                    <option key={storefront.id} value={String(storefront.id)}>
                                        {storefront.name}
                                    </option>
                                ))}
                            </Select>
                        </>
                    )}
                    rowActions={(row) => (
                        <Button variant="outline" size="sm" onClick={() => router.visit(row.edit_url)}>
                            {t('common.edit', 'تعديل')}
                        </Button>
                    )}
                />
            </div>
        </ManageLayout>
    );
}
