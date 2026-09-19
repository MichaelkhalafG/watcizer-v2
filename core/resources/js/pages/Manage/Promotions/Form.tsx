import { router, useForm } from "@inertiajs/react";
import { useCallback, useEffect, useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input, Select } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import ManageLayout from "@/layouts/ManageLayout";
import { Ltr } from "@/components/ui/bidi";
import { useT } from "@/lib/i18n";
import { bucketLabel } from "@/lib/labels";

/**
 * Authoring a promotion (wave 4D, study §3.16.6).
 *
 * ── Two things this screen exists to prevent ─────────────────────────────────────────────────
 *
 * 1. **An admin publishing three promotions expecting three discounts.** Rules do not stack. The
 *    sentence is on the page, above the fields, in the operator's own language — and it comes from
 *    the server so this screen and the list cannot drift into saying different things.
 *
 * 2. **An admin approving a gift without seeing what it costs the shelf.** The sample cart shows
 *    BOTH outcomes: what the customer pays, and what stock leaves the shop, per order. A promotion
 *    is a stock decision, and "buy 2 get 1 free" means a third unit off the shelf on every order
 *    that qualifies — which is easy to agree to and hard to picture.
 *
 * Products are chosen by SEARCH, never by a typed id: an admin who has to look an id up in another
 * tab will eventually type the wrong one, and the wrong one here means the shop gives away the
 * wrong thing.
 */

interface Storefront {
    id: number;
    name: string;
    /** Does this storefront's frontend show a promotion-aware total? */
    money_rewards?: boolean;
}

interface TypeOption {
    value: string;
    label: string;
    /** True for the three types that reduce what the customer pays. */
    money?: boolean;
    /** Storefront ids this type CAN be delivered on, and the ones it cannot. */
    live_on?: number[];
    blocked_on?: number[];
    available?: boolean;
    reason?: string | null;
}

interface ProductHit {
    id: number;
    title: string;
    wa_code: string | null;
    price: number;
    stock_express: number;
    stock_market: number;
    is_visible: boolean;
    cover: string | null;
}

interface ConditionRow {
    type: string;
    product_id: number | null;
    variant_id: number | null;
    storefront_category_id: number | null;
    brand_id: number | null;
    quantity: number | null;
    amount: string | null;
    methods: string | null;
}

interface RewardRow {
    type: string;
    product_id: number | null;
    variant_id: number | null;
    quantity: number;
    /** The money reward's value: a RATE for percent_discount, EGP for fixed_discount. */
    amount: string | null;
    product?: {
        id: number;
        title: string;
        wa_code: string | null;
        stock_express: number;
        stock_market: number;
    } | null;
}

interface RuleState {
    state: string;
    label: string;
    tone: string;
    skips: {
        stock: number;
        visibility: Record<number, number>;
        last_at: string | null;
    };
}

interface Rule {
    id: number;
    name: string;
    priority: number;
    is_active: boolean;
    starts_at: string | null;
    ends_at: string | null;
    storefronts: number[];
    conditions: ConditionRow[];
    rewards: RewardRow[];
    state: RuleState;
}

interface PreviewStockRow {
    product_id: number | null;
    title: string;
    quantity: number;
    bucket: string;
    stock_before: number;
    stock_after: number;
}

interface Preview {
    ok: boolean;
    applies: boolean;
    winner: "draft" | "other_rule" | null;
    customer: {
        subtotal: number;
        shipping: number;
        /** What the cart came to before any promotion — the number the frontend quotes. */
        total_before: number;
        discount: number;
        /** What the order will actually be written for. */
        total: number;
        free_shipping: boolean;
        total_unchanged: boolean;
    };
    stock: PreviewStockRow[];
    skipped: { id: number; name: string; reason: string; label: string }[];
    notice: string;
}

interface Props {
    rule: Rule | null;
    storefronts: Storefront[];
    condition_types: TypeOption[];
    reward_types: TypeOption[];
    stacking_notice: string;
    max_reward_quantity: number;
}

/** One line of the sample cart the admin builds by searching. */
interface SampleLine {
    product: ProductHit;
    quantity: number;
}

const emptyCondition = (): ConditionRow => ({
    type: "cart_subtotal_min",
    product_id: null,
    variant_id: null,
    storefront_category_id: null,
    brand_id: null,
    quantity: null,
    amount: null,
    methods: null,
});

