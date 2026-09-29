import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';

/**
 * Home-page rails (C-1 stage 4, slice C).
 *
 * The product rails under the hero, in the order the home page shows them. A rail is a KIND —
 * offers, featured, newest, or one grade / brand / top-level category — with an optional title in
 * each language (empty = the target's own name), a card count, and an on/off switch.
 *
 * Order is edited with up/down buttons, and every move sends the WHOLE order: the server refuses a
 * list that does not name every rail once, so two tabs cannot silently interleave their moves.
 * A rail with nothing to show (a grade with no products) is simply not rendered on the home page.
 */

interface Rail {
    id: number;
    kind: string;
    target_id: number | null;
    target_name: string | null;
    title_en: string | null;
    title_ar: string | null;
    position: number;
    is_active: boolean;
    card_count: number;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: Option[];
    rails: Rail[];
    kinds: string[];
    targeted: string[];
    targets: Record<string, Option[]>;
    max_cards: number;
}

interface Draft {
    id: number | null;
    kind: string;
    target_id: string;
    title_en: string;
    title_ar: string;
    card_count: string;
    is_active: boolean;
}

const EMPTY: Draft = { id: null, kind: 'grade', target_id: '', title_en: '', title_ar: '', card_count: '8', is_active: true };

/** The kinds, named for the operator. A function because `useT()` only runs inside the component. */
function kindLabels(t: ReturnType<typeof useT>): Record<string, string> {
    return {
        offers: t('home_rails.kind_offers', 'العروض'),
        featured: t('home_rails.kind_featured', 'منتجات مميّزة'),
        newest: t('home_rails.kind_newest', 'وصل حديثًا'),
        grade: t('products.grade', 'الدرجة'),
        brand: t('home_rails.kind_brand', 'ماركة'),
        category_type: t('home_rails.kind_category_type', 'تصنيف رئيسي'),
    };
}

