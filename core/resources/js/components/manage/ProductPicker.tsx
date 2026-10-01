import { ArrowDown, ArrowUp, GripVertical, Plus, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Ltr } from '@/components/ui/bidi';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Pick products by hand, in order (2026-09-30; reworked 2026-10-01) — the re-engagement e-mail's
 * manual picks (R4) and the home page's custom rail. Search by name or code, click a result to add
 * it, drag a row (or use its arrows) to reorder, × to remove. Never an id typed by hand: the value
 * is the ordered list of product ids, and the picker fetches names, pictures and prices itself.
 *
 * Every state SAYS what it is (rework): too short to search, searching, no match for this term,
 * and a failed search (session ended, no connection, server error) are four different messages.
 * Before the rework a failed search showed exactly what "no results" showed — nothing — which read
 * as "this box wants something else", i.e. an id.
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
    price: number;
    was: number | null;
    in_stock: boolean;
    visible: boolean;
}

type Fetched = { ok: true; rows: PickerProduct[] } | { ok: false; reason: 'session' | 'network' | 'server' };

async function fetchProducts(url: string): Promise<Fetched> {
    let response: Response;
    try {
        response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    } catch {
        return { ok: false, reason: 'network' };
    }
    // A signed-out session answers 401/419, or redirects to the sign-in page (HTML, not JSON).
    if (response.status === 401 || response.status === 419 || response.redirected) return { ok: false, reason: 'session' };
    if (!response.ok) return { ok: false, reason: 'server' };
    try {
        const payload: unknown = await response.json();
        if (typeof payload === 'object' && payload !== null && 'data' in payload && Array.isArray((payload as { data: unknown }).data)) {
            return { ok: true, rows: (payload as { data: PickerProduct[] }).data };
        }
    } catch {
        return { ok: false, reason: 'session' };
    }
    return { ok: false, reason: 'server' };
}

const money = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });

function Price({ product }: { product: PickerProduct }) {
    return (
        <span className="inline-flex items-baseline gap-1.5 whitespace-nowrap text-sm tabular-nums" dir="ltr">
            <span>EGP {money.format(product.price)}</span>
            {product.was !== null ? <s className="text-xs text-muted-foreground">{money.format(product.was)}</s> : null}
        </span>
    );
}

