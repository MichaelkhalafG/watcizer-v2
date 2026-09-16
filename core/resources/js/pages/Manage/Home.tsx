import { usePage } from '@inertiajs/react';
import { AlertTriangle, Boxes, PackageX, Layers } from 'lucide-react';

import ManageLayout from '@/layouts/ManageLayout';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Ltr, Num } from '@/components/ui/bidi';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

interface Stat {
    key: string;
    label: string;
    value: number;
    hint: string;
}

interface StorefrontRow {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    visible: number;
    hidden: number;
    not_added: number;
    unplaced: number;
    no_arabic: number;
}

interface Inventory {
    out_of_stock: number;
    low_stock: number;
    variants: number;
    threshold_products: number;
    buckets: { express: number; market: number };
}

/** Arabic-Indic-free number formatting: the team reads Latin digits in this dashboard. */
const nf = new Intl.NumberFormat('en-US');

function StatCard({ stat }: { stat: Stat }) {
    return (
        <Card>
            <CardContent className="space-y-1 py-5">
                <p className="text-sm text-muted-foreground">{stat.label}</p>
                <p className="text-2xl font-semibold">
                    <Num>{nf.format(stat.value)}</Num>
                </p>
                <p className="text-xs text-muted-foreground">{stat.hint}</p>
            </CardContent>
        </Card>
    );
}

export default function Home({
    stats,
    inventory,
    storefronts,
}: {
    stats: Stat[];
    inventory: Inventory;
    storefronts: StorefrontRow[];
}) {
    const t = useT();
    const { auth } = usePage<SharedProps>().props;

    return (
        <ManageLayout title={t('common.home', 'الرئيسية')} crumbs={[{ label: t('common.home', 'الرئيسية') }]}>
            <Alert tone="info" title={t('home.welcome', 'أهلاً :name', { name: auth.user?.name ?? '' })}>
                {t(
                    'home.catalogue_shared',
                    'الكتالوج مشترك بين المتاجر: المنتج واحد، وما يختلف هو الظهور والتصنيفات والترتيب في كل متجر. الأرقام بالأسفل تفصّل ذلك لكل متجر.',
                )}
            </Alert>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {stats.map((stat) => (
                    <StatCard key={stat.key} stat={stat} />
                ))}
            </div>

            {/* ── the catalogue as each SITE sees it (task 1) ──────────────────────────────
                The totals above are catalogue-wide, which is right — the catalogue is shared.
                This is the number the team actually works from: what is live on each storefront
                and what is holding the rest back. `غير مضاف` and `مخفي` are separated because a
                product that was never offered to a site and one somebody decided against need
                different actions. ──────────────────────────────────────────────────────── */}
            <Card>
                <CardHeader className="gap-1">
                    <CardTitle>{t('home.catalogue_per_storefront', 'الكتالوج على كل متجر')}</CardTitle>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'home.catalogue_per_storefront_hint',
                            'كل منتج يُضاف تلقائيًا إلى كل متجر مفعّل وهو ظاهر، والفريق يخفي ما لا يناسب كل موقع. إعادة التحديث لا تُعيد إظهار ما أخفيتَه.',
                        )}
                    </p>
                </CardHeader>
                <CardContent className="px-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('common.storefront', 'المتجر')}</TableHead>
                                <TableHead>{t('common.visible', 'ظاهر')}</TableHead>
                                <TableHead>{t('common.hidden', 'مخفي')}</TableHead>
                                <TableHead>{t('home.not_added', 'غير مضاف')}</TableHead>
                                <TableHead>{t('home.unplaced', 'بلا تصنيف')}</TableHead>
                                <TableHead>{t('home.visible_missing_arabic', 'ظاهر بعربي ناقص')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {storefronts.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <span className="font-medium">{row.name}</span>{' '}
                                        <Ltr className="text-xs text-muted-foreground">{row.code}</Ltr>
                                        {row.is_active ? null : (
                                            <Badge variant="neutral" className="ms-2">
                                                {t('home.storefront_inactive', 'غير مفعّل')}
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Num>{nf.format(row.visible)}</Num>
                                    </TableCell>
                                    <TableCell>
                                        <Num>{nf.format(row.hidden)}</Num>
                                    </TableCell>
                                    <TableCell>
                                        {row.not_added === 0 ? <Num>0</Num> : <Badge variant="warning"><Num>{nf.format(row.not_added)}</Num></Badge>}
                                    </TableCell>
                                    <TableCell>
                                        {row.unplaced === 0 ? <Num>0</Num> : <Badge variant="warning"><Num>{nf.format(row.unplaced)}</Num></Badge>}
                                    </TableCell>
                                    <TableCell>
                                        {row.no_arabic === 0 ? <Num>0</Num> : <Badge variant="destructive"><Num>{nf.format(row.no_arabic)}</Num></Badge>}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>{t('home.inventory_status', 'حالة المخزون')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <div className="flex items-start gap-3 rounded-lg border p-4">
                            <PackageX className="mt-0.5 h-5 w-5 text-destructive" aria-hidden="true" />
                            <div>
                                <p className="text-lg font-semibold">
                                    <Num>{nf.format(inventory.out_of_stock)}</Num>
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('home.out_of_stock_hint', 'منتجات مفعّلة نفدت من المخزون')}
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3 rounded-lg border p-4">
                            <AlertTriangle className="mt-0.5 h-5 w-5 text-amber-600" aria-hidden="true" />
                            <div>
                                <p className="text-lg font-semibold">
                                    <Num>{nf.format(inventory.low_stock)}</Num>
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('home.low_stock_hint', 'وصلت إلى حد التنبيه الخاص بها')}
                                    <span className="mt-1 block text-xs">
                                        {t(
                                            'home.low_stock_source',
                                            'محسوبة من عمود low_stock_threshold لكل منتج (:count منتج لديه حد)',
                                            { count: nf.format(inventory.threshold_products) },
                                        )}
                                    </span>
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3 rounded-lg border p-4">
                            <Boxes className="mt-0.5 h-5 w-5 text-muted-foreground" aria-hidden="true" />
                            <div>
                                <p className="text-lg font-semibold">
                                    <Num>
                                        {nf.format(inventory.buckets.express)} / {nf.format(inventory.buckets.market)}
                                    </Num>
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('home.units_total', 'إجمالي الوحدات: express / market')}
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3 rounded-lg border p-4">
                            <Layers className="mt-0.5 h-5 w-5 text-muted-foreground" aria-hidden="true" />
                            <div>
                                <p className="text-lg font-semibold">
                                    <Num>{nf.format(inventory.variants)}</Num>
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('home.variants_hint', 'مقاسات/ألوان لها مخزون مستقل')}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('common.your_abilities', 'صلاحياتك')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex flex-wrap gap-1.5">
                            {auth.user?.roles.map((role) => (
                                <Badge key={role.value}>{role.label}</Badge>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'home.abilities_note',
                                'القائمة الجانبية تعرض ما تستطيع الوصول إليه فقط، والخادم يرفض أي مسار خارج صلاحياتك.',
                            )}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </ManageLayout>
    );
}
