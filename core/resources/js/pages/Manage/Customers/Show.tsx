import { router } from '@inertiajs/react';

import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Ltr, Num } from '@/components/ui/bidi';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';

/**
 * One customer (wave 4D) — what somebody needs while the person is on the telephone.
 *
 * Their orders, newest first, each one a click from its own screen; the addresses those orders went
 * to, with the phone numbers written on them. Nothing here is editable, and there is no route that
 * would let it be: the tables belong to the storefront.
 */

interface Order {
    id: number;
    order_number: string;
    status: string;
    total: string;
    payment_method: string | null;
    provider: string | null;
    storefront: string | null;
    created_at: string | null;
    url: string;
}

interface Address {
    id: number;
    line: string | null;
    city: string | null;
    phone_one: string | null;
    phone_two: string | null;
    updated_at: string | null;
}

interface Props {
    customer: {
        ckey: string;
        kind: string;
        kind_label: string;
        name: string;
        email: string | null;
        phone: string | null;
        orders_count: number;
        spent: string;
        last_order_at: string | null;
        joined_at: string | null;
        storefronts: string[];
    };
    orders: Order[];
    addresses: Address[];
}

const TONE: Record<string, 'default' | 'neutral' | 'success' | 'warning' | 'destructive'> = {
    completed: 'success',
    delivered: 'success',
    cancelled: 'destructive',
    pending: 'warning',
};

const money = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function CustomerShow({ customer, orders, addresses }: Props) {
    const t = useT();

    /*
     * The statuses live here rather than in a module constant because reading them needs the hook.
     *
     * All six are `common.status_*`, and the wording is the ORDER screen's. This screen used to
     * carry its own four — مكتمل/"Completed" where the order screen says مغلق/"Closed", قيد التجهيز
     * where it says قيد التنفيذ — so an operator opening a customer, reading "Completed", and
     * clicking through to that same order was shown "Closed". One order, two words, in both
     * languages. The conflict test could not see it: they were different KEYS, not one key with two
     * Arabics. The order screen wins because the state belongs to the order.
     */
    const STATUS: Record<string, string> = {
        pending: t('common.status_pending', 'قيد الانتظار'),
        processing: t('common.status_processing', 'قيد التنفيذ'),
        shipped: t('common.status_shipped', 'تم الشحن'),
        delivered: t('common.status_delivered', 'تم التوصيل'),
        completed: t('common.status_completed', 'مغلق'),
        cancelled: t('common.status_cancelled', 'ملغى'),
    };

    return (
        <ManageLayout
            title={customer.name}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('common.customers', 'العملاء'), href: '/manage/customers' },
                { label: customer.name },
            ]}
        >
            <div className="space-y-4">
                <Alert tone="info" title={t('common.read_only', 'للقراءة فقط')}>
                    {t(
                        'customers.show_read_only_body',
                        'بيانات العميل يملكها المتجر. لا تُعدَّل من لوحة التحكم، ولا يوجد مسار يسمح بذلك.',
                    )}
                </Alert>

                <Card>
                    <CardHeader className="flex-row items-center justify-between gap-3">
                        <CardTitle>{customer.name}</CardTitle>
                        <Badge variant={customer.kind === 'registered' ? 'default' : 'neutral'}>{customer.kind_label}</Badge>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('common.phone', 'الهاتف')}>
                            <Num className="font-medium">{customer.phone ?? '—'}</Num>
                        </Field>
                        <Field label={t('common.email', 'البريد')}>
                            <Ltr className="text-sm">{customer.email ?? '—'}</Ltr>
                        </Field>
                        <Field label={t('common.storefronts', 'المتاجر')}>
                            <span className="text-sm">{customer.storefronts.join(t('common.list_separator', '، ')) || '—'}</span>
                        </Field>
                        <Field label={t('customers.show_orders_count', 'عدد الطلبات')}>
                            <Num className="text-lg font-semibold">{customer.orders_count}</Num>
                        </Field>
                        <Field label={t('common.total_spent', 'إجمالي المشتريات')}>
                            {/* Delivered and completed only — money taken, not money asked for. */}
                            <Num className="text-lg font-semibold">{money.format(Number(customer.spent))}</Num>
                        </Field>
                        <Field label={t('customers.show_first_seen_last_order', 'أول ظهور / آخر طلب')}>
                            <Num className="text-sm">
                                {customer.joined_at?.slice(0, 10) ?? '—'} → {customer.last_order_at?.slice(0, 10) ?? '—'}
                            </Num>
                        </Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('customers.show_orders_title', 'الطلبات (:count)', { count: orders.length })}</CardTitle>
                    </CardHeader>
                    <CardContent className="px-0">
                        {orders.length === 0 ? (
                            <p className="px-6 pb-4 text-sm text-muted-foreground">
                                {t('customers.show_no_orders', 'لا توجد طلبات لهذا العميل.')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('common.order_number', 'رقم الطلب')}</TableHead>
                                        <TableHead>{t('common.date', 'التاريخ')}</TableHead>
                                        <TableHead>{t('common.status', 'الحالة')}</TableHead>
                                        <TableHead>{t('common.total', 'الإجمالي')}</TableHead>
                                        <TableHead>{t('common.payment', 'الدفع')}</TableHead>
                                        <TableHead>{t('common.storefront', 'المتجر')}</TableHead>
                                        <TableHead align="end">…</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {orders.map((order) => (
                                        <TableRow key={order.id}>
                                            <TableCell>
                                                <Num className="font-medium">{order.order_number}</Num>
                                            </TableCell>
                                            <TableCell>
                                                <Num className="text-xs text-muted-foreground">{order.created_at?.slice(0, 16) ?? '—'}</Num>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={TONE[order.status] ?? 'neutral'}>{STATUS[order.status] ?? order.status}</Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Num>{money.format(Number(order.total))}</Num>
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                <Ltr>{order.provider ?? order.payment_method ?? '—'}</Ltr>
                                            </TableCell>
                                            <TableCell className="text-xs">{order.storefront ?? '—'}</TableCell>
                                            <TableCell align="end">
                                                <Button variant="outline" size="sm" onClick={() => router.visit(order.url)}>
                                                    {t('customers.show_open_order', 'فتح')}
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('customers.show_addresses_title', 'العناوين (:count)', { count: addresses.length })}</CardTitle>
                    </CardHeader>
                    <CardContent className="px-0">
                        {addresses.length === 0 ? (
                            <p className="px-6 pb-4 text-sm text-muted-foreground">
                                {t('customers.show_no_addresses', 'لا توجد عناوين — الطلبات هنا بلا عنوان مسجَّل.')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('common.address', 'العنوان')}</TableHead>
                                        <TableHead>{t('common.city', 'المدينة')}</TableHead>
                                        <TableHead>{t('customers.show_address_phones', 'هواتف العنوان')}</TableHead>
                                        <TableHead>{t('common.last_updated', 'آخر تحديث')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {addresses.map((address) => (
                                        <TableRow key={address.id}>
                                            <TableCell className="max-w-md">{address.line ?? '—'}</TableCell>
                                            <TableCell>{address.city ?? '—'}</TableCell>
                                            <TableCell>
                                                <div className="space-y-0.5 text-sm">
                                                    <Num>{address.phone_one ?? '—'}</Num>
                                                    {address.phone_two ? (
                                                        <div className="text-xs text-muted-foreground">
                                                            <Num>{address.phone_two}</Num>
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Num className="text-xs text-muted-foreground">{address.updated_at?.slice(0, 10) ?? '—'}</Num>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </ManageLayout>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div>{children}</div>
        </div>
    );
}
