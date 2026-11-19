import { useState } from 'react';

import { useT } from '@/lib/i18n';

/**
 * A list of names that never becomes a wall (2026-11-19, the team's hot-deals report: a save asked about hundreds of
 * products in one paragraph, the dialog grew taller than the screen and its buttons could not be reached).
 *
 * The first `limit` names (five by default) in one line, "and N more", and a "show all" button that opens the full
 * list in a box of its own height that scrolls — whatever the number, the controls around it stay on screen.
 */
export function NameList({ names, limit = 5 }: { names: string[]; limit?: number }) {
    const t = useT();
    const [all, setAll] = useState(false);
    if (names.length === 0) {
        return null;
    }
    const rest = names.length - limit;

    return (
        <div className="space-y-2" data-name-list={names.length}>
            <p className="text-sm">
                <bdi>{names.slice(0, limit).join(t('common.list_separator', '، '))}</bdi>
                {rest > 0 ? <span className="text-muted-foreground"> {t('common.and_more', 'و:count غيرها', { count: rest })}</span> : null}
            </p>
            {rest > 0 ? (
                <>
                    <button type="button" className="text-sm font-medium underline underline-offset-4" aria-expanded={all} onClick={() => setAll(!all)}>
                        {all ? t('common.show_less', 'عرض أقل') : t('common.show_all', 'عرض الكل (:count)', { count: names.length })}
                    </button>
                    {all ? (
                        <ol className="max-h-60 list-decimal space-y-1 overflow-y-auto rounded-md border p-3 ps-8 text-sm">
                            {names.map((name, i) => (
                                <li key={`${i}-${name}`}>
                                    <bdi>{name}</bdi>
                                </li>
                            ))}
                        </ol>
                    ) : null}
                </>
            ) : null}
        </div>
    );
}

/** A server refusal's names (the `<field>_names` error, a JSON list), or none. */
export function namesFromError(value: string | undefined): string[] {
    if (!value) {
        return [];
    }
    try {
        const parsed: unknown = JSON.parse(value);
        return Array.isArray(parsed) ? parsed.map((x) => String(x)) : [];
    } catch {
        return [];
    }
}
