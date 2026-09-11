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

export default function Home({ stats, inventory }: { stats: Stat[]; inventory: Inventory }) {
    const { auth } = usePage<SharedProps>().props;

    return (
        <ManageLayout title="الرئيسية" crumbs={[{ label: 'الرئيسية' }]}>
            <Alert tone="info" title={`أهلاً ${auth.user?.name ?? ''}`}>
                هذه المرحلة (4A) تبني الهيكل والصلاحيات فقط. شاشات المنتجات والتصنيفات والطلبات تأتي في المراحل 4B و4C.
            </Alert>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {stats.map((stat) => (
                    <StatCard key={stat.key} stat={stat} />
                ))}
            </div>

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
