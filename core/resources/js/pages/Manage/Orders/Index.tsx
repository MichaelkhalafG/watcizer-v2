import { Link } from "@inertiajs/react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { Name, Num } from "@/components/ui/bidi";
import { DataTable, type Column } from "@/components/table/DataTable";
import { DateRangeFilter } from "@/components/table/DateRangeFilter";
import ManageLayout from "@/layouts/ManageLayout";
import type { TablePayload } from "@/types";
import { useT } from "@/lib/i18n";
import { methodLabel, providerLabel } from "@/lib/labels";

/**
 * The order queue (wave 4C).
 *
 * Cross-storefront on purpose: the team works ONE queue and filters it. An order belongs to a
 * storefront through a column, but partitioning the screen by storefront would mean opening two
 * tabs to run one shop day.
 *
 * Every control here is a FILTER on the server's whitelist — nothing is computed in the browser,
 * so the number the list shows and the number a report shows cannot drift.
 */

interface OrderRow {
    id: number;
    order_number: string;
    status: string;
    status_label: string;
    total: string;
    payment_method: string | null;
    /**
     * Who took the money, written by the attempt that succeeded (§3.9.6).
     *
     * NOT "written once": it is a plain column update, so a second successful callback would
     * overwrite it. What stops a second one arriving on an order that has moved on is
     * `CallbackPolicy` — the column is not the guard, and the comment used to claim it was.
     */
    paid_via_provider: string | null;
    paid_via_method: string | null;
    customer: string;
    phone: string | null;
    storefront: string | null;
    storefront_id: number | null;
    created_at: string | null;
    items: number;
    /** The last attempt's outcome, so "paid but still pending" is visible from the list. */
    last_attempt: {
        provider: string | null;
        method: string | null;
        success: boolean;
    } | null;
    url: string;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    table: TablePayload<OrderRow>;
    filters: {
        storefronts: Option[];
        statuses: Option[];
        providers: Option[];
        methods: Option[];
    };
    abilities: { fulfil: boolean; cancel: boolean; settle: boolean };
}

const STATUS_TONE: Record<
    string,
    "default" | "neutral" | "success" | "warning" | "destructive" | "outline"
> = {
    pending: "warning",
    processing: "default",
    shipped: "default",
    // `delivered` is the green one: it is the state the customer cares about. `completed` means
    // CLOSED and is deliberately quiet, so a queue of green rows reads as "arrived", not "filed".
    delivered: "success",
    completed: "neutral",
    cancelled: "destructive",
};

