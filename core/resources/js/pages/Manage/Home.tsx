import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowDownRight, ArrowUpRight, Minus, PackageX } from 'lucide-react';

import ManageLayout from '@/layouts/ManageLayout';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Ltr, Num } from '@/components/ui/bidi';
import { Select } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

/*
 * The dashboard home (rebuilt 2026-10-21).
 *
 * Every block answers a question somebody acts on; see HomeController for what was dropped and why.
 * The chart is drawn by hand in SVG — no charting library, no new dependency, and nothing to keep in step
 * with the dashboard's theme because it is built from the same CSS variables as everything else.
 */

interface ScopeOption {
    id: number;
    code: string;
    name: string;
    currency: string;
}

interface Scope {
    selected: number | null;
    options: ScopeOption[];
    currency: string;
    mixed_currency: boolean;
    window_days: number;
}

interface SeriesPoint {
    date: string;
    orders: number;
    ordered: number;
}

interface Sales {
    today: { orders: number; ordered: number };
    baseline: { orders: number; ordered: number; weeks: number };
    window: {
        orders: number;
        ordered: number;
        collected: number;
        cancelled: number;
        cancelled_orders: number;
        aov: number;
    };
    series: SeriesPoint[];
}

interface Queue {
    total: number;
    by_status: { status: string; orders: number }[];
    oldest: string | null;
    oldest_days: number | null;
}

interface PaymentSplit {
    method: string;
    orders: number;
    value: number;
}

interface TopProduct {
    product_id: number;
    name: string;
    units: number;
    value: number;
    in_stock: boolean;
}

interface RestockRow {
    product_id: number;
    name: string;
    units: number;
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

interface Props {
    scope: Scope;
    sales: Sales;
    queue: Queue;
    payments: PaymentSplit[];
    top_products: TopProduct[];
    restock: RestockRow[];
    unseen: number;
    inventory: { out_of_stock: number; low_stock: number };
    storefronts: StorefrontRow[];
}

/** Latin digits in this dashboard, whatever the UI language — the team reads them that way. */
const nf = new Intl.NumberFormat('en-US');
const mf = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });

/** A money amount: rounded to whole units. Nobody decides anything on the piastres. */
function money(value: number, currency: string): string {
    return `${mf.format(Math.round(value))} ${currency}`;
}

/**
 * Today against the same weekday, averaged over the weeks that actually have data.
 *
 * A shop with less than one week of history gets no percentage at all — Brand Fashion took its first order
 * on 2026-10-19, and a shop in that state must read as "too early to say", not as "+∞%" or "−100%".
 */
function Delta({ now, base, weeks }: { now: number; base: number; weeks: number }) {
    const t = useT();
    if (weeks === 0) {
        return <span className="text-xs text-muted-foreground">{t('home.no_baseline', 'لا توجد أسابيع سابقة للمقارنة بعد')}</span>;
    }
    if (base === 0) {
        return (
            <span className="text-xs text-muted-foreground">
                {now === 0
                    ? t('home.same_as_usual', 'كالمعتاد في هذا اليوم')
                    : t('home.above_nothing', 'لم يكن هناك شيء في هذا اليوم سابقًا')}
            </span>
        );
    }
    const pct = Math.round(((now - base) / base) * 100);
    const flat = Math.abs(pct) < 5;
    const Icon = flat ? Minus : pct > 0 ? ArrowUpRight : ArrowDownRight;
    const tone = flat
        ? 'text-muted-foreground'
        : pct > 0
          ? 'text-emerald-700 dark:text-emerald-400'
          : 'text-destructive';

    return (
        <span className={`inline-flex items-center gap-1 text-xs font-medium ${tone}`}>
            <Icon className="h-3.5 w-3.5" aria-hidden="true" />
            <Num>{`${pct > 0 ? '+' : ''}${pct}%`}</Num>
            {/* no week COUNT in the sentence: Arabic pluralises 1/2/3-10/11+ differently and ":weeks أسابيع"
                prints "1 أسابيع", which is wrong. The number earns nothing here anyway. */}
            <span className="font-normal text-muted-foreground">
                {t('home.vs_weekday', 'مقارنة بنفس اليوم في الأسابيع السابقة')}
            </span>
        </span>
    );
}

/**
 * The month, as bars — one per day, height by value, drawn in SVG.
 *
 * Why by hand and not a library: the only shape this page needs is "thirty bars and a baseline". ApexCharts,
 * which the admin template ships, is forbidden by AGENTS §5 and would be ~130 KB gzipped for that. Recharts is
 * ~95 KB. This is about forty lines and inherits the theme because it is painted in `currentColor`.
 *
 * It is a FIGURE, not a decoration: it carries a role, a label and a text summary for anyone who cannot see
 * it, and every bar has a `<title>` so hovering a day names the day and its number.
 */
