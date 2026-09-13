import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ManageLayout from '@/layouts/ManageLayout';

/**
 * The reconciliation panel (wave 4C) — READ-ONLY, and it shows the COMMAND's own words.
 *
 * `inventory:verify` holds the four invariants: the ledger sums to the column, a variant product's
 * aggregate equals its variants, `in_stock` is derived and not stored independently, and no
 * product-level movement exists on a product that has variants. This screen runs that command and
 * prints its report verbatim.
 *
 * It deliberately does NOT re-derive those checks in PHP or in the browser. A second
 * implementation is a second answer, and the first time the two disagreed nobody would know which
 * to believe — least of all on switch night, when the runbook's step is the command.
 */

interface Props {
    ok: boolean;
    exit_code: number;
    /** The command's stdout, unedited. */
    report: string;
    ran_at: string;
    counts: { products: number; variants: number; movements: number };
}

function Count({ label, value }: { label: string; value: number }) {
    return (
        <div className="space-y-1">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="text-2xl font-semibold" dir="ltr">
                {value.toLocaleString('en-US')}
            </div>
        </div>
    );
}

export default function InventoryReconciliation({ ok, exit_code, report, ran_at, counts }: Props) {
    const [busy, setBusy] = useState(false);

    const rerun = () => {
        setBusy(true);
        // A plain reload: the check runs on GET, so "run again" is "ask the page again". There is
        // no POST here because the panel changes nothing.
        router.reload({ onFinish: () => setBusy(false) });
    };

    return (
        <ManageLayout
            title="فحص مطابقة المخزون"
            crumbs={[
                { label: 'الرئيسية', href: '/manage' },
                { label: 'المخزون', href: '/manage/inventory' },
                { label: 'فحص المطابقة' },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" disabled={busy} onClick={rerun}>
                        أعد الفحص
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <Link href="/manage/inventory/ledger">السجل</Link>
                    </Button>
                </div>
            }
        >
            <div className="space-y-6">
                {ok ? (
                    <Alert tone="success" title="المخزون مطابق">
                        الفحص انتهى بلا مخالفات: مجموع الحركات يساوي الأرصدة، وأرصدة المنتجات ذات المقاسات مشتقّة من
                        متغيّراتها.
                    </Alert>
                ) : (
                    <Alert tone="error" title={`الفحص وجد مخالفات (رمز الخروج ${exit_code})`}>
                        اقرأ التقرير أدناه كما هو. هذه الشاشة لا تُصلح شيئًا ولا تخفي شيئًا — الإصلاح يتم بتسجيل حركة
                        مضادة من شاشة المخزون، أو بمراجعة الموجة المسؤولة عن الفارق.
                    </Alert>
                )}

                <div className="grid gap-6 sm:grid-cols-3">
                    <Card>
                        <CardContent className="pt-6">
                            <Count label="منتجات" value={counts.products} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <Count label="متغيّرات" value={counts.variants} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <Count label="حركات في السجل" value={counts.movements} />
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            تقرير <code className="text-sm" dir="ltr">inventory:verify</code>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-xs text-muted-foreground">
                            نُفِّذ في <span dir="ltr">{ran_at}</span> — هذه مخرجات الأمر نفسه بلا تعديل، حتى تتفق الشاشة
                            مع الطرفية ومع خطوة الكتاب الليلي.
                        </p>
                        {/* `dir="ltr"` and a monospace block: this is command output, not prose. */}
                        <pre
                            dir="ltr"
                            className="max-h-[28rem] overflow-auto rounded-lg border border-border bg-muted/50 p-4 text-xs leading-relaxed"
                        >
                            {report}
                        </pre>
                    </CardContent>
                </Card>

                <Alert tone="info" title="لماذا لا يوجد زر «أصلح»">
                    الفحص يقرأ فقط. الرقم الخاطئ يُصحَّح بحركة مخزون لها سبب وملاحظة، فيبقى الأثر في السجل؛ زرّ إصلاح
                    صامت كان سيمحو الفارق ويمحو معه سبب وجوده.
                </Alert>
            </div>
        </ManageLayout>
    );
}
