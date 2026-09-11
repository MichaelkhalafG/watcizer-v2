import { usePage } from '@inertiajs/react';
import { AlertTriangle, Boxes, PackageX, Layers } from 'lucide-react';

import ManageLayout from '@/layouts/ManageLayout';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
                <p className="text-2xl font-semibold tabular-nums" dir="ltr">
                    {nf.format(stat.value)}
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
    const { auth } = usePage<SharedProps>().props;

    return (
        <ManageLayout title="الرئيسية" crumbs={[{ label: 'الرئيسية' }]}>
            <Alert tone="info" title={`أهلاً ${auth.user?.name ?? ''}`}>
                الكتالوج مشترك بين المتاجر: المنتج واحد، وما يختلف هو الظهور والتصنيفات والترتيب في كل متجر. الأرقام بالأسفل تفصّل ذلك لكل متجر.
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
                    <CardTitle>الكتالوج على كل متجر</CardTitle>
                    <p className="text-xs text-muted-foreground">
                        كل منتج يُضاف تلقائيًا إلى كل متجر مفعّل وهو ظاهر، والفريق يخفي ما لا يناسب كل موقع. إعادة التحديث لا تُعيد إظهار ما أخفيتَه.
                    </p>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-start text-xs text-muted-foreground">
                                <th className="py-2 text-start font-medium">المتجر</th>
                                <th className="py-2 text-start font-medium">ظاهر</th>
                                <th className="py-2 text-start font-medium">مخفي</th>
                                <th className="py-2 text-start font-medium">غير مضاف</th>
                                <th className="py-2 text-start font-medium">بلا تصنيف</th>
                                <th className="py-2 text-start font-medium">ظاهر بعربي ناقص</th>
                            </tr>
                        </thead>
                        <tbody>
                            {storefronts.map((row) => (
                                <tr key={row.id} className="border-b last:border-0">
                                    <td className="py-2">
                                        <span className="font-medium">{row.name}</span>{' '}
                                        <span className="text-xs text-muted-foreground" dir="ltr">
                                            {row.code}
                                        </span>
                                        {row.is_active ? null : (
                                            <Badge variant="neutral" className="ms-2">
                                                غير مفعّل
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="py-2 tabular-nums" dir="ltr">
                                        {nf.format(row.visible)}
                                    </td>
                                    <td className="py-2 tabular-nums" dir="ltr">
                                        {nf.format(row.hidden)}
                                    </td>
                                    <td className="py-2 tabular-nums" dir="ltr">
                                        {row.not_added === 0 ? '0' : <Badge variant="warning">{nf.format(row.not_added)}</Badge>}
                                    </td>
                                    <td className="py-2 tabular-nums" dir="ltr">
                                        {row.unplaced === 0 ? '0' : <Badge variant="warning">{nf.format(row.unplaced)}</Badge>}
                                    </td>
                                    <td className="py-2 tabular-nums" dir="ltr">
                                        {row.no_arabic === 0 ? '0' : <Badge variant="destructive">{nf.format(row.no_arabic)}</Badge>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>حالة المخزون</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <div className="flex items-start gap-3 rounded-lg border p-4">
                            <PackageX className="mt-0.5 h-5 w-5 text-destructive" aria-hidden="true" />
                            <div>
                                <p className="text-lg font-semibold tabular-nums" dir="ltr">
                                    {nf.format(inventory.out_of_stock)}
                                </p>
                                <p className="text-sm text-muted-foreground">منتجات مفعّلة نفدت من المخزون</p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3 rounded-lg border p-4">
                            <AlertTriangle className="mt-0.5 h-5 w-5 text-amber-600" aria-hidden="true" />
                            <div>
                                <p className="text-lg font-semibold tabular-nums" dir="ltr">
                                    {nf.format(inventory.low_stock)}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    وصلت إلى حد التنبيه الخاص بها
                                    <span className="mt-1 block text-xs">
                                        محسوبة من عمود low_stock_threshold لكل منتج ({nf.format(inventory.threshold_products)} منتج لديه حد)
                                    </span>
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3 rounded-lg border p-4">
                            <Boxes className="mt-0.5 h-5 w-5 text-muted-foreground" aria-hidden="true" />
                            <div>
                                <p className="text-lg font-semibold tabular-nums" dir="ltr">
                                    {nf.format(inventory.buckets.express)} / {nf.format(inventory.buckets.market)}
                                </p>
                                <p className="text-sm text-muted-foreground">إجمالي الوحدات: express / market</p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3 rounded-lg border p-4">
                            <Layers className="mt-0.5 h-5 w-5 text-muted-foreground" aria-hidden="true" />
                            <div>
                                <p className="text-lg font-semibold tabular-nums" dir="ltr">
                                    {nf.format(inventory.variants)}
                                </p>
                                <p className="text-sm text-muted-foreground">مقاسات/ألوان لها مخزون مستقل</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>صلاحياتك</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex flex-wrap gap-1.5">
                            {auth.user?.roles.map((role) => (
                                <Badge key={role.value}>{role.label}</Badge>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            القائمة الجانبية تعرض ما تستطيع الوصول إليه فقط، والخادم يرفض أي مسار خارج صلاحياتك.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </ManageLayout>
    );
}