function DailyBars({ series, currency }: { series: SeriesPoint[]; currency: string }) {
    const t = useT();
    const max = series.reduce((m, p) => Math.max(m, p.ordered), 0);
    const width = 100;
    const height = 34;
    const gap = 0.6;
    const slot = series.length > 0 ? width / series.length : width;
    const barWidth = Math.max(slot - gap, 0.4);
    const best = series.reduce<SeriesPoint | null>((b, p) => (b === null || p.ordered > b.ordered ? p : b), null);

    if (max <= 0) {
        return (
            <div className="flex h-24 items-center justify-center rounded-md border border-dashed text-sm text-muted-foreground">
                {t('home.no_sales_window', 'لا مبيعات في هذه الفترة')}
            </div>
        );
    }

    return (
        <figure className="space-y-2">
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                className="h-24 w-full text-primary"
                role="img"
                aria-label={t('home.chart_alt', 'مبيعات كل يوم خلال الفترة؛ أعلى يوم :day بقيمة :value', {
                    day: best?.date ?? '',
                    value: money(best?.ordered ?? 0, currency),
                })}
            >
                {series.map((point, i) => {
                    const h = Math.max((point.ordered / max) * (height - 1), point.ordered > 0 ? 0.6 : 0);
                    return (
                        <rect
                            key={point.date}
                            x={i * slot}
                            y={height - h}
                            width={barWidth}
                            height={h}
                            rx={0.4}
                            fill="currentColor"
                            opacity={point.ordered > 0 ? 0.85 : 0.18}
                        >
                            <title>{`${point.date} — ${money(point.ordered, currency)} · ${nf.format(point.orders)}`}</title>
                        </rect>
                    );
                })}
            </svg>
            <figcaption className="flex justify-between text-xs text-muted-foreground">
                <Num>{series[0]?.date ?? ''}</Num>
                <Num>{series[series.length - 1]?.date ?? ''}</Num>
            </figcaption>
        </figure>
    );
}

/** One headline figure with its own explanation underneath. */
function Figure({ label, value, children }: { label: string; value: string; children?: React.ReactNode }) {
    return (
        <div className="space-y-1">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="text-2xl font-semibold">
                <Num>{value}</Num>
            </p>
            {children}
        </div>
    );
}

