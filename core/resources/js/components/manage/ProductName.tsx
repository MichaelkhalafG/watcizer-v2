import { Badge } from "@/components/ui/badge";
import { Name } from "@/components/ui/bidi";
import { useLocale, useT } from "@/lib/i18n";
import { localisedTitle, type TitlePair } from "@/lib/title";

/**
 * A product's name as the operator should read it (item 1b, 2026-09-17).
 *
 * ── Why this is a component and not three copies of the same JSX ─────────────────────────────
 *
 * {@link localisedTitle} makes the DECISION — which of the two names this reader gets, and whether
 * it is standing in for a missing one. The decision is worth nothing if each screen then draws the
 * consequence slightly differently: the products list, the placement list and whatever comes next
 * would drift into three ways of saying "this is not your language", and an operator would learn
 * three marks instead of one.
 *
 * So the rule lives in `lib/title` and the mark lives here, and a screen just says what it has.
 */
export function ProductName({
    title,
    secondary = true,
}: {
    title: TitlePair | null | undefined;
    secondary?: boolean;
}) {
    const t = useT();
    const locale = useLocale();

    const name = localisedTitle(title, locale);

    if (name.missing) {
        /*
         * Not an empty cell. A product with neither name is a real row somebody has to find, and a
         * blank looks like a rendering fault — the reader cannot tell "no name" from "did not load".
         */
        return (
            <div className="font-medium text-muted-foreground">
                {t("products.no_name_either_language", "— بلا اسم —")}
            </div>
        );
    }

    /*
     * The component owns BOTH lines and their weights, rather than sitting inside a wrapper the
     * caller styles. A caller's `font-medium` would otherwise cascade into the muted second line and
     * render the other-language name semibold — which is exactly what happened on the first pass
     * here, because the old markup had the two lines as siblings and this has them nested.
     */
    return (
        <>
            <div className="font-medium">
                <Name>{name.text}</Name>
                {name.fallback ? (
                    <Badge
                        variant="outline"
                        className="ms-2 align-middle text-[10px] font-normal"
                        title={
                            locale === "ar"
                                ? t(
                                      "products.name_shown_in_english_hint",
                                      "لا يوجد اسم عربي لهذا المنتج بعد، والمعروض هنا هو الاسم الإنجليزي. افتح المنتج واكتب الاسم العربي.",
                                  )
                                : t(
                                      "products.name_shown_in_arabic_hint",
                                      "لا يوجد اسم إنجليزي لهذا المنتج بعد، والمعروض هنا هو الاسم العربي. افتح المنتج واكتب الاسم الإنجليزي.",
                                  )
                        }
                    >
                        {locale === "ar"
                            ? t("products.name_shown_in_english", "بالإنجليزية")
                            : t("products.name_shown_in_arabic", "بالعربية")}
                    </Badge>
                ) : null}
            </div>
            {secondary && name.secondary !== null ? (
                <div className="text-xs font-normal text-muted-foreground">
                    <Name>{name.secondary}</Name>
                </div>
            ) : null}
        </>
    );
}
