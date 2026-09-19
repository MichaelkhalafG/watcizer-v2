import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { ExportLink } from '@/components/table/ExportLink';
import { Ltr, Num } from '@/components/ui/bidi';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';

/**
 * Shipping prices — the governorates and what delivery to each costs (wave 4D).
 *
 * ── Why this screen exists at all ───────────────────────────────────────────────────────────
 *
 * It is the one thing the team could not do on the day the Blade dashboard is retired. The old
 * `shipping_city` screen was the only editor of the delivery price anywhere, so without this the
 * next courier price rise is a hand-typed UPDATE against production.
 *
 * ── Read by everyone, written by administrators ─────────────────────────────────────────────
 *
 * Data-entry see the whole list, because they quote the delivery price on the telephone all day.
 * They see no buttons: a price is money, so every write is behind `manage-shipping` on the route,
 * and `abilities.manage` here only decides whether to draw a control that would otherwise lead to
 * a refusal.
 *
 * ── The address count is not decoration ─────────────────────────────────────────────────────
 *
 * `addresses.shipping_city_id` has no database constraint, so deleting a governorate that customers
 * have saved addresses in would leave those addresses pointing at nothing — the city disappears from
 * the customer's saved address and checkout prices delivery at zero. The server refuses it; this
 * column is why, shown before the operator reaches for the button.
 */

interface City {
    id: number;
    name_ar: string | null;
    name_en: string | null;
    shipping_cost: string;
    addresses: number;
}

interface Props {
    cities: City[];
    abilities: { manage: boolean };
    notice: string;
}