const emptyReward = (): RewardRow => ({
    type: "free_product",
    product_id: null,
    variant_id: null,
    quantity: 1,
    amount: null,
});

/** Search box + results, shared by the reward picker and the sample cart. */
function ProductSearch({
    storefrontId,
    onPick,
    label,
}: {
    storefrontId: number;
    onPick: (product: ProductHit) => void;
    label: string;
}) {
    const t = useT();
    const [term, setTerm] = useState("");
    const [hits, setHits] = useState<ProductHit[]>([]);
    const [busy, setBusy] = useState(false);

    const search = useCallback(async () => {
        if (term.trim().length < 2) {
            setHits([]);

            return;
        }
        setBusy(true);
        try {
            const response = await fetch(
                `/manage/promotions/products?q=${encodeURIComponent(term)}&storefront_id=${storefrontId}`,
                {
                    headers: { Accept: "application/json" },
                    credentials: "same-origin",
                },
            );
            const payload: unknown = await response.json();
            const data =
                typeof payload === "object" &&
                payload !== null &&
                "data" in payload
                    ? ((payload as { data: ProductHit[] }).data ?? [])
                    : [];
            setHits(data);
        } catch {
            setHits([]);
        } finally {
            setBusy(false);
        }
    }, [term, storefrontId]);

    useEffect(() => {
        const timer = setTimeout(() => void search(), 300);

        return () => clearTimeout(timer);
    }, [search]);

    return (
        <div className="space-y-2">
            <Label>{label}</Label>
            <Input
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                placeholder={t(
                    "promotions.search_placeholder",
                    "اكتب اسم المنتج أو كوده…",
                )}
            />
            {busy ? (
                <p className="text-xs text-muted-foreground">
                    {t("promotions.searching", "جاري البحث…")}
                </p>
            ) : null}
            {hits.length > 0 ? (
                <ul className="max-h-60 divide-y overflow-y-auto rounded-md border">
                    {hits.map((hit) => (
                        <li key={hit.id}>
                            <button
                                type="button"
                                className="flex w-full items-center gap-3 p-2 text-right hover:bg-muted"
                                onClick={() => {
                                    onPick(hit);
                                    setTerm("");
                                    setHits([]);
                                }}
                            >
                                {hit.cover ? (
                                    <img
                                        src={hit.cover}
                                        alt=""
                                        className="size-10 rounded object-contain"
                                    />
                                ) : (
                                    <span className="size-10 rounded bg-muted" />
                                )}
                                <span className="flex-1 space-y-0.5">
                                    <span className="block text-sm">
                                        {hit.title}
                                    </span>
                                    <span
                                        className="block text-xs text-muted-foreground"
                                        dir="ltr"
                                    >
                                        {hit.wa_code ?? "—"} · {hit.price} EGP ·
                                        express {hit.stock_express} / market{" "}
                                        {hit.stock_market}
                                    </span>
                                </span>
                                {/* Said in the picker, so a gift that can never be given is visible before saving. */}
                                {hit.is_visible ? null : (
                                    <Badge variant="destructive">
                                        {t(
                                            "promotions.not_visible",
                                            "غير معروض",
                                        )}
                                    </Badge>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

export default function PromotionForm({
    rule,
    storefronts,
    condition_types,
    reward_types,
    stacking_notice,
    max_reward_quantity,
}: Props) {
    const t = useT();
    const form = useForm({
        name: rule?.name ?? "",
        priority: rule?.priority ?? 0,
        is_active: rule?.is_active ?? false,
        starts_at: rule?.starts_at?.slice(0, 16) ?? "",
        ends_at: rule?.ends_at?.slice(0, 16) ?? "",
        storefronts:
            rule?.storefronts ??
            [storefronts[0]?.id].filter(
                (id): id is number => typeof id === "number",
            ),
        conditions: rule?.conditions ?? [emptyCondition()],
        rewards: rule?.rewards ?? [emptyReward()],
    });

    const [sample, setSample] = useState<SampleLine[]>([]);
    const [method, setMethod] = useState("cash");
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewing, setPreviewing] = useState(false);

    const storefrontId = form.data.storefronts[0] ?? storefronts[0]?.id ?? 1;

    /*
     * Which of the CHOSEN storefronts can (and cannot) deliver a reward type.
     *
     * Scoped to the rule's own storefronts rather than to every storefront in the shop: a warning
     * about a storefront this rule does not run on is noise, and noise on a money screen is how a
     * real warning stops being read. The server computed the per-storefront lists; this only
     * intersects them with what the admin has ticked.
     */
    const chosen = (ids: number[] | undefined): Storefront[] =>
        storefronts.filter(
            (storefront) =>
                form.data.storefronts.includes(storefront.id) &&
                (ids ?? []).includes(storefront.id),
        );

    const blockedStorefronts = (type: TypeOption): Storefront[] =>
        chosen(type.blocked_on);

    const liveStorefronts = (type: TypeOption): Storefront[] =>
        chosen(type.live_on);

    const isMoneyType = (value: string): boolean =>
        reward_types.find((type) => type.value === value)?.money === true;

    const runPreview = async () => {
        setPreviewing(true);
        try {
            const response = await fetch("/manage/promotions/preview", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN":
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? "",
                },
                credentials: "same-origin",
                body: JSON.stringify({
                    storefront_id: storefrontId,
                    payment_method: method,
                    lines: sample.map((line) => ({
                        product_id: line.product.id,
                        quantity: line.quantity,
                    })),
                    conditions: form.data.conditions,
                    rewards: form.data.rewards,
                }),
            });
            const payload = (await response.json()) as Preview;
            setPreview(payload);
        } catch {
            setPreview(null);
        } finally {
            setPreviewing(false);
        }
    };

    const submit = () => {
        if (rule === null) {
            form.post("/manage/promotions");
        } else {
            form.put(`/manage/promotions/${rule.id}`);
        }
    };

    return (
        <ManageLayout
            title={
                rule === null
                    ? t("promotions.new_title", "عرض ترويجي جديد")
                    : rule.name
            }
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                {
                    label: t("promotions.title", "العروض الترويجية"),
                    href: "/manage/promotions",
                },
                {
                    label:
                        rule === null
                            ? t("promotions.crumb_new", "جديد")
                            : t("common.edit", "تعديل"),
                },
            ]}
            actions={
                <div className="flex gap-2">
                    {rule !== null ? (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.delete(`/manage/promotions/${rule.id}`)
                            }
                        >
                            {t("common.delete", "حذف")}
                        </Button>
                    ) : null}
                    <Button onClick={submit} disabled={form.processing}>
                        {t("common.save", "حفظ")}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4">
                <div
                    className="rounded-lg border border-amber-300/60 bg-amber-50/60 p-4 text-sm leading-relaxed dark:border-amber-900/50 dark:bg-amber-950/20"
                    role="note"
                >
                    {stacking_notice}
                </div>

                {/* When editing: what this rule is actually doing, without leaving the page. */}
                {rule !== null ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge
                            variant={
                                rule.state.tone === "success"
                                    ? "success"
                                    : rule.state.tone === "destructive"
                                      ? "destructive"
                                      : "neutral"
                            }
                        >
                            {rule.state.label}
                        </Badge>
                        {rule.state.skips.stock > 0 ? (
                            <span className="text-xs text-destructive">
                                {t(
                                    "promotions.gift_missed",
                                    "فات العميل الهدية :count مرة لعدم توفر المخزون",
                                    {
                                        count: rule.state.skips.stock,
                                    },
                                )}
                            </span>
                        ) : null}
                    </div>
                ) : null}

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* ── the rule ─────────────────────────────────────────────────────────── */}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t("promotions.rule", "القاعدة")}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="name">
                                    {t("common.name", "الاسم")}
                                </Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData("name", event.target.value)
                                    }
                                />
                                {form.errors.name ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.name}
                                    </p>
                                ) : null}
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="starts_at">
                                        {t("common.starts", "يبدأ")}
                                    </Label>
                                    <Input
                                        id="starts_at"
                                        type="datetime-local"
                                        value={form.data.starts_at}
                                        onChange={(event) =>
                                            form.setData(
                                                "starts_at",
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="ends_at">
                                        {t("common.ends", "ينتهي")}
                                    </Label>
                                    <Input
                                        id="ends_at"
                                        type="datetime-local"
                                        value={form.data.ends_at}
                                        onChange={(event) =>
                                            form.setData(
                                                "ends_at",
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.ends_at ? (
                                        <p className="text-xs text-destructive">
                                            {form.errors.ends_at}
                                        </p>
                                    ) : null}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="priority">
                                    {t(
                                        "promotions.priority",
                                        "الأولوية (الأعلى تفوز)",
                                    )}
                                </Label>
                                <Input
                                    id="priority"
                                    type="number"
                                    value={String(form.data.priority)}
                                    onChange={(event) =>
                                        form.setData(
                                            "priority",
                                            Number(event.target.value),
                                        )
                                    }
                                />
                                {form.errors.priority ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.priority}
                                    </p>
                                ) : null}
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.is_active}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            "is_active",
                                            checked === true,
                                        )
                                    }
                                />
                                {t("promotions.active", "مفعّلة")}
                            </label>

                            <div className="space-y-2">
                                <Label>
                                    {t("common.storefronts", "المتاجر")}
                                </Label>
                                {/*
                                 * Checkboxes, like product placement: a rule is authored ONCE and the
                                 * operator ticks where it applies (developer decision 2026-09-13).
                                 */}
                                <div className="flex flex-wrap gap-3">
                                    {storefronts.map((storefront) => (
                                        <label
                                            key={storefront.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={form.data.storefronts.includes(
                                                    storefront.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        "storefronts",
                                                        checked === true
                                                            ? [
                                                                  ...form.data
                                                                      .storefronts,
                                                                  storefront.id,
                                                              ]
                                                            : form.data.storefronts.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      storefront.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {storefront.name}
                                            {/*
                                             * Marked on the storefront itself as well as on the
                                             * reward row, because the two answer different
                                             * questions: "why is my discount greyed out" and "which
                                             * of these can take one at all".
                                             */}
                                            {storefront.money_rewards ===
                                            false ? (
                                                <Badge variant="neutral">
                                                    {t(
                                                        "promotions.no_money_rewards",
                                                        "بدون خصومات",
                                                    )}
                                                </Badge>
                                            ) : null}
                                        </label>
                                    ))}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        "promotions.money_rewards_hint",
                                        "المتاجر المعلَّمة «بدون خصومات» تقبل الهدايا فقط: واجهتها لا تعرض خصمًا، فلو غيّر العرض المبلغ لظهر للعميل رقم وحُوسب على غيره.",
                                    )}
                                </p>
                                {form.errors.storefronts ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.storefronts}
                                    </p>
                                ) : null}
                            </div>
                        </CardContent>
                    </Card>

                    {/* ── conditions and rewards ───────────────────────────────────────────── */}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t(
                                    "promotions.conditions_and_rewards",
                                    "الشروط والمكافآت",
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <div className="space-y-3">
                                <Label>
                                    {t(
                                        "promotions.conditions",
                                        "الشروط (كلها لازم تتحقق)",
                                    )}
                                </Label>
                                {form.data.conditions.map(
                                    (condition, index) => (
                                        <div
                                            key={index}
                                            className="grid gap-2 rounded-md border p-3 sm:grid-cols-2"
                                        >
                                            <Select
                                                aria-label={t(
                                                    "promotions.condition_type",
                                                    "نوع الشرط",
                                                )}
                                                value={condition.type}
                                                onChange={(event) => {
                                                    const next = [
                                                        ...form.data.conditions,
                                                    ];
                                                    next[index] = {
                                                        ...condition,
                                                        type: event.target
                                                            .value,
                                                    };
                                                    form.setData(
                                                        "conditions",
                                                        next,
                                                    );
                                                }}
                                            >
                                                {condition_types.map((type) => (
                                                    <option
                                                        key={type.value}
                                                        value={type.value}
                                                    >
                                                        {type.label}
                                                    </option>
                                                ))}
                                            </Select>

                                            {condition.type ===
                                            "cart_subtotal_min" ? (
                                                <Input
                                                    type="number"
                                                    placeholder={t(
                                                        "promotions.amount",
                                                        "المبلغ",
                                                    )}
                                                    value={
                                                        condition.amount ?? ""
                                                    }
                                                    onChange={(event) => {
                                                        const next = [
                                                            ...form.data
                                                                .conditions,
                                                        ];
                                                        next[index] = {
                                                            ...condition,
                                                            amount: event.target
                                                                .value,
                                                        };
                                                        form.setData(
                                                            "conditions",
                                                            next,
                                                        );
                                                    }}
                                                />
                                            ) : (
                                                <Input
                                                    type="number"
                                                    placeholder={t(
                                                        "common.quantity",
                                                        "الكمية",
                                                    )}
                                                    value={
                                                        condition.quantity ===
                                                        null
                                                            ? ""
                                                            : String(
                                                                  condition.quantity,
                                                              )
                                                    }
                                                    onChange={(event) => {
                                                        const next = [
                                                            ...form.data
                                                                .conditions,
                                                        ];
                                                        next[index] = {
                                                            ...condition,
                                                            quantity: Number(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        };
                                                        form.setData(
                                                            "conditions",
                                                            next,
                                                        );
                                                    }}
                                                />
                                            )}
                                        </div>
                                    ),
                                )}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        form.setData("conditions", [
                                            ...form.data.conditions,
                                            emptyCondition(),
                                        ])
                                    }
                                >
                                    {t("promotions.add_condition", "إضافة شرط")}
                                </Button>
                                {form.errors.conditions ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.conditions}
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-3">
                                <Label>
                                    {t("promotions.rewards", "المكافآت")}
                                </Label>
                                {form.data.rewards.map((reward, index) => (
                                    <div
                                        key={index}
                                        className="space-y-2 rounded-md border p-3"
                                    >
                                        <Select
                                            aria-label={t(
                                                "promotions.reward_type",
                                                "نوع المكافأة",
                                            )}
                                            value={reward.type}
                                            onChange={(event) => {
                                                const next = [
                                                    ...form.data.rewards,
                                                ];
                                                next[index] = {
                                                    ...reward,
                                                    type: event.target.value,
                                                };
                                                form.setData("rewards", next);
                                            }}
                                        >
                                            {/*
                                             * Types the CHOSEN storefronts cannot deliver are still
                                             * OFFERED, and disabled only when no chosen storefront can
                                             * take them: an admin who cannot find "percentage discount"
                                             * will ask whether it exists; one who sees it greyed out
                                             * with the storefronts named has their answer.
                                             */}
                                            {reward_types.map((type) => (
                                                <option
                                                    key={type.value}
                                                    value={type.value}
                                                    disabled={
                                                        blockedStorefronts(type)
                                                            .length > 0 &&
                                                        liveStorefronts(type)
                                                            .length === 0
                                                    }
                                                >
                                                    {type.label}
                                                    {blockedStorefronts(type)
                                                        .length > 0
                                                        ? t(
                                                              "promotions.not_live_on",
                                                              " — لا يعمل على: :names",
                                                              {
                                                                  names: blockedStorefronts(
                                                                      type,
                                                                  )
                                                                      .map(
                                                                          (s) =>
                                                                              s.name,
                                                                      )
                                                                      .join(
                                                                          t(
                                                                              "common.list_separator",
                                                                              "، ",
                                                                          ),
                                                                      ),
                                                              },
                                                          )
                                                        : ""}
                                                </option>
                                            ))}
                                        </Select>

                                        {(() => {
                                            const option = reward_types.find(
                                                (candidate) =>
                                                    candidate.value ===
                                                    reward.type,
                                            );
                                            const blocked = option
                                                ? blockedStorefronts(option)
                                                : [];

                                            return blocked.length > 0 ? (
                                                <p className="text-xs text-destructive">
                                                    {t(
                                                        "promotions.reward_blocked_on",
                                                        "هذه المكافأة تغيّر المبلغ المستحق، ولا تعمل على: :names — لأن واجهة المتجر لا تعرض الخصم. استبعد المتجر من هذه القاعدة، أو فعّل الإعداد له.",
                                                        {
                                                            names: blocked
                                                                .map(
                                                                    (s) =>
                                                                        s.name,
                                                                )
                                                                .join(
                                                                    t(
                                                                        "common.list_separator",
                                                                        "، ",
                                                                    ),
                                                                ),
                                                        },
                                                    )}
                                                </p>
                                            ) : null;
                                        })()}

                                        {isMoneyType(reward.type) ? (
                                            /*
                                             * A money reward has no gift and no quantity — only a
                                             * value, and `free_shipping` has not even got that: it
                                             * waives whatever the cart's shipping happens to be.
                                             */
                                            reward.type === "free_shipping" ? (
                                                <p className="text-xs text-muted-foreground">
                                                    {t(
                                                        "promotions.free_shipping_hint",
                                                        "يُلغي تكلفة الشحن مهما كانت، فلا يحتاج مبلغًا.",
                                                    )}
                                                </p>
                                            ) : (
                                                <div className="space-y-1">
                                                    <Label>
                                                        {reward.type ===
                                                        "percent_discount"
                                                            ? t(
                                                                  "promotions.percent_amount",
                                                                  "النسبة (%)",
                                                              )
                                                            : t(
                                                                  "promotions.fixed_amount",
                                                                  "المبلغ (جنيه)",
                                                              )}
                                                    </Label>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        max={
                                                            reward.type ===
                                                            "percent_discount"
                                                                ? "100"
                                                                : undefined
                                                        }
                                                        value={
                                                            reward.amount ?? ""
                                                        }
                                                        onChange={(event) => {
                                                            const next = [
                                                                ...form.data
                                                                    .rewards,
                                                            ];
                                                            next[index] = {
                                                                ...reward,
                                                                amount:
                                                                    event.target
                                                                        .value ===
                                                                    ""
                                                                        ? null
                                                                        : event
                                                                              .target
                                                                              .value,
                                                            };
                                                            form.setData(
                                                                "rewards",
                                                                next,
                                                            );
                                                        }}
                                                    />
                                                    <p className="text-xs text-muted-foreground">
                                                        {t(
                                                            "promotions.discount_clamp_hint",
                                                            "الخصم لا يتجاوز قيمة السلة: عرض أكبر من الطلب يجعله مجانيًا، ولا يتحول إلى مبلغ مُعاد.",
                                                        )}
                                                    </p>
                                                </div>
                                            )
                                        ) : reward.product ? (
                                            <div className="flex items-center justify-between rounded bg-muted p-2 text-sm">
                                                <span>
                                                    {reward.product.title}
                                                    <span
                                                        className="block text-xs text-muted-foreground"
                                                        dir="ltr"
                                                    >
                                                        express{" "}
                                                        {
                                                            reward.product
                                                                .stock_express
                                                        }{" "}
                                                        / market{" "}
                                                        {
                                                            reward.product
                                                                .stock_market
                                                        }
                                                    </span>
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => {
                                                        const next = [
                                                            ...form.data
                                                                .rewards,
                                                        ];
                                                        next[index] = {
                                                            ...reward,
                                                            product_id: null,
                                                            product: null,
                                                        };
                                                        form.setData(
                                                            "rewards",
                                                            next,
                                                        );
                                                    }}
                                                >
                                                    {t(
                                                        "promotions.change_product",
                                                        "تغيير",
                                                    )}
                                                </Button>
                                            </div>
                                        ) : (
                                            <ProductSearch
                                                storefrontId={storefrontId}
                                                label={t(
                                                    "promotions.gift",
                                                    "الهدية",
                                                )}
                                                onPick={(hit) => {
                                                    const next = [
                                                        ...form.data.rewards,
                                                    ];
                                                    next[index] = {
                                                        ...reward,
                                                        product_id: hit.id,
                                                        product: {
                                                            id: hit.id,
                                                            title: hit.title,
                                                            wa_code:
                                                                hit.wa_code,
                                                            stock_express:
                                                                hit.stock_express,
                                                            stock_market:
                                                                hit.stock_market,
                                                        },
                                                    };
                                                    form.setData(
                                                        "rewards",
                                                        next,
                                                    );
                                                }}
                                            />
                                        )}

                                        {/* A discount has no quantity; showing the box would invite
                                            an operator to set one that nothing reads. */}
                                        <div
                                            className="space-y-1"
                                            hidden={isMoneyType(reward.type)}
                                        >
                                            <Label>
                                                {t(
                                                    "promotions.reward_quantity",
                                                    "الكمية (حتى :max)",
                                                    {
                                                        max: max_reward_quantity,
                                                    },
                                                )}
                                            </Label>
                                            <Input
                                                type="number"
                                                value={String(reward.quantity)}
                                                onChange={(event) => {
                                                    const next = [
                                                        ...form.data.rewards,
                                                    ];
                                                    next[index] = {
                                                        ...reward,
                                                        quantity: Number(
                                                            event.target.value,
                                                        ),
                                                    };
                                                    form.setData(
                                                        "rewards",
                                                        next,
                                                    );
                                                }}
                                            />
                                        </div>
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        form.setData("rewards", [
                                            ...form.data.rewards,
                                            emptyReward(),
                                        ])
                                    }
                                >
                                    {t("promotions.add_reward", "إضافة مكافأة")}
                                </Button>
                                {form.errors.rewards ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.rewards}
                                    </p>
                                ) : null}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* ── THE SAMPLE CART: both outcomes, before saving ────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t(
                                "promotions.sample_cart_title",
                                "جرّب العرض على سلة حقيقية",
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            {t(
                                "promotions.sample_cart_intro",
                                "العرض قرار مخزون قبل أن يكون قرار سعر.",
                            )}
                        </p>

                        <ProductSearch
                            storefrontId={storefrontId}
                            label={t(
                                "promotions.add_to_sample_cart",
                                "أضف منتجًا إلى السلة التجريبية",
                            )}
                            onPick={(hit) =>
                                setSample((lines) => [
                                    ...lines,
                                    { product: hit, quantity: 1 },
                                ])
                            }
                        />

                        {sample.length > 0 ? (
                            <ul className="divide-y rounded-md border">
                                {sample.map((line, index) => (
                                    <li
                                        key={`${line.product.id}-${index}`}
                                        className="flex items-center gap-3 p-2"
                                    >
                                        <span className="flex-1 text-sm">
                                            {line.product.title}
                                        </span>
                                        <Input
                                            type="number"
                                            className="w-20"
                                            value={String(line.quantity)}
                                            onChange={(event) =>
                                                setSample((lines) =>
                                                    lines.map((l, i) =>
                                                        i === index
                                                            ? {
                                                                  ...l,
                                                                  quantity:
                                                                      Number(
                                                                          event
                                                                              .target
                                                                              .value,
                                                                      ),
                                                              }
                                                            : l,
                                                    ),
                                                )
                                            }
                                        />
                                        <span
                                            className="text-xs text-muted-foreground"
                                            dir="ltr"
                                        >
                                            {line.product.price} EGP
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setSample((lines) =>
                                                    lines.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            {t("common.remove", "إزالة")}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        ) : null}

                        <div className="flex flex-wrap items-end gap-3">
                            <div className="space-y-1">
                                <Label>
                                    {t(
                                        "promotions.payment_method",
                                        "طريقة الدفع",
                                    )}
                                </Label>
                                <Select
                                    value={method}
                                    onChange={(event) =>
                                        setMethod(event.target.value)
                                    }
                                >
                                    <option value="cash">
                                        {t(
                                            "promotions.method_cash",
                                            "الدفع عند الاستلام",
                                        )}
                                    </option>
                                    <option value="paymob">
                                        {t("promotions.method_card", "بطاقة")}
                                    </option>
                                    <option value="whatsapp">
                                        {t(
                                            "promotions.method_whatsapp",
                                            "واتساب",
                                        )}
                                    </option>
                                </Select>
                            </div>
                            <Button
                                type="button"
                                onClick={() => void runPreview()}
                                disabled={previewing || sample.length === 0}
                            >
                                {previewing
                                    ? t("promotions.trying", "جاري التجربة…")
                                    : t("promotions.try", "جرّب")}
                            </Button>
                        </div>

                        {preview !== null ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {/* HALF ONE: what the customer pays. */}
                                <div className="space-y-2 rounded-md border p-3">
                                    <h3 className="text-sm font-semibold">
                                        {t(
                                            "promotions.customer_pays",
                                            "ما يدفعه العميل",
                                        )}
                                    </h3>
                                    <dl className="space-y-1 text-sm">
                                        <div className="flex justify-between">
                                            <dt className="text-muted-foreground">
                                                {t(
                                                    "promotions.subtotal",
                                                    "المجموع",
                                                )}
                                            </dt>
                                            <dd>
                                                <Ltr>
                                                    {preview.customer.subtotal}{" "}
                                                    EGP
                                                </Ltr>
                                            </dd>
                                        </div>
                                        <div className="flex justify-between">
                                            <dt className="text-muted-foreground">
                                                {t(
                                                    "promotions.shipping",
                                                    "الشحن",
                                                )}
                                            </dt>
                                            <dd>
                                                <Ltr>
                                                    {preview.customer.shipping}{" "}
                                                    EGP
                                                </Ltr>
                                            </dd>
                                        </div>
                                        {preview.customer.discount > 0 ? (
                                            <div className="flex justify-between text-success">
                                                <dt>
                                                    {preview.customer
                                                        .free_shipping
                                                        ? t(
                                                              "promotions.discount_shipping",
                                                              "خصم (شحن مجاني)",
                                                          )
                                                        : t(
                                                              "promotions.discount",
                                                              "الخصم",
                                                          )}
                                                </dt>
                                                <dd>
                                                    <Ltr>
                                                        −
                                                        {
                                                            preview.customer
                                                                .discount
                                                        }{" "}
                                                        EGP
                                                    </Ltr>
                                                </dd>
                                            </div>
                                        ) : null}
                                        <div className="flex justify-between font-semibold">
                                            <dt>
                                                {t("common.total", "الإجمالي")}
                                            </dt>
                                            <dd>
                                                <Ltr>
                                                    {preview.customer.total} EGP
                                                </Ltr>
                                            </dd>
                                        </div>
                                    </dl>
                                    {preview.customer.total_unchanged ? (
                                        <p className="text-xs text-muted-foreground">
                                            {t(
                                                "promotions.total_unchanged",
                                                "الإجمالي لا يتغيّر بالعرض: الهدية مجانية، ولذلك لا يفشل الدفع.",
                                            )}
                                        </p>
                                    ) : (
                                        /*
                                         * The sentence an admin needs before they trust a discount:
                                         * the shop charges the lower number, and the storefront has
                                         * to be one that SHOWS it — otherwise the customer is
                                         * quoted one figure and billed another.
                                         */
                                        <p className="text-xs text-muted-foreground">
                                            {t(
                                                "promotions.total_reduced",
                                                "العميل يُحاسب على :total بدلًا من :before. لا يظهر هذا الخصم إلا على متجر تعرض واجهته العروض.",
                                                {
                                                    total: preview.customer
                                                        .total,
                                                    before: preview.customer
                                                        .total_before,
                                                },
                                            )}
                                        </p>
                                    )}
                                </div>

                                {/* HALF TWO: what leaves the shop. The half usually omitted. */}
                                <div className="space-y-2 rounded-md border p-3">
                                    <h3 className="text-sm font-semibold">
                                        {t(
                                            "promotions.stock_leaving",
                                            "ما يخرج من المخزون",
                                        )}
                                    </h3>
                                    {preview.applies &&
                                    preview.stock.length > 0 ? (
                                        <ul className="space-y-2 text-sm">
                                            {preview.stock.map((row, index) => (
                                                <li
                                                    key={index}
                                                    className="space-y-0.5"
                                                >
                                                    <div>
                                                        {row.title} ×{" "}
                                                        {row.quantity}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        <Ltr>
                                                            {bucketLabel(t, row.bucket)}:{" "}
                                                            {row.stock_before} →{" "}
                                                            {row.stock_after}{" "}
                                                            {t(
                                                                "promotions.per_order",
                                                                "(لكل طلب)",
                                                            )}
                                                        </Ltr>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            {t(
                                                "promotions.does_not_apply",
                                                "لا شيء — العرض لا ينطبق على هذه السلة.",
                                            )}
                                        </p>
                                    )}

                                    {preview.winner === "other_rule" ? (
                                        <p className="text-xs text-destructive">
                                            {t(
                                                "promotions.other_rule_won",
                                                "قاعدة أخرى ذات أولوية أعلى هي التي طُبِّقت على هذه السلة، وليست القاعدة التي تحرّرها.",
                                            )}
                                        </p>
                                    ) : null}

                                    {preview.skipped.map((skip) => (
                                        <p
                                            key={skip.id}
                                            className="text-xs text-destructive"
                                        >
                                            «{skip.name}»: {skip.label}
                                        </p>
                                    ))}
                                </div>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
        </ManageLayout>
    );
}