function Thumb({ src }: { src: string | null }) {
    return src ? <img src={src} alt="" className="size-12 shrink-0 rounded border bg-white object-contain" /> : <span className="size-12 shrink-0 rounded border bg-muted" />;
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
    const [gone, setGone] = useState<Record<number, true>>({});
    const [term, setTerm] = useState('');
    const [hits, setHits] = useState<PickerProduct[]>([]);
    const [state, setState] = useState<'idle' | 'searching' | 'done' | 'session' | 'network' | 'server'>('idle');
    const [dragging, setDragging] = useState<number | null>(null);
    const latest = useRef(0);

    // Names, pictures and prices for picks we have not seen yet (e.g. the saved ones on first load).
    // An id the search no longer answers (deleted, or taken off this storefront) is marked, not shown as a number.
    const missing = value.filter((pid) => !known[pid] && !gone[pid]).join(',');
    useEffect(() => {
        if (missing === '') return;
        void fetchProducts(`${searchUrl}?ids=${missing}`).then((result) => {
            if (!result.ok) return;
            const found = Object.fromEntries(result.rows.map((row) => [row.id, row]));
            setKnown((prev) => ({ ...prev, ...found }));
            setGone((prev) => ({ ...prev, ...Object.fromEntries(missing.split(',').map(Number).filter((pid) => !found[pid]).map((pid) => [pid, true as const])) }));
        });
    }, [missing, searchUrl]);

    const search = useCallback(async () => {
        const q = term.trim();
        if (q.length < 2) {
            setHits([]);
            setState('idle');
            return;
        }
        const ticket = ++latest.current;
        setState('searching');
        const result = await fetchProducts(`${searchUrl}?q=${encodeURIComponent(q)}`);
        if (ticket !== latest.current) return; // a newer search is on its way: drop this answer
        if (result.ok) {
            setHits(result.rows);
            setState('done');
        } else {
            setHits([]);
            setState(result.reason);
        }
    }, [term, searchUrl]);

    useEffect(() => {
        const timer = setTimeout(() => void search(), 300);
        return () => clearTimeout(timer);
    }, [search]);

    const full = value.length >= max;
    const add = (product: PickerProduct) => {
        if (value.includes(product.id) || full) return;
        setKnown((prev) => ({ ...prev, [product.id]: product }));
        onChange([...value, product.id]);
    };
    const move = (from: number, to: number) => {
        if (to < 0 || to >= value.length || from === to) return;
        const next = [...value];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item);
        onChange(next);
    };

    const short = term.trim().length < 2;

    return (
        <div className="space-y-3">
            {value.length > 0 ? (
                <ol className="divide-y rounded-md border" aria-label={t('picker.chosen', 'المنتجات المختارة، بالترتيب')}>
                    {value.map((pid, index) => {
                        const product = known[pid];
                        return (
                            <li
                                key={pid}
                                draggable
                                onDragStart={(event) => {
                                    setDragging(index);
                                    event.dataTransfer.effectAllowed = 'move';
                                }}
                                onDragOver={(event) => {
                                    event.preventDefault();
                                    if (dragging !== null && dragging !== index) {
                                        move(dragging, index);
                                        setDragging(index);
                                    }
                                }}
                                onDragEnd={() => setDragging(null)}
                                className={cn('flex items-center gap-3 bg-card p-2', dragging === index && 'opacity-50')}
                            >
                                <GripVertical className="size-4 shrink-0 cursor-grab text-muted-foreground" aria-hidden />
                                <span className="w-5 text-center text-xs text-muted-foreground tabular-nums">{index + 1}</span>
                                <Thumb src={product?.cover ?? null} />
                                <span className="min-w-0 flex-1 space-y-0.5">
                                    {product ? (
                                        <>
                                            <span className="block truncate text-sm">{product.title}</span>
                                            <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <Price product={product} />
                                                {product.code ? <Ltr className="text-xs text-muted-foreground">{product.code}</Ltr> : null}
                                                {!product.in_stock ? <Badge variant="warning">{t('picker.out_of_stock', 'غير متوفر')}</Badge> : null}
                                                {!product.visible ? <Badge variant="neutral">{t('picker.hidden', 'مخفي في المتجر')}</Badge> : null}
                                            </span>
                                        </>
                                    ) : gone[pid] ? (
                                        <span className="block text-sm text-destructive">
                                            {t('picker.gone', 'هذا المنتج لم يعد في المتجر — أزله من القائمة.')}
                                        </span>
                                    ) : (
                                        <span className="block text-sm text-muted-foreground">{t('picker.loading', 'جاري التحميل…')}</span>
                                    )}
                                </span>
                                <button
                                    type="button"
                                    className="rounded p-1 hover:bg-muted disabled:opacity-30"
                                    onClick={() => move(index, index - 1)}
                                    disabled={index === 0}
                                    aria-label={t('picker.up', 'تحريك لأعلى')}
                                    title={t('picker.up', 'تحريك لأعلى')}
                                >
                                    <ArrowUp className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    className="rounded p-1 hover:bg-muted disabled:opacity-30"
                                    onClick={() => move(index, index + 1)}
                                    disabled={index === value.length - 1}
                                    aria-label={t('picker.down', 'تحريك لأسفل')}
                                    title={t('picker.down', 'تحريك لأسفل')}
                                >
                                    <ArrowDown className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    className="rounded p-1 hover:bg-muted"
                                    onClick={() => onChange(value.filter((x) => x !== pid))}
                                    aria-label={t('picker.remove', 'إزالة')}
                                    title={t('picker.remove', 'إزالة')}
                                >
                                    <X className="size-4" />
                                </button>
                            </li>
                        );
                    })}
                </ol>
            ) : null}
            {value.length > 1 ? (
                <p className="text-xs text-muted-foreground">{t('picker.drag_hint', 'اسحب صفاً لتغيير الترتيب، أو استخدم الأسهم.')}</p>
            ) : null}

            {full ? (
                <p className="text-sm text-muted-foreground">
                    {t('picker.full_hint', 'اكتمل العدد (:max من :max). أزل منتجاً لإضافة غيره.', { max })}
                </p>
            ) : (
                <div className="space-y-2">
                    <Input
                        id={id}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder={t('picker.placeholder', 'ابحث باسم المنتج أو كوده…')}
                        autoComplete="off"
                    />
                    <p className="text-xs text-muted-foreground" role="status">
                        {short
                            ? t('picker.type_more', 'اكتب حرفين على الأقل من اسم المنتج أو كوده، ثم اضغط على النتيجة لإضافتها.')
                            : state === 'searching'
                              ? t('picker.searching', 'جاري البحث…')
                              : state === 'done' && hits.length === 0
                                ? t('picker.no_match', 'لا يوجد منتج في هذا المتجر يطابق «:term». جرّب جزءاً من الاسم أو الكود.', { term: term.trim() })
                                : state === 'done'
                                  ? t('picker.results', ':count نتيجة — اضغط على منتج لإضافته.', { count: hits.length })
                                  : null}
                    </p>
                    {state === 'session' || state === 'network' || state === 'server' ? (
                        <div className="flex flex-wrap items-center gap-2 rounded-md border border-destructive/40 bg-destructive/10 p-2 text-sm text-destructive" role="alert">
                            <span className="flex-1">
                                {state === 'session'
                                    ? t('picker.err_session', 'تعذّر البحث: انتهت جلستك. أعد تحميل الصفحة وسجّل الدخول مرة أخرى.')
                                    : state === 'network'
                                      ? t('picker.err_network', 'تعذّر البحث: لا يوجد اتصال بالخادم. تحقق من الاتصال وحاول مرة أخرى.')
                                      : t('picker.err_server', 'تعذّر البحث: حدث خطأ في الخادم. حاول مرة أخرى بعد قليل.')}
                            </span>
                            {state !== 'session' ? (
                                <Button type="button" size="sm" variant="outline" onClick={() => void search()}>
                                    {t('picker.retry', 'أعد البحث')}
                                </Button>
                            ) : null}
                        </div>
                    ) : null}
                    {state === 'done' && hits.length > 0 ? (
                        <ul className="max-h-80 divide-y overflow-y-auto rounded-md border">
                            {hits.map((hit) => {
                                const picked = value.includes(hit.id);
                                return (
                                    <li key={hit.id}>
                                        <button
                                            type="button"
                                            className="flex w-full items-center gap-3 p-2 text-start hover:bg-muted disabled:cursor-default disabled:opacity-50"
                                            onClick={() => add(hit)}
                                            disabled={picked}
                                        >
                                            <Thumb src={hit.cover} />
                                            <span className="min-w-0 flex-1 space-y-0.5">
                                                <span className="block truncate text-sm">{hit.title}</span>
                                                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <Price product={hit} />
                                                    {hit.code ? <Ltr className="text-xs text-muted-foreground">{hit.code}</Ltr> : null}
                                                    {!hit.in_stock ? <Badge variant="warning">{t('picker.out_of_stock', 'غير متوفر')}</Badge> : null}
                                                    {!hit.visible ? <Badge variant="neutral">{t('picker.hidden', 'مخفي في المتجر')}</Badge> : null}
                                                </span>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-1 text-xs font-medium text-muted-foreground">
                                                {picked ? (
                                                    t('picker.added', 'مضاف')
                                                ) : (
                                                    <>
                                                        <Plus className="size-4" />
                                                        {t('picker.add', 'أضف')}
                                                    </>
                                                )}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    ) : null}
                </div>
            )}
        </div>
    );
}
