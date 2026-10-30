import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Num } from "@/components/ui/bidi";
import { useLocale, useT } from "@/lib/i18n";
import { changedFieldLabel } from "@/lib/labels";

/*
 * The product's HISTORY (M1w, 2026-10-30): who created it, who changed its price, who changed its stock, and when —
 * the activity log's rows for the product and its variants, and every movement of its stock from the ledger (a
 * person's adjustment, an order, a cancellation, an import), newest first. Read-only; times are Cairo time.
 */

export interface HistoryEntry {
    at: string | null;
    who: string;
    source: "product" | "variant" | "stock";
    action: string;
    changes: { field: string; from: unknown; to: unknown }[];
    note: string | null;
}

function show(value: unknown): string {
    if (value === null || value === undefined || value === "") {
        return "—";
    }
    if (typeof value === "boolean") {
        return value ? "✓" : "✗";
    }
    return typeof value === "object" ? "…" : String(value);
}

export function ProductHistory({ entries }: { entries: HistoryEntry[] }) {
    const t = useT();
    const arrow = useLocale() === "ar" ? "←" : "→";

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t("products.history", "السجل")}</CardTitle>
                <p className="text-sm text-muted-foreground">
                    {t(
                        "products.history_intro",
                        "كل ما حدث لهذا المنتج: الإنشاء والتعديلات وتغييرات السعر، وكل حركة في مخزونه — من أحد الفريق أو من النظام (طلب، إلغاء، استيراد). الأوقات بتوقيت القاهرة.",
                    )}
                </p>
            </CardHeader>
            <CardContent>
                {entries.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t("products.history_empty", "لا يوجد شيء مسجّل لهذا المنتج بعد.")}</p>
                ) : (
                    <ol className="divide-y">
                        {entries.map((entry, index) => (
                            <li key={index} className="flex flex-col gap-1 py-2 text-sm sm:flex-row sm:gap-4">
                                <div className="w-40 shrink-0 text-xs text-muted-foreground">
                                    <Num>{entry.at ?? "—"}</Num>
                                    <div>{entry.who}</div>
                                </div>
                                <div className="min-w-0 flex-1 space-y-1">
                                    <div className="flex flex-wrap items-center gap-1">
                                        <Badge variant={entry.source === "stock" ? "neutral" : "default"}>{entry.action}</Badge>
                                        {entry.source === "variant" && (
                                            <Badge variant="neutral">{t("activity.type_variant", "متغيّر")}</Badge>
                                        )}
                                        {entry.note && <span className="truncate text-xs text-muted-foreground">{entry.note}</span>}
                                    </div>
                                    {entry.changes.length > 0 && (
                                        <ul className="space-y-0.5 text-xs">
                                            {entry.changes.map((change) => (
                                                <li key={change.field} className="flex flex-wrap items-baseline gap-1">
                                                    <span className="font-medium">{changedFieldLabel(t, change.field)}</span>
                                                    <span className="break-all">{show(change.from)}</span>
                                                    <span className="text-muted-foreground">{arrow}</span>
                                                    <span className="break-all">{show(change.to)}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
