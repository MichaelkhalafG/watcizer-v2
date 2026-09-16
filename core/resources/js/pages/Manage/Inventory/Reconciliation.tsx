import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import { Ltr } from '@/components/ui/bidi';

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
            <div className="text-2xl font-semibold">
                <Ltr>{value.toLocaleString('en-US')}</Ltr>
            </div>
        </div>
    );
}

export default function InventoryReconciliation({ ok, exit_code, report, ran_at, counts }: Props) {
    const t = useT();
    const [busy, setBusy] = useState(false);

    const rerun = () => {
        setBusy(true);
        // A plain reload: the check runs on GET, so "run again" is "ask the page again". There is
        // no POST here because the panel changes nothing.
        router.reload({ onFinish: () => setBusy(false) });
    };

    return (
        <ManageLayout
            title={t('inventory.recon_title', 'فحص مطابقة المخزون')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('common.inventory', 'المخزون'), href: '/manage/inventory' },
                { label: t('common.reconciliation', 'فحص المطابقة') },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" disabled={busy} onClick={rerun}>
                        {t('inventory.recon_rerun', 'أعد الفحص')}
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <Link href="/manage/inventory/ledger">{t('inventory.recon_ledger_link', 'السجل')}</Link>
                    </Button>
                </div>
            }
        >
            <div className="space-y-6">
                {ok ? (
                    <Alert tone="success" title={t('inventory.recon_ok_title', 'المخزون مطابق')}>
                        {t(
                            'inventory.recon_ok_body',
                            'الفحص انتهى بلا مخالفات: مجموع الحركات يساوي الأرصدة، وأرصدة المنتجات ذات المقاسات مشتقّة من متغيّراتها.',
                        )}
                    </Alert>
                ) : (
                    <Alert
                        tone="error"
                        title={t('inventory.recon_failed_title', 'الفحص وجد مخالفات (رمز الخروج :code)', {
                            code: exit_code,
                        })}
                    >
                        {t(
                            'inventory.recon_failed_body',
                            'اقرأ التقرير أدناه كما هو. هذه الشاشة لا تُصلح شيئًا ولا تخفي شيئًا — الإصلاح يتم بتسجيل حركة مضادة من شاشة المخزون، أو بمراجعة الموجة المسؤولة عن الفارق.',
                        )}
                    </Alert>
                )}

                <div className="grid gap-6 sm:grid-cols-3">
                    <Card>
                        <CardContent className="pt-6">
                            <Count label={t('inventory.recon_count_products', 'منتجات')} value={counts.products} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <Count label={t('inventory.recon_count_variants', 'متغيّرات')} value={counts.variants} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <Count label={t('inventory.recon_count_movements', 'حركات في السجل')} value={counts.movements} />
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {/*
                             * The command name stays a real <code> element (monospace, dir="ltr"),
                             * so the heading is a translated word FOLLOWED by the literal command
                             * rather than one sentence. The English has to read correctly in front
                             * of it — "Report from inventory:verify".
                             */}
                            {t('inventory.recon_report_heading', 'تقرير')}{' '}
                            <code className="text-sm" dir="ltr">
                                inventory:verify
                            </code>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-xs text-muted-foreground">
                            {t('inventory.recon_ran_at', 'نُفِّذ في')} <span dir="ltr">{ran_at}</span>{' '}
                            {t(
                                'inventory.recon_report_verbatim',
                                '— هذه مخرجات الأمر نفسه بلا تعديل، حتى تتفق الشاشة مع الطرفية ومع خطوة الكتاب الليلي.',
                            )}
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

                <Alert tone="info" title={t('inventory.recon_no_fix_title', 'لماذا لا يوجد زر «أصلح»')}>
                    {t(
                        'inventory.recon_no_fix_body',
                        'الفحص يقرأ فقط. الرقم الخاطئ يُصحَّح بحركة مخزون لها سبب وملاحظة، فيبقى الأثر في السجل؛ زرّ إصلاح صامت كان سيمحو الفارق ويمحو معه سبب وجوده.',
                    )}
                </Alert>
            </div>
        </ManageLayout>
    );
}