export default function OrdersIndex({ table, filters, abilities }: Props) {
    const t = useT();
    const columns: Array<Column<OrderRow>> = [
        {
            key: "o.order_number",
            header: t("common.order_number", "رقم الطلب"),
            sortable: true,
            cell: (row) => (
                <div className="space-y-0.5">
                    <Link
                        href={row.url}
                        className="font-medium text-brand-strong hover:underline"
                        dir="ltr"
                    >
                        {row.order_number}
                    </Link>
                    {/* Name and phone are laid out by FLEX, not by the bidi algorithm. A bare
                        `{row.customer}` beside a `dir="ltr"` span put the separator at the far
                        left of the cell and glued the phone to the name: `· 01022315422Adam
                        Ayoub`. Flex gives the separator its own box and a real gap, and the two
                        `bdi` wrappers keep each value in its own direction without touching the
                        cell's alignment — a customer name may be Arabic or Latin, so `Name`
                        (dir="auto") decides per value. */}
                    <div className="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                        <Name>{row.customer}</Name>
                        {row.phone ? (
                            <>
                                <span aria-hidden="true">·</span>
                                <Num>{row.phone}</Num>
                            </>
                        ) : null}
                    </div>
                </div>
            ),
        },
        {
            key: "o.status",
            header: t("common.status", "الحالة"),
            sortable: true,
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-1">
                    <Badge variant={STATUS_TONE[row.status] ?? "neutral"}>
                        {row.status_label}
                    </Badge>
                    {/* A paid order still sitting in `pending` is the thing worth seeing early. */}
                    {row.last_attempt && !row.last_attempt.success ? (
                        <Badge
                            variant="warning"
                            title={t(
                                "orders.last_attempt_failed",
                                "آخر محاولة دفع فشلت",
                            )}
                        >
                            {t("orders.failed_attempt", "محاولة فاشلة")}
                        </Badge>
                    ) : null}
                </div>
            ),
        },
        {
            key: "payment",
            header: t("common.payment", "الدفع"),
            sortable: false,
            hideOnMobile: true,
            cell: (row) => (
                <div className="space-y-0.5 text-sm">
                    {/* Item 10: words, not tokens. This column is read down a queue of
                        orders, so `paymob · card` repeated forty times taught nobody anything. */}
                    {row.paid_via_provider ? (
                        <div>
                            {providerLabel(t, row.paid_via_provider)}
                            {row.paid_via_method
                                ? ` · ${methodLabel(t, row.paid_via_method)}`
                                : ""}
                        </div>
                    ) : (
                        <span className="text-muted-foreground">
                            {/* The legacy chosen-method column, which holds its own vocabulary
                                (`cod`, `cash`, `online`) — the same map covers it. */}
                            {row.payment_method === null
                                ? "—"
                                : methodLabel(t, row.payment_method)}
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: "o.total_price_for_order",
            header: t("common.total", "الإجمالي"),
            sortable: true,
            cell: (row) => (
                <span className="font-medium" dir="ltr">
                    {row.total}
                </span>
            ),
        },
        {
            key: "items",
            header: t("orders.items", "الأصناف"),
            sortable: false,
            hideOnMobile: true,
            cell: (row) => <span>{row.items}</span>,
        },
        {
            key: "storefront",
            header: t("common.storefront", "المتجر"),
            sortable: false,
            hideOnMobile: true,
            cell: (row) => (
                <span className="text-sm">{row.storefront ?? "—"}</span>
            ),
        },
        {
            key: "o.created_at",
            header: t("common.date", "التاريخ"),
            sortable: true,
            hideOnMobile: true,
            cell: (row) => (
                <span className="text-xs text-muted-foreground" dir="ltr">
                    {row.created_at ?? "—"}
                </span>
            ),
        },
    ];

    return (
        <ManageLayout
            title={t("common.orders", "الطلبات")}
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                { label: t("common.orders", "الطلبات") },
            ]}
        >
            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.id}
                searchPlaceholder={t(
                    "orders.search_placeholder",
                    "رقم الطلب أو الهاتف…",
                )}
                emptyTitle={t("orders.empty_title", "لا توجد طلبات")}
                emptyDescription={t(
                    "orders.empty_description",
                    "جرِّب تعديل التصفية أو نطاق التاريخ.",
                )}
                rowActions={(row) => (
                    <Button asChild variant="outline" size="sm">
                        <Link href={row.url}>
                            {t("orders.details", "تفاصيل")}
                        </Link>
                    </Button>
                )}
                filters={(setFilter, current) => (
                    <>
                        <Select
                            className="w-full sm:w-48"
                            aria-label={t("common.storefront", "المتجر")}
                            value={current.storefront_id ?? ""}
                            onChange={(event) =>
                                setFilter(
                                    "storefront_id",
                                    event.target.value || null,
                                )
                            }
                        >
                            <option value="">
                                {t("common.all_storefronts", "كل المتاجر")}
                            </option>
                            {filters.storefronts.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>

                        <Select
                            className="w-full sm:w-48"
                            aria-label={t("common.status", "الحالة")}
                            value={current.status ?? ""}
                            onChange={(event) =>
                                setFilter("status", event.target.value || null)
                            }
                        >
                            <option value="">
                                {t("common.all_statuses", "كل الحالات")}
                            </option>
                            {filters.statuses.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>

                        {/* Provider and method: a failed card attempt never reaches
                            `orders.paid_via_provider`, and the server's filter matches attempts
                            too — which is exactly what someone chasing a provider is looking for. */}
                        <Select
                            className="w-full sm:w-48"
                            aria-label={t(
                                "orders.payment_provider",
                                "مزوّد الدفع",
                            )}
                            value={current.provider ?? ""}
                            onChange={(event) =>
                                setFilter(
                                    "provider",
                                    event.target.value || null,
                                )
                            }
                        >
                            <option value="">
                                {t("orders.all_providers", "كل المزوّدين")}
                            </option>
                            {/* Item 10: the server sends these options with the stored token in
                                BOTH fields, so the filter read `paymob` while the column beside it
                                now reads the company name. */}
                            {filters.providers.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {providerLabel(t, option.value)}
                                </option>
                            ))}
                        </Select>

                        <Select
                            className="w-full sm:w-48"
                            aria-label={t(
                                "orders.payment_method",
                                "طريقة الدفع",
                            )}
                            value={current.method ?? ""}
                            onChange={(event) =>
                                setFilter("method", event.target.value || null)
                            }
                        >
                            <option value="">
                                {t("orders.all_methods", "كل الطرق")}
                            </option>
                            {filters.methods.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {methodLabel(t, option.value)}
                                </option>
                            ))}
                        </Select>

                        {/* An order queue is filtered by "today" and "this week" far more often
                            than by a typed pair of dates, which is why the presets are here
                            (§2.8). */}
                        <DateRangeFilter
                            from={current.from ?? null}
                            to={current.to ?? null}
                            onChange={(from, to) => {
                                setFilter("from", from);
                                setFilter("to", to);
                            }}
                        />
                    </>
                )}
            />

            {/* The settlement export is payment reconciliation, so it is admin-only on the server
                (`can:manage-payments`) and the button only appears for the ability that route
                actually asks for — not for a neighbouring one that happens to be admin-only too. */}
            {abilities.settle ? (
                <div className="pt-4">
                    <Button asChild variant="outline" size="sm">
                        <a href="/manage/orders/export/settlement">
                            {t(
                                "orders.settlement_export",
                                "تصدير تسويات الدفع (CSV)",
                            )}
                        </a>
                    </Button>
                </div>
            ) : null}
        </ManageLayout>
    );
}
