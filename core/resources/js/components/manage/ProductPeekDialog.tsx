import { Fragment } from "react";

import { Dialog, DialogContent } from "@/components/ui/dialog";
import { Badge } from "@/components/ui/badge";
import { Name, Num } from "@/components/ui/bidi";
import { ColourChip, type StoredColour } from "@/components/manage/ColourChip";
import { useLocale, useT } from "@/lib/i18n";
import { localisedTitle, titleOrCode, type TitlePair } from "@/lib/title";

/** One product, in enough detail to confirm it — the shape `App\Domain\Orders\ProductPeek` sends. */
export interface Peek {
    title: TitlePair;
    wa_code: string | null;
    sku: string | null;
    brand: TitlePair;
    family: string;
    family_label: string;
    model_number: string | null;
    cover: string | null;
    specs: Array<{ label: string; value: string }>;
}

/** What the LINE knows, which the product itself does not: which one was sold, and in what. */
export interface PeekLine {
    variant: string | null;
    variant_sku: string | null;
    colours: Array<{ label: string; colour: StoredColour }>;
    quantity: number;
    piece_price: string;
}

/**
 * "Is this the right product?" — answered without leaving the order (item 12, 2026-09-18).
 *
 * ── The problem ──────────────────────────────────────────────────────────────────────────────
 *
 * An order line said "طقم ساعة" and a code. Forty products share that title, so confirming the
 * pick meant opening the product screen in another tab, losing the order, and coming back. The
 * developer's words: *"make the product row open something with enough detail to confirm it's the
 * right product — image, code, variant, specs."*
 *
 * ── What is in here, and why that set ────────────────────────────────────────────────────────
 *
 * The photograph first, because that is what a person actually matches against the thing in their
 * hand. Then the two names, the supplier's code, the model number, the brand — the facts printed
 * on the box. Then the LINE's own facts: the variant the warehouse must pick, the colours it was
 * sold in, the quantity. Then the specifications that the product has, and only those.
 *
 * Empty specification fields are dropped on the server rather than shown blank: a list of twenty
 * rows where fourteen say nothing buries the six that identify the product. The product screen is
 * where a missing specification is visible and fixable, and this panel says so with a link.
 */