export default function Home({
    scope,
    sales,
    queue,
    payments,
    top_products,
    restock,
    unseen,
    inventory,
    storefronts,
}: Props) {
    const t = useT();
    const { auth } = usePage<SharedProps>().props;
    const currency = scope.currency;

    const STATUS_LABELS: Record<string, string> = {
        // the SAME keys and the same Arabic the orders and customers screens use, so one status
        // cannot read two ways in one dashboard
        pending: t('common.status_pending', 'قيد الانتظار'),
        processing: t('common.status_processing', 'قيد التنفيذ'),
        shipped: t('common.status_shipped', 'تم الشحن'),
    };
    const METHOD_LABELS: Record<string, string> = {
        cash: t('home.method_cash', 'الدفع عند الاستلام'),
        card: t('home.method_card', 'بطاقة'),
        whatsapp: t('home.method_whatsapp', 'واتساب'),
        unknown: t('home.method_unknown', 'غير محدد'),
    };

    const cancelRate =
        sales.window.orders === 0 ? 0 : Math.round((sales.window.cancelled_orders / sales.window.orders) * 100);
    /*
     * The payment split's denominator is the PAID orders, not every order in the window: the split excludes
     * cancelled orders (an order nobody paid for was not paid for by any method), so dividing by the window's
     * full count made the shares add up to 100 minus the cancellation rate — 50% + 34% = 84% on the first
     * screenshot. Summing the rows is also self-correcting if a method is ever added.
     */
    const paidOrders = payments.reduce((n, row) => n + row.orders, 0);
    const windowLabel = t('home.last_days', 'آخر :days يومًا', { days: String(scope.window_days) });

    return (
        <ManageLayout title={t('common.home', 'الرئيسية')} crumbs={[{ label: t('common.home', 'الرئيسية') }]}>
            {/* ── who, and which shop ─────────────────────────────────────────────────────── */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">
                        {t('home.welcome', 'أهلاً :name', { name: auth.user?.name ?? '' })}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('home.money_scope_hint', 'أرقام المبيعات تتبع المتجر المختار؛ أرقام الكتالوج والمخزون مشتركة بين المتاجر.')}
                    </p>
                </div>
                {scope.options.length > 1 ? (
                    <Select
                        aria-label={t('common.storefront', 'المتجر')}
                        className="w-56"
                        value={scope.selected === null ? 'all' : String(scope.selected)}
                        onChange={(event) =>
                            router.get(
                                '/manage',
                                event.target.value === 'all' ? {} : { storefront: event.target.value },
                                { preserveScroll: true, preserveState: false },
                            )
                        }
                    >
                        <option value="all">{t('home.all_storefronts', 'كل المتاجر')}</option>
                        {scope.options.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.name}
                            </option>
                        ))}
                    </Select>
                ) : null}
            </div>

            {scope.mixed_currency ? (
                <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    {t('home.mixed_currency', 'المتاجر المختارة تستخدم عملات مختلفة، لذلك لا يمكن جمع المبالغ. اختر متجرًا واحدًا.')}
                </p>
            ) : null}

            {/* ── 1. is today normal? ─────────────────────────────────────────────────────── */}
            <Card>
                <CardHeader className="gap-1">
                    <CardTitle>{t('home.today', 'اليوم')}</CardTitle>
                    <p className="text-xs text-muted-foreground">
                        {t('home.today_hint', 'منذ منتصف الليل بتوقيت المتجر')}
                    </p>
                </CardHeader>
                <CardContent className="grid gap-6 sm:grid-cols-3">
                    <Figure label={t('home.orders_today', 'طلبات اليوم')} value={nf.format(sales.today.orders)}>
                        <Delta now={sales.today.orders} base={sales.baseline.orders} weeks={sales.baseline.weeks} />
                    </Figure>
                    <Figure label={t('home.sold_today', 'مبيعات اليوم')} value={money(sales.today.ordered, currency)}>
                        <Delta now={sales.today.ordered} base={sales.baseline.ordered} weeks={sales.baseline.weeks} />
                    </Figure>
                    <Figure label={t('home.stat_orders_unseen', 'جديد منذ آخر زيارة')} value={nf.format(unseen)}>
                        <span className="text-xs text-muted-foreground">
                            {t('home.unseen_hint_scope', 'طلبات لم تفتحها بعد — لك وحدك، وعبر كل المتاجر المسموح لك بها')}
                        </span>
                    </Figure>
                </CardContent>
            </Card>

            {/* ── 2. how is the month going? ──────────────────────────────────────────────── */}
            <Card>
                <CardHeader className="gap-1">
                    <CardTitle>{windowLabel}</CardTitle>
                    <p className="text-xs text-muted-foreground">
                        {t('home.window_hint', '«مُباع» كل الطلبات ما عدا الملغاة، و«مُسلَّم» ما تم تسليمه فعلاً. الفرق بينهما قيد التنفيذ.')}
                    </p>
                </CardHeader>
                <CardContent className="space-y-6">
                    <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
                        <Figure label={t('home.sold', 'مُباع')} value={money(sales.window.ordered, currency)}>
                            <span className="text-xs text-muted-foreground">
                                <Num>{nf.format(sales.window.orders)}</Num>{' '}
                                {t('home.orders_word', 'طلب')}
                            </span>
                        </Figure>
                        <Figure label={t('home.collected', 'مُسلَّم')} value={money(sales.window.collected, currency)}>
                            <span className="text-xs text-muted-foreground">
                                {t('home.collected_hint', 'وصل إلى العميل')}
                            </span>
                        </Figure>
                        <Figure label={t('home.aov', 'متوسط قيمة الطلب')} value={money(sales.window.aov, currency)}>
                            <span className="text-xs text-muted-foreground">
                                {t('home.aov_hint', 'مُباع ÷ عدد الطلبات')}
                            </span>
                        </Figure>
                        <Figure label={t('home.cancelled', 'ملغاة')} value={`${nf.format(cancelRate)}%`}>
                            <span className="text-xs text-muted-foreground">
                                <Num>{nf.format(sales.window.cancelled_orders)}</Num>{' '}
                                {t('home.orders_word', 'طلب')} ·{' '}
                                <Num>{money(sales.window.cancelled, currency)}</Num>
                            </span>
                        </Figure>
                    </div>
                    <DailyBars series={sales.series} currency={currency} />
                </CardContent>
            </Card>

            {/* ── 3. what is waiting for me? ──────────────────────────────────────────────── */}
            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader className="gap-1">
                        <CardTitle>{t('home.open_orders', 'طلبات مفتوحة')}</CardTitle>
                        <p className="text-xs text-muted-foreground">
                            {t('home.open_orders_hint', 'كل الطلبات التي لم تُسلَّم ولم تُلغَ بعد، مهما كان تاريخها')}
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-end gap-6">
                            <Figure label={t('home.waiting', 'في انتظارك')} value={nf.format(queue.total)} />
                            {queue.by_status.map((row) => (
                                <div key={row.status} className="space-y-1">
                                    <p className="text-xs text-muted-foreground">{STATUS_LABELS[row.status] ?? row.status}</p>
                                    <p className="text-lg font-medium">
                                        <Num>{nf.format(row.orders)}</Num>
                                    </p>
                                </div>
                            ))}
                        </div>
                        {queue.oldest_days !== null && queue.oldest_days >= 3 ? (
                            <p className="text-sm">
                                <Badge variant={queue.oldest_days >= 7 ? 'destructive' : 'warning'}>
                                    {t('home.oldest_open', 'أقدم طلب مفتوح عمره :days يومًا', {
                                        days: nf.format(queue.oldest_days),
                                    })}
                                </Badge>
                            </p>
                        ) : null}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="gap-1">
                        <CardTitle>{t('home.payment_split', 'طريقة الدفع')}</CardTitle>
                        <p className="text-xs text-muted-foreground">{windowLabel}</p>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {payments.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('home.no_orders_window', 'لا طلبات في هذه الفترة')}</p>
                        ) : (
                            payments.map((row) => {
                                const share = paidOrders === 0 ? 0 : Math.round((row.orders / paidOrders) * 100);
                                return (
                                    <div key={row.method} className="space-y-1">
                                        <div className="flex items-baseline justify-between gap-2 text-sm">
                                            <span>{METHOD_LABELS[row.method] ?? row.method}</span>
                                            <span className="font-medium">
                                                <Num>{`${share}%`}</Num>
                                            </span>
                                        </div>
                                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                            <div className="h-full rounded-full bg-primary" style={{ width: `${share}%` }} />
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            <Num>{money(row.value, currency)}</Num>
                                        </p>
                                    </div>
                                );
                            })
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* ── 4. what should I buy more of? ───────────────────────────────────────────── */}
            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader className="gap-1">
                        <CardTitle>{t('home.best_sellers', 'الأكثر مبيعًا')}</CardTitle>
                        <p className="text-xs text-muted-foreground">{windowLabel}</p>
                    </CardHeader>
                    <CardContent className="px-0">
                        {top_products.length === 0 ? (
                            <p className="px-6 text-sm text-muted-foreground">
                                {t('home.no_sales_window', 'لا مبيعات في هذه الفترة')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('common.product', 'المنتج')}</TableHead>
                                        <TableHead>{t('home.units', 'قطعة')}</TableHead>
                                        <TableHead>{t('home.sold', 'مُباع')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {top_products.map((row) => (
                                        <TableRow key={row.product_id}>
                                            <TableCell>
                                                <Ltr className="line-clamp-1">{row.name}</Ltr>
                                                {row.in_stock ? null : (
                                                    <Badge variant="destructive" className="ms-2">
                                                        {t('home.out_of_stock', 'نفد')}
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Num>{nf.format(row.units)}</Num>
                                            </TableCell>
                                            <TableCell>
                                                <Num>{money(row.value, currency)}</Num>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="gap-1">
                        <CardTitle>{t('home.restock', 'يُعاد طلبه')}</CardTitle>
                        <p className="text-xs text-muted-foreground">
                            {t('home.restock_hint', 'بِيع خلال الفترة ولم يعد له مخزون — مبيعات تضيع اليوم')}
                        </p>
                    </CardHeader>
                    <CardContent className="px-0">
                        {restock.length === 0 ? (
                            <p className="px-6 text-sm text-muted-foreground">
                                {t('home.restock_empty', 'لا شيء نفد من المنتجات التي بِيعت مؤخرًا')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('common.product', 'المنتج')}</TableHead>
                                        <TableHead>{t('home.units_sold', 'بِيع')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {restock.map((row) => (
                                        <TableRow key={row.product_id}>
                                            <TableCell>
                                                <Ltr className="line-clamp-1">{row.name}</Ltr>
                                            </TableCell>
                                            <TableCell>
                                                <Num>{nf.format(row.units)}</Num>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* ── 5. what is wrong with the shop? ─────────────────────────────────────────── */}
            <div className="grid gap-4 sm:grid-cols-2">
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
                    <AlertTriangle className="mt-0.5 h-5 w-5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                    <div>
                        <p className="text-lg font-semibold">
                            <Num>{nf.format(inventory.low_stock)}</Num>
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {t('home.low_stock_hint', 'وصلت إلى حد التنبيه الخاص بها')}
                        </p>
                    </div>
                </div>
            </div>

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
        </ManageLayout>
    );
}