export default function ShippingIndex({ cities, abilities, notice }: Props) {
    const t = useT();
    const [editing, setEditing] = useState<number | null>(null);
    const [adding, setAdding] = useState(false);

    return (
        <ManageLayout title={t('shipping.title', 'أسعار الشحن')}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold">{t('shipping.title', 'أسعار الشحن')}</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t('shipping.count_note', ':count محافظة. السعر بالجنيه المصري.', {
                            count: cities.length,
                        })}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <ExportLink count={cities.length} />
                    {abilities.manage && !adding && (
                        <Button size="sm" onClick={() => setAdding(true)}>
                            {t('shipping.add_city', 'إضافة محافظة')}
                        </Button>
                    )}
                </div>
            </div>

            <p className="mt-3 rounded-md border border-dashed p-3 text-xs text-muted-foreground">{notice}</p>

            {adding && <CityForm onDone={() => setAdding(false)} />}

            {/* ── The row reads as a table row again (second pass, item 10, 2026-09-19) ────────

                Three separate faults, and only one of them was mine:

                 • `align="end"` is not a valid value of the `align` attribute (the HTML one takes
                   left/center/right/justify, and is deprecated anyway), so the cost column was
                   silently start-aligned while its header looked like it should not be. That is
                   the "columns don't line up" report, and it was invisible in code review because
                   the attribute LOOKS like it is doing something.
                 • `dir="ltr"` on a `TableCell` is the exact bug `components/ui/bidi` exists to
                   stop: direction on a block resolves `text-align: start` against the box's own
                   direction, so the English name left-aligned while its header stayed right.
                   `RtlTableGuardTest` forbids it — and missed it, because the guard matched
                   lowercase `<td`. That hole is closed with this change.
                 • the linked-address explanation and both buttons were stacked into one cell by
                   my own J-8 fix. The explanation belongs beside the NUMBER it explains, not
                   beside the button it disables.

                No outer `overflow-x-auto` either: `Table` now owns its scroll container (item 9),
                and two nested scrollers means the inner one can never reach its own edge. */}
            <div className="mt-4">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-14">#</TableHead>
                            <TableHead>{t('shipping.governorate', 'المحافظة')}</TableHead>
                            {/* Item 10: this said `Governorate` in English beside the
                                Arabic heading one line up. It is the ENGLISH NAME column,
                                and the lookup screens already have a word for that. */}
                            <TableHead>{t('common.name_en', 'الاسم (إنجليزي)')}</TableHead>
                            <TableHead className="text-end">{t('shipping.cost', 'سعر الشحن')}</TableHead>
                            <TableHead className="text-end">{t('shipping.linked_addresses', 'عناوين مرتبطة')}</TableHead>
                            {abilities.manage && (
                                <TableHead className="w-[9rem] text-end">{t('common.actions', 'إجراءات')}</TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {cities.map((city) =>
                            editing === city.id ? (
                                <TableRow key={city.id}>
                                    <TableCell colSpan={abilities.manage ? 6 : 5}>
                                        <CityForm city={city} onDone={() => setEditing(null)} />
                                    </TableCell>
                                </TableRow>
                            ) : (
                                <TableRow key={city.id}>
                                    <TableCell>
                                        <Num>{city.id}</Num>
                                    </TableCell>
                                    <TableCell>{city.name_ar ?? '—'}</TableCell>
                                    <TableCell>
                                        <Ltr>{city.name_en ?? '—'}</Ltr>
                                    </TableCell>
                                    <TableCell className="text-end">
                                        <Num>{city.shipping_cost}</Num>
                                    </TableCell>
                                    {/* A plain number, not a badge. §2.4: a badge says "act on
                                        this", and nine customers having addresses in Cairo is a
                                        healthy fact — the same mistake the units screen makes with
                                        its usage count in red. The sentence under it is the reason
                                        the delete button is off, printed where the number is
                                        rather than crowding the buttons. */}
                                    <TableCell className="text-end">
                                        {city.addresses > 0 ? (
                                            <>
                                                <Num>{city.addresses}</Num>
                                                {abilities.manage ? (
                                                    <span className="mt-0.5 block text-[11px] leading-snug text-muted-foreground">
                                                        {t(
                                                            'shipping.linked_blocks_delete',
                                                            'لا يمكن حذفها ما دامت مرتبطة بعناوين عملاء',
                                                        )}
                                                    </span>
                                                ) : null}
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </TableCell>
                                    {abilities.manage && (
                                        <TableCell className="text-end">
                                            <div className="flex justify-end gap-1.5">
                                                <Button variant="outline" size="sm" onClick={() => setEditing(city.id)}>
                                                    {t('common.edit', 'تعديل')}
                                                </Button>
                                                <DeleteButton city={city} />
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ),
                        )}
                    </TableBody>
                </Table>
            </div>
        </ManageLayout>
    );
}

/** Add or edit — one form, because the fields are identical and a second one would drift. */
function CityForm({ city, onDone }: { city?: City; onDone: () => void }) {
    const t = useT();
    const form = useForm({
        name_ar: city?.name_ar ?? '',
        name_en: city?.name_en ?? '',
        shipping_cost: city?.shipping_cost ?? '0.00',
        // A PUT here REPLACES the row, so the server refuses a payload that has not declared
        // itself complete (`FullReplace`). This form always sends every field, so it may say so.
        _complete: 1,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const done = { onSuccess: () => onDone() };

        if (city) {
            // PUT, and every field is sent: the server asserts a full replace, so a partial form
            // would blank the name it did not carry.
            form.put(`/manage/shipping/${city.id}`, done);
        } else {
            form.post('/manage/shipping', done);
        }
    };

    return (
        <form onSubmit={submit} className="my-3 grid gap-3 rounded-md border p-3 sm:grid-cols-4">
            <div>
                <label className="text-xs text-muted-foreground" htmlFor="name_ar">
                    {t('shipping.governorate_ar', 'المحافظة (عربي)')}
                </label>
                <Input
                    id="name_ar"
                    value={form.data.name_ar}
                    onChange={(e) => form.setData('name_ar', e.target.value)}
                />
                {form.errors.name_ar && <p className="mt-1 text-xs text-destructive">{form.errors.name_ar}</p>}
            </div>
            <div>
                <label className="text-xs text-muted-foreground" htmlFor="name_en">
                    Governorate (English)
                </label>
                <Input
                    id="name_en"
                    dir="ltr"
                    value={form.data.name_en}
                    onChange={(e) => form.setData('name_en', e.target.value)}
                />
                {form.errors.name_en && <p className="mt-1 text-xs text-destructive">{form.errors.name_en}</p>}
            </div>
            <div>
                <label className="text-xs text-muted-foreground" htmlFor="shipping_cost">
                    {t('shipping.cost_egp', 'سعر الشحن (جنيه)')}
                </label>
                <Input
                    id="shipping_cost"
                    type="number"
                    step="0.01"
                    min="0"
                    dir="ltr"
                    value={form.data.shipping_cost}
                    onChange={(e) => form.setData('shipping_cost', e.target.value)}
                />
                {form.errors.shipping_cost && (
                    <p className="mt-1 text-xs text-destructive">{form.errors.shipping_cost}</p>
                )}
            </div>
            <div className="flex items-end gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    {t('common.save', 'حفظ')}
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>
                    {t('common.cancel', 'إلغاء')}
                </Button>
            </div>
        </form>
    );
}

/**
 * Delete, with the refusal explained before it is attempted.
 *
 * No `confirm()`: a browser dialog blocks the page and the server refuses a city with addresses
 * anyway. The button simply is not offered when deleting would be refused, and the reason is the
 * number already on the row.
 */
function DeleteButton({ city }: { city: City }) {
    const t = useT();

    if (city.addresses > 0) {
        /*
         * ── The reason is VISIBLE, and it is next to the NUMBER (J-8, then item 10) ─────────
         *
         * J-8: Cairo's `حذف` looks close enough to enabled, does nothing when clicked, and
         * explained itself only after about a second of hover — never on touch. That is reported
         * as "the dashboard is broken" rather than understood as a rule, and AGENTS §2.27 asks
         * for the reason ON the control.
         *
         * The first fix put the sentence inside this button's own cell, which crammed an
         * explanation and two buttons into one column and made the row read as a paragraph
         * (item 10). The sentence now lives in the linked-addresses cell, beside the count it is
         * about; the button keeps only the short form, which is what a disabled control needs to
         * carry on its own.
         */
        return (
            <Button variant="outline" size="sm" disabled title={t('shipping.delete', 'حذف')}>
                {t('shipping.delete_blocked_short', 'مرتبطة')}
            </Button>
        );
    }

    return (
        <Button
            variant="outline"
            size="sm"
            onClick={() => router.delete(`/manage/shipping/${city.id}`, { preserveScroll: true })}
        >
            {t('shipping.delete', 'حذف')}
        </Button>
    );
}
