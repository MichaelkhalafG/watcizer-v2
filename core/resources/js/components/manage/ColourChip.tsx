import { Name, Num } from "@/components/ui/bidi";
import { useLocale, useT } from "@/lib/i18n";
import { localisedTitle, type TitlePair } from "@/lib/title";

/** What an order line stores for a colour: the hex it was sold as, and the catalogue's name for it. */
export interface StoredColour {
    hex: string;
    name: TitlePair | null;
}

/**
 * A colour, as a person recognises one (item 12, 2026-09-18).
 *
 * ── What was wrong ───────────────────────────────────────────────────────────────────────────
 *
 * `order_items.color_band` and `color_dial` hold what the legacy cart wrote: `#1F3A5F`. The order
 * screen printed it, so a team member packing the order read "#1F3A5F / #1F3A5F" where they needed
 * to read "أزرق". Nobody picks a watch off a shelf by its hex code.
 *
 * ── Why the hex stays on screen ──────────────────────────────────────────────────────────────
 *
 * The swatch is drawn from it, and it is what the row actually stores — so when a name and a
 * swatch disagree with the watch in somebody's hand, the value that caused it is right there
 * rather than one query away. It is demoted to a small monospace run beside the name, not removed.
 *
 * A colour the catalogue has never heard of arrives with `name: null` and shows its hex alone, with
 * a word saying the catalogue does not know it. That is deliberate: `#1E3A5E` is not "أزرق" just
 * because `#1F3A5F` is, and a screen that rounds colours is a screen that mis-picks orders.
 */
export function ColourChip({ colour }: { colour: StoredColour }) {
    const t = useT();
    const locale = useLocale();

    const name = localisedTitle(colour.name, locale);

    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                aria-hidden="true"
                className="inline-block h-3.5 w-3.5 shrink-0 rounded-sm border border-border align-middle"
                style={{ backgroundColor: colour.hex }}
            />
            {name.missing ? (
                <span className="text-muted-foreground">
                    {t("orders.colour_unknown", "لون غير معروف للكتالوج")}
                </span>
            ) : (
                <Name>{name.text}</Name>
            )}
            <Num className="font-mono text-[11px] text-muted-foreground">
                {colour.hex.toUpperCase()}
            </Num>
        </span>
    );
}
