import { Search, X } from "lucide-react";
import { useEffect, useState } from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useT } from "@/lib/i18n";

/*
 * QUICK SEARCH for the screens that hold their WHOLE list on the page (M1x, 2026-10-30, Michael's item 4): brands and
 * the other lists, units, shipping prices, categories, articles. The server already sends every row (tens, not
 * thousands), so the search filters in the browser — no round trip — and the term lives in the URL (`?q=`), so it
 * survives a refresh and can be sent to someone. The screens with thousands of rows use `DataTable`, which searches on
 * the server with the same `?q=`.
 */

/** Read and write `?q=` without a reload. */
export function useQueryTerm(key = "q"): [string, (value: string) => void] {
    const [term, setTerm] = useState<string>(() =>
        typeof window === "undefined" ? "" : (new URLSearchParams(window.location.search).get(key) ?? ""),
    );

    useEffect(() => {
        if (typeof window === "undefined") {
            return;
        }
        const url = new URL(window.location.href);
        if (term.trim() === "") {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, term);
        }
        window.history.replaceState(window.history.state, "", url.toString());
    }, [term, key]);

    return [term, setTerm];
}

/** Does `haystack` (any number of strings) contain the term, ignoring case and Arabic letter variants? */
export function matchesTerm(term: string, ...haystack: Array<string | null | undefined>): boolean {
    const needle = fold(term);
    if (needle === "") {
        return true;
    }
    return haystack.some((value) => value !== null && value !== undefined && fold(value).includes(needle));
}

function fold(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .replace(/[أإآٱ]/g, "ا") // i18n-exempt: letter-folding data for matching, never rendered
        .replace(/ة/g, "ه") // i18n-exempt: letter-folding data for matching, never rendered
        .replace(/ى/g, "ي") // i18n-exempt: letter-folding data for matching, never rendered
        .replace(/[\u064B-\u0652]/g, ""); // the short-vowel marks, written as escapes
}

export function QuickSearch({
    value,
    onChange,
    placeholder,
    count,
    total,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    /** How many rows match, and how many there are. */
    count: number;
    total: number;
}) {
    const t = useT();

    return (
        <div className="mb-3 flex flex-wrap items-center gap-2">
            <div className="relative w-full sm:w-80">
                <Search className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    aria-label={placeholder}
                    placeholder={placeholder}
                    className="ps-8"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                />
            </div>
            {value.trim() !== "" ? (
                <>
                    <span className="text-xs text-muted-foreground">
                        {t("table.quick_search_count", ":count من :total", { count, total })}
                    </span>
                    <Button type="button" variant="ghost" size="sm" className="gap-1" onClick={() => onChange("")}>
                        <X className="h-3.5 w-3.5" />
                        {t("table.clear_filters", "إلغاء التصفية")}
                    </Button>
                </>
            ) : null}
        </div>
    );
}