export default function HomeRailsIndex({ storefront, storefronts, rails, kinds, targeted, targets, max_cards }: Props) {
    const t = useT();
    const [draft, setDraft] = useState<Draft | null>(null);
    const KIND = kindLabels(t);
    const base = `/manage/storefronts/${storefront.id}/home-rails`;
    const needsTarget = (kind: string) => targeted.includes(kind);

    const payloadOf = (rail: Rail, patch: Partial<Rail> = {}) => {
        const next = { ...rail, ...patch };

        return {
            kind: next.kind,
            target_id: next.target_id,
            title_en: next.title_en,
            title_ar: next.title_ar,
            card_count: next.card_count,
            is_active: next.is_active,
        };
    };

    const edit = (rail: Rail) =>
        setDraft({
            id: rail.id,
            kind: rail.kind,
            target_id: rail.target_id === null ? '' : String(rail.target_id),
            title_en: rail.title_en ?? '',
            title_ar: rail.title_ar ?? '',
            card_count: String(rail.card_count),
            is_active: rail.is_active,
        });

    const submit = () => {
        if (draft === null) {
            return;
        }
        const payload = {
            kind: draft.kind,
            target_id: needsTarget(draft.kind) && draft.target_id !== '' ? Number(draft.target_id) : null,
            title_en: draft.title_en.trim() === '' ? null : draft.title_en,
            title_ar: draft.title_ar.trim() === '' ? null : draft.title_ar,
            card_count: Number(draft.card_count || 0),
            is_active: draft.is_active,
        };
        const done = { preserveScroll: true, onSuccess: () => setDraft(null) };
        draft.id === null ? router.post(base, payload, done) : router.put(`${base}/${draft.id}`, payload, done);
    };

    const move = (index: number, by: -1 | 1) => {
        const order = rails.map((rail) => rail.id);
        const other = index + by;
        if (other < 0 || other >= order.length) {
            return;
        }
        [order[index], order[other]] = [order[other], order[index]];
        router.post(`${base}/order`, { order }, { preserveScroll: true });
    };

    const toggle = (rail: Rail) =>
        router.put(`${base}/${rail.id}`, payloadOf(rail, { is_active: !rail.is_active }), { preserveScroll: true });

    const draftTargetMissing = draft !== null && needsTarget(draft.kind) && draft.target_id === '';

    return (
        <ManageLayout
            title={t('home_rails.title', 'أشرطة الصفحة الرئيسية')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('home_rails.title', 'أشرطة الصفحة الرئيسية') },
            ]}
            actions={<Button onClick={() => setDraft({ ...EMPTY })}>{t('home_rails.new', 'شريط جديد')}</Button>}
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        aria-label={t('common.storefront', 'المتجر')}
                        className="w-56"
                        value={String(storefront.id)}
                        onChange={(event) => router.visit(`/manage/storefronts/${event.target.value}/home-rails`)}
                    >
                        {storefronts.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'home_rails.intro',
                            'أشرطة المنتجات تحت الصورة الرئيسية لهذا المتجر، بنفس ترتيبها هنا. الشريط الذي لا منتجات فيه لا يظهر. التغيير يظهر في المتجر خلال ٥ دقائق.',
                        )}
                    </p>
                </div>

                {draft !== null ? (
                    <div className="rounded-lg border bg-card p-4">
                        <h2 className="font-medium">
                            {draft.id === null
                                ? t('home_rails.new', 'شريط جديد')
                                : t('home_rails.edit_heading', 'تعديل الشريط #:id', { id: draft.id })}
                        </h2>

                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <label className="space-y-1 text-sm">
                                <span>{t('home_rails.kind', 'النوع')}</span>
                                <Select
                                    value={draft.kind}
                                    onChange={(event) => setDraft({ ...draft, kind: event.target.value, target_id: '' })}
                                >
                                    {kinds.map((kind) => (
                                        <option key={kind} value={kind}>
                                            {KIND[kind] ?? kind}
                                        </option>
                                    ))}
                                </Select>
                            </label>

                            {needsTarget(draft.kind) ? (
                                <label className="space-y-1 text-sm">
                                    <span>{t('home_rails.target', 'يعرض')}</span>
                                    <Select
                                        value={draft.target_id}
                                        onChange={(event) => setDraft({ ...draft, target_id: event.target.value })}
                                    >
                                        <option value="">{t('common.choose', 'اختر…')}</option>
                                        {(targets[draft.kind] ?? []).map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </Select>
                                </label>
                            ) : (
                                <p className="self-end text-xs text-muted-foreground">
                                    {draft.kind === 'featured'
                                        ? t(
                                              'home_rails.featured_hint',
                                              'المنتجات المعلَّمة «مميّز» في شاشة العرض والترتيب، بترتيب عشوائي. ما دام لا شيء معلَّمًا: منتجات مخفّضة لها صورة.',
                                          )
                                        : draft.kind === 'newest'
                                          ? t('home_rails.newest_hint', 'أحدث المنتجات المتوفرة، الأحدث أولًا.')
                                          : t('home_rails.offers_hint', 'المنتجات التي عليها خصم.')}
                                </p>
                            )}

                            <label className="space-y-1 text-sm">
                                <span>{t('home_rails.title_ar', 'العنوان (عربي)')}</span>
                                <Input
                                    value={draft.title_ar}
                                    placeholder={t('home_rails.title_placeholder', 'فارغ = الاسم التلقائي')}
                                    onChange={(event) => setDraft({ ...draft, title_ar: event.target.value })}
                                />
                            </label>

                            <label className="space-y-1 text-sm">
                                <span>{t('home_rails.title_en', 'العنوان (إنجليزي)')}</span>
                                <Input
                                    dir="ltr"
                                    value={draft.title_en}
                                    placeholder={t('home_rails.title_placeholder', 'فارغ = الاسم التلقائي')}
                                    onChange={(event) => setDraft({ ...draft, title_en: event.target.value })}
                                />
                            </label>

                            <label className="space-y-1 text-sm">
                                <span>{t('home_rails.card_count', 'عدد المنتجات')}</span>
                                <Input
                                    dir="ltr"
                                    type="number"
                                    min={1}
                                    max={max_cards}
                                    value={draft.card_count}
                                    onChange={(event) => setDraft({ ...draft, card_count: event.target.value })}
                                />
                            </label>

                            <label className="flex items-center gap-2 self-end text-sm">
                                <input
                                    type="checkbox"
                                    checked={draft.is_active}
                                    onChange={(event) => setDraft({ ...draft, is_active: event.target.checked })}
                                />
                                <span>{t('common.active', 'مفعّل')}</span>
                            </label>
                        </div>

                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button onClick={submit} disabled={draftTargetMissing}>
                                {t('common.save', 'حفظ')}
                            </Button>
                            <Button variant="outline" onClick={() => setDraft(null)}>
                                {t('common.cancel', 'إلغاء')}
                            </Button>
                            {draft.id !== null ? (
                                <Button
                                    variant="destructive"
                                    className="ms-auto"
                                    onClick={() =>
                                        router.delete(`${base}/${draft.id}`, {
                                            preserveScroll: true,
                                            onSuccess: () => setDraft(null),
                                        })
                                    }
                                >
                                    {t('common.delete', 'حذف')}
                                </Button>
                            ) : null}
                        </div>
                    </div>
                ) : null}

                {rails.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <p className="font-medium">{t('home_rails.empty_title', 'لا توجد أشرطة')}</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('home_rails.empty_description', 'الصفحة الرئيسية لهذا المتجر لا تعرض أي شريط منتجات. أضف شريطًا.')}
                        </p>
                    </div>
                ) : (
                    <ol className="divide-y rounded-lg border bg-card">
                        {rails.map((rail, index) => (
                            <li
                                key={rail.id}
                                className={`flex flex-wrap items-center gap-3 p-3 ${rail.is_active ? '' : 'opacity-60'}`}
                            >
                                <span className="w-6 text-center text-sm text-muted-foreground tabular-nums" dir="ltr">
                                    {index + 1}
                                </span>
                                <div className="flex flex-col gap-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-label={t('home_rails.move_up', 'تحريك لأعلى')}
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                    >
                                        ↑
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-label={t('home_rails.move_down', 'تحريك لأسفل')}
                                        disabled={index === rails.length - 1}
                                        onClick={() => move(index, 1)}
                                    >
                                        ↓
                                    </Button>
                                </div>
                                <div className="min-w-0 flex-1 space-y-0.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{KIND[rail.kind] ?? rail.kind}</span>
                                        {rail.target_name !== null ? <span className="truncate">· {rail.target_name}</span> : null}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {rail.title_ar !== null || rail.title_en !== null
                                            ? [rail.title_ar, rail.title_en].filter((title) => title !== null).join(' / ')
                                            : t('home_rails.default_title', 'العنوان: الاسم التلقائي')}
                                        {' · '}
                                        {t('home_rails.cards', ':count منتجات', { count: rail.card_count })}
                                    </div>
                                </div>
                                <Badge variant={rail.is_active ? 'success' : 'neutral'}>
                                    {rail.is_active ? t('common.active', 'مفعّل') : t('common.suspended', 'موقوف')}
                                </Badge>
                                <Button variant="outline" size="sm" onClick={() => toggle(rail)}>
                                    {rail.is_active ? t('home_rails.switch_off', 'إيقاف') : t('home_rails.switch_on', 'تفعيل')}
                                </Button>
                                <Button variant="outline" size="sm" onClick={() => edit(rail)}>
                                    {t('common.edit', 'تعديل')}
                                </Button>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </ManageLayout>
    );
}
