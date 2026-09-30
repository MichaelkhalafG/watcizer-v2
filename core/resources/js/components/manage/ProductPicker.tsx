import { ArrowDown, ArrowUp, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Ltr } from '@/components/ui/bidi';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

/**
 * Pick products by hand, in order (2026-09-30) — the re-engagement e-mail's manual picks (R4) and the
 * home page's custom rail. Search, add, move up and down, remove. The value is the ordered list of
 * product ids; the picker fetches their names and pictures itself.
 *
 * `searchUrl` is the screen's own route to ProductPickerController (each screen registers it inside
 * its own permission group), answering `?q=` (search) and `?ids=` (these ids, in order).
 * A product out of stock or hidden on the storefront is shown with a warning, not refused: the
 * screen's own rules decide what happens to it (the e-mail skips it; the rail does not show it).
 */
export interface PickerProduct {
    id: number;
    title: string;
    title_en: string | null;
    code: string | null;
    cover: string | null;
    in_stock: boolean;
    visible: boolean;
}

async function fetchProducts(url: string): Promise<PickerProduct[]> {
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const payload: unknown = await response.json();
        return typeof payload === 'object' && payload !== null && 'data' in payload && Array.isArray((payload as { data: unknown }).data)
            ? (payload as { data: PickerProduct[] }).data
            : [];
    } catch {
        return [];
    }
}

export default function ProductPicker({
    searchUrl,
    value,
    onChange,
    max,
    id,
}: {
    searchUrl: string;
    value: number[];
    onChange: (ids: number[]) => void;
    max: number;
    id?: string;
}) {
    const t = useT();
    const [known, setKnown] = useState<Record<number, PickerProduct>>({});
    const [term, setTerm] = useState('');
    const [hits, setHits] = useState<PickerProduct[]>([]);
    const [busy, setBusy] = useState(false);

    // Names and pictures for picks we have not seen yet (e.g. the saved ones on first load).
    const missing = value.filter((pid) => !known[pid]).join(',');
    useEffect(() => {
        if (missing === '') return;
        void fetchProducts(`${searchUrl}?ids=${missing}`).then((rows) =>
            setKnown((prev) => ({ ...prev, ...Object.fromEntries(rows.map((row) => [row.id, row])) })),
        );
    }, [missing, searchUrl]);

    const search = useCallback(async () => {
        if (term.trim().length < 2) {
            setHits([]);
            return;
        }
        setBusy(true);
        setHits(await fetchProducts(`${searchUrl}?q=${encodeURIComponent(term.trim())}`));
        setBusy(false);
    }, [term, searchUrl]);

    useEffect(() => {
        const timer = setTimeout(() => void search(), 300);
        return () => clearTimeout(timer);
    }, [search]);

    const add = (product: PickerProduct) => {
        setKnown((prev) => ({ ...prev, [product.id]: product }));
        if (!value.includes(product.id) && value.length < max) onChange([...value, product.id]);
        setTerm('');
        setHits([]);
    };
    const move = (index: number, by: number) => {
        const next = [...value];
        const [item] = next.splice(index, 1);
        next.splice(index + by, 0, item);
        onChange(next);
    };
    const full = value.length >= max;

    return (
        <div className="space-y-3">
            {value.length > 0 ? (
                <ol className="divide-y rounded-md border">
                    {value.map((pid, index) => {
                        const product = known[pid];
                        return (
                            <li key={pid} className="flex items-center gap-3 p-2">
                                <span className="w-5 text-center text-xs text-muted-foreground tabular-nums">{index + 1}</span>
                                {product?.cover ? (
                                    <img src={product.cover} alt="" className="size-10 rounded object-contain" />
                                ) : (
                                    <span className="size-10 rounded bg-muted" />
                                )}
                                <span className="min-w-0 flex-1 space-y-0.5">
                                    <span className="block truncate text-sm">{product?.title ?? `#${pid}`}</span>
                                    <span className="flex flex-wrap gap-1">
                                        {product?.code ? (
                                            <Ltr className="text-xs text-muted-foreground">{product.code}</Ltr>
                                        ) : null}
                                        {product && !product.in_stock ? (
                                            <Badge variant="warning">{t('picker.out_of_stock', 'غير متوفر')}</Badge>
                                        ) : null}
                                        {product && !product.visible ? (
                                            <Badge variant="neutral">{t('picker.hidden', 'مخفي في المتجر')}</Badge>
                                        ) : null}
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    className="rounded p-1 hover:bg-muted disabled:opacity-30"
                                    onClick={() => move(index, -1)}
                                    disabled={index === 0}
                                    aria-label={t('picker.up', 'تحريك لأعلى')}
                                >
                                    <ArrowUp className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    className="rounded p-1 hover:bg-muted disabled:opacity-30"
                                    onClick={() => move(index, 1)}
                                    disabled={index === value.length - 1}
                                    aria-label={t('picker.down', 'تحريك لأسفل')}
                                >
                                    <ArrowDown className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    className="rounded p-1 hover:bg-muted"
                                    onClick={() => onChange(value.filter((x) => x !== pid))}
                                    aria-label={t('picker.remove', 'إزالة')}
                                >
                                    <X className="size-4" />
                                </button>
                            </li>
                        );
                    })}
                </ol>
            ) : null}

            <Input
                id={id}
                value={term}
                disabled={full}
                onChange={(event) => setTerm(event.target.value)}
                placeholder={
                    full
                        ? t('picker.full', 'اكتمل العدد (:max)', { max })
                        : t('picker.placeholder', 'ابحث باسم المنتج أو كوده…')
                }
            />
            {busy ? <p className="text-xs text-muted-foreground">{t('picker.searching', 'جاري البحث…')}</p> : null}
            {hits.length > 0 ? (
                <ul className="max-h-64 divide-y overflow-y-auto rounded-md border">
                    {hits.map((hit) => (
                        <li key={hit.id}>
                            <button
                                type="button"
                                className="flex w-full items-center gap-3 p-2 text-start hover:bg-muted disabled:opacity-40"
                                onClick={() => add(hit)}
                                disabled={value.includes(hit.id)}
                            >
                                {hit.cover ? (
                                    <img src={hit.cover} alt="" className="size-10 rounded object-contain" />
                                ) : (
                                    <span className="size-10 rounded bg-muted" />
                                )}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm">{hit.title}</span>
                                    {hit.code ? <Ltr className="text-xs text-muted-foreground">{hit.code}</Ltr> : null}
                                </span>
                                {!hit.in_stock ? <Badge variant="warning">{t('picker.out_of_stock', 'غير متوفر')}</Badge> : null}
                                {!hit.visible ? <Badge variant="neutral">{t('picker.hidden', 'مخفي في المتجر')}</Badge> : null}
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