export function ProductPeekDialog({
    peek,
    line,
    productUrl,
    open,
    onClose,
}: {
    peek: Peek | null;
    line: PeekLine | null;
    productUrl: string | null;
    open: boolean;
    onClose: () => void;
}) {
    const t = useT();
    const locale = useLocale();

    const title = peek === null ? null : localisedTitle(peek.title, locale);
    const brand = peek === null ? null : localisedTitle(peek.brand, locale);

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? null : onClose())}>
            {peek === null ? null : (
                <DialogContent
                    title={titleOrCode(peek.title, locale, peek.wa_code ?? "")}
                >
                    <div className="space-y-4 text-sm">
                        <div className="flex gap-4">
                            {/* The photograph is the whole point of opening this, so it leads and it
                                is big enough to recognise a dial from. */}
                            <div className="h-32 w-32 shrink-0 overflow-hidden rounded border bg-muted/40">
                                {peek.cover === null ? (
                                    <div className="flex h-full w-full items-center justify-center p-2 text-center text-xs text-muted-foreground">
                                        {t("products.no_image", "بلا صورة")}
                                    </div>
                                ) : (
                                    <img
                                        src={peek.cover}
                                        alt=""
                                        className="h-full w-full object-contain"
                                    />
                                )}
                            </div>

                            <div className="min-w-0 flex-1 space-y-1">
                                <div className="font-medium">
                                    {title !== null && !title.missing ? (
                                        <Name>{title.text}</Name>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {t("products.no_name_either_language", "— بلا اسم —")}
                                        </span>
                                    )}
                                </div>
                                {title !== null && title.secondary !== null ? (
                                    <div className="text-xs text-muted-foreground">
                                        <Name>{title.secondary}</Name>
                                    </div>
                                ) : null}
                                {brand !== null && !brand.missing ? (
                                    <div className="text-xs text-muted-foreground">
                                        <Name>{brand.text}</Name>
                                    </div>
                                ) : null}
                                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 pt-1 text-xs">
                                    <Fact
                                        label={t("products.code", "الكود")}
                                        value={peek.wa_code}
                                        mono
                                    />
                                    <Fact
                                        label={t("products.supplier_code", "كود المورّد")}
                                        value={peek.sku}
                                        mono
                                    />
                                    <Fact
                                        label={t("products.model_number", "رقم الموديل")}
                                        value={peek.model_number}
                                        mono
                                    />
                                </dl>
                            </div>
                        </div>

                        {/* ── what THIS line sold ────────────────────────────────────────── */}
                        {line === null ? null : (
                            <div className="space-y-2 rounded border p-3">
                                <div className="text-xs font-medium text-muted-foreground">
                                    {t("orders.what_was_sold", "المباع في هذا السطر")}
                                </div>
                                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
                                    {line.variant === null ? null : (
                                        <>
                                            <dt className="text-muted-foreground">
                                                {t("orders.variant", "المتغيّر")}
                                            </dt>
                                            <dd>
                                                <Name>{line.variant}</Name>
                                                {line.variant_sku === null ? null : (
                                                    <Num className="ms-2 font-mono text-[11px] text-muted-foreground">
                                                        {line.variant_sku}
                                                    </Num>
                                                )}
                                            </dd>
                                        </>
                                    )}
                                    {line.colours.map(({ label, colour }) => (
                                        // A Fragment with a key, not a bare `<>`: a keyless fragment
                                        // in a list is React's own warning, and a `<div>` wrapper
                                        // here would break the two-column grid the dl draws.
                                        <Fragment key={label}>
                                            <dt className="text-muted-foreground">{label}</dt>
                                            <dd>
                                                <ColourChip colour={colour} />
                                            </dd>
                                        </Fragment>
                                    ))}
                                    <dt className="text-muted-foreground">
                                        {t("common.quantity", "الكمية")}
                                    </dt>
                                    <dd>
                                        <Num>{line.quantity}</Num>
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        {t("orders.unit_price", "سعر الوحدة")}
                                    </dt>
                                    <dd>
                                        <Num>{line.piece_price}</Num>
                                    </dd>
                                </dl>
                            </div>
                        )}

                        {/* ── the specifications the product HAS ─────────────────────────── */}
                        {peek.specs.length === 0 ? (
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    "orders.peek_no_specs",
                                    "لا توجد مواصفات مسجّلة لهذا المنتج. تُضاف من شاشة المنتج.",
                                )}
                            </p>
                        ) : (
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                                {peek.specs.map((spec) => (
                                    <Fragment key={spec.label}>
                                        <dt className="text-muted-foreground">{spec.label}</dt>
                                        <dd>
                                            <Name>{spec.value}</Name>
                                        </dd>
                                    </Fragment>
                                ))}
                            </dl>
                        )}

                        {productUrl === null ? (
                            <p className="text-xs text-muted-foreground">
                                {/* Not an error: a data-entry operator without the catalogue grant
                                    gets the facts and no link, rather than a link that 403s. */}
                                {t(
                                    "orders.peek_no_link",
                                    "فتح صفحة المنتج للتعديل يحتاج صلاحية الكتالوج.",
                                )}
                            </p>
                        ) : (
                            <a
                                href={productUrl}
                                className="inline-block text-xs font-medium text-brand-strong underline-offset-2 hover:underline"
                            >
                                {t("orders.peek_open_product", "فتح صفحة المنتج")}
                            </a>
                        )}

                        <Badge variant="neutral" className="text-[10px]">
                            {peek.family_label}
                        </Badge>
                    </div>
                </DialogContent>
            )}
        </Dialog>
    );
}

/** One `label: value` pair, dropped entirely when there is no value. */
function Fact({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string | null;
    mono?: boolean;
}) {
    if (value === null || value === "") {
        return null;
    }

    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{mono ? <Num className="font-mono">{value}</Num> : <Name>{value}</Name>}</dd>
        </>
    );
}
