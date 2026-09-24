import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Choosing where a product lives in one storefront's tree (§2.1, J-9).
 *
 * ── What it replaces ────────────────────────────────────────────────────────────────────────
 *
 * A **two-column** grid of checkboxes inside a 200 px scroll pane, with `—` and `— —` glued to
 * the front of each name as the only indication of depth, showing 8 of 61 names at a time, with
 * no search, inside a page that was already seven screens long. Two columns destroy indentation
 * outright: `أزياء وإكسسوارات` landed in the right column with an unrelated child beside it on the
 * left, and a reader could not tell a parent from a sibling.
 *
 * ── What it does instead ────────────────────────────────────────────────────────────────────
 *
 * One column, real indentation with a visible rail, and a search box. The search matches Arabic
 * OR English, because the team is bilingual and half of them will type "watches" at a tree
 * labelled «ساعات» — and it keeps a matched node's ANCESTORS on screen, because a result with its
 * parents cut off is a name with no place attached to it.
 *
 * A node the product is already in is always shown, whatever the search says. Hiding a current
 * placement behind a filter is how somebody removes the wrong one by accident.
 */

export interface CategoryOption {
    value: string;
    /** The plain name, no `— ` depth prefix — this component indents properly. */
    name: string;
    /** The English name, for search only. */
    en: string;
    depth: number;
    path: string;
    /** False for the parked legacy tree: offered, explained, and not pickable (J-9). */
    selectable: boolean;
}

export function CategoryPicker({
    options,
    selected,
    onToggle,
    disabled = false,
}: {
    options: CategoryOption[];
    selected: number[];
    onToggle: (id: number, on: boolean) => void;
    disabled?: boolean;
}) {
    const t = useT();
    const [term, setTerm] = useState('');

    const visible = useMemo(() => {
        const needle = term.trim().toLocaleLowerCase();
        if (needle === '') {
            return options;
        }

        /*
         * A match pulls its ANCESTORS along. `path` is a materialised id path (`/1/10/`), so a
         * node's ancestors are exactly the nodes whose path is a prefix of its own — no tree to
         * rebuild and no second query.
         */
        const keep = new Set<string>();
        for (const option of options) {
            const hit =
                option.name.toLocaleLowerCase().includes(needle) ||
                option.en.toLocaleLowerCase().includes(needle) ||
                selected.includes(Number(option.value));
            if (!hit) {
                continue;
            }
            keep.add(option.value);
            for (const other of options) {
                if (other.value !== option.value && option.path.startsWith(other.path)) {
                    keep.add(other.value);
                }
            }
        }

        return options.filter((option) => keep.has(option.value));
    }, [options, term, selected]);

    return (
        <div className="space-y-2">
            <div className="relative">
                <Search
                    className="pointer-events-none absolute inset-y-0 start-2.5 my-auto h-3.5 w-3.5 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    type="search"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    placeholder={t('products.category_search', 'ابحث في التصنيفات…')}
                    aria-label={t('products.category_search', 'ابحث في التصنيفات…')}
                    className="h-8 ps-8 text-sm"
                />
            </div>

            <div className="max-h-72 overflow-y-auto rounded-lg border p-1">
                {options.length === 0 ? (
                    <p className="p-2 text-xs text-muted-foreground">
                        {t('products.no_categories', 'لا توجد تصنيفات في هذا المتجر بعد.')}
                    </p>
                ) : null}

                {options.length > 0 && visible.length === 0 ? (
                    <p className="p-2 text-xs text-muted-foreground">
                        {t('products.category_search_empty', 'لا يوجد تصنيف بهذا الاسم.')}
                    </p>
                ) : null}

                {visible.map((option) => {
                    const id = Number(option.value);
                    const checked = selected.includes(id);
                    // A dead-tree node stays operable while the product is IN it, so a placement
                    // made before J-9 can still be removed from the only screen that could do it.
                    const locked = !option.selectable && !checked;

                    return (
                        <div
                            key={option.value}
                            className={cn(
                                'flex items-start gap-2 rounded px-2 py-1.5 text-sm',
                                // The rail: one border per level, so depth is visible at a glance
                                // instead of being spelled out in dashes.
                                option.depth > 1 && 'border-s',
                                locked ? 'opacity-60' : 'hover:bg-muted/50',
                            )}
                            style={{ marginInlineStart: `${(option.depth - 1) * 1.25}rem` }}
                        >
                            <label className="flex flex-1 items-start gap-2">
                                <Checkbox
                                    className="mt-0.5"
                                    checked={checked}
                                    disabled={disabled || locked}
                                    onCheckedChange={(state) => onToggle(id, state === true)}
                                />
                                <span className={cn(option.depth === 1 && 'font-medium')}>{option.name}</span>
                            </label>

                            {/* The reason ON the control, visible, not in a `title` nobody hovers
                                (J-8). A disabled control that will not say why reads as a broken
                                dashboard rather than as a rule. */}
                            {locked ? (
                                <span className="shrink-0 text-[11px] leading-5 text-muted-foreground">
                                    {t('products.category_legacy_locked', 'شجرة قديمة — لا تُعرض على المتجر')}
                                </span>
                            ) : null}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
