import { Info } from 'lucide-react';

import { SelectField, TextField } from '@/components/form/TextField';
import { Alert } from '@/components/ui/alert';
import { SwitchField } from '@/components/form/SwitchField';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export interface SpecField {
    key: string;
    label: string;
    type: 'string' | 'integer' | 'decimal' | 'boolean' | 'lookup';
    /** A dimension's unit column — rendered as a second control on the same row. */
    unit?: string;
    /** A lookup key from config('catalog.lookups'). */
    lookup?: string;
}

export interface SpecBlockDef {
    family: string;
    label: string;
    /** 'watch_specs' (its own table) or 'specs' (the JSON column). */
    table: string;
    fields: SpecField[];
}

export type SpecValues = Record<string, string | number | boolean | null>;

export interface FamilyExplanation {
    family: string;
    node_id: number | null;
    node_en: string;
    root_en: string;
    reason: string;
    /** The family the product is SAVED with — null on a create screen. */
    saved_family: string | null;
}

/**
 * The family-aware half of the product form.
 *
 * ── It re-renders when the category changes, and that took a fix (task 4.1, 2026-09-11) ──────
 *
 * The form used to compute the shown family by SPREADING the server's answer and overwriting only
 * `node_id` and `reason`, so changing the category rewrote the explanation sentence and left
 * `family` — and therefore `blocks[family]`, and therefore every field on screen — exactly as it
 * was. The screen looked reactive and was not: a watch moved to Bags kept asking for its case
 * diameter, and the developer found it by walking the UI.
 *
 * The fix is not a TypeScript mirror of the derivation rule. The server resolves the family for
 * EVERY category it offers and ships it with the option (`option.family`), so this component is
 * handed the answer rather than deriving one. One rule, one implementation, no drift.
 *
 * The block that appears here is decided by the product's FAMILY, which the server derives from
 * its CATEGORY (App\Domain\Catalog\FamilyForCategory) using the same config and the same class as
 * the transform. The form never asks the team which family a product is: choosing "Bags" as the
 * category IS choosing the bag block, and that is the whole point — the legacy dashboard asked
 * both questions and answered the second one from hard-coded category ids.
 *
 * So this component renders:
 *   • the derived family and the REASON ("التصنيف «Bags» تحت الجذر «Fashion»"), because a block
 *     that appears without explanation looks like a bug;
 *   • the block's fields, from the server's definition, typed;
 *   • nothing at all for `fashion` and `other`, with a sentence saying why — they are the
 *     resolver's fallbacks, and inventing attributes for "did not match anything" would make the
 *     form lie about the data.
 *
 * All blocks are shipped to the browser, so changing the category re-renders instantly. The server
 * still decides on save; this is a rendering hint, never the authority.
 */
export function SpecBlock({
    explanation,
    blocks,
    lookups,
    values,
    onChange,
    errors,
}: {
    explanation: FamilyExplanation;
    blocks: Record<string, SpecBlockDef>;
    lookups: Record<string, Array<{ value: string; label: string }>>;
    values: SpecValues;
    onChange: (values: SpecValues) => void;
    errors: Record<string, string>;
}) {
    const block = blocks[explanation.family];

    /*
     * Moving a product to another family DISCARDS the old block's values, and the operator has to
     * be told before the save, not after it. The server rebuilds `specs` from the new family's
     * keys and deletes the watch row when the new family is not `watch` — which is the right
     * behaviour (a bag has no case diameter) and is exactly why it must not be silent.
     */
    const saved = explanation.saved_family;
    const losing = saved !== null && saved !== explanation.family ? blocks[saved] : undefined;
    const losingFilled =
        losing === undefined
            ? []
            : losing.fields
                  .filter((field) => {
                      const value = values[field.key];

                      return value !== null && value !== undefined && value !== '' && value !== false;
                  })
                  .map((field) => field.label);
    const set = (key: string, value: string | number | boolean | null) => onChange({ ...values, [key]: value });
    const text = (key: string) => {
        const value = values[key];

        return value === null || value === undefined ? '' : String(value);
    };

    return (
        <Card>
            <CardHeader className="gap-2">
                <CardTitle>{block === undefined ? 'مواصفات إضافية' : block.label}</CardTitle>
                {/* The derivation, in words. This is the sentence that makes the whole screen
                    trustworthy: the team can see WHY they are being shown watch fields. */}
                <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                    <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span>
                        العائلة المحسوبة: <strong className="text-foreground">{explanation.family}</strong> — {explanation.reason}
                    </span>
                </p>
            </CardHeader>

            <CardContent className="space-y-4">
                {losing === undefined ? null : (
                    <Alert tone="warning" title="تغيير التصنيف يغيّر نوع المواصفات">
                        هذا المنتج محفوظ الآن كـ «{losing.label}». بعد الحفظ ستصبح مواصفاته «
                        {block === undefined ? 'بلا مواصفات' : block.label}»، و
                        {losingFilled.length === 0
                            ? 'لا توجد قيم قديمة ستُفقد.'
                            : `ستُحذف القيم المكتوبة في: ${losingFilled.join('، ')}.`}{' '}
                        لو لم يكن هذا ما تريده، أعِد اختيار التصنيف الأساسي السابق قبل الحفظ.
                    </Alert>
                )}

                {block === undefined ? (
                    <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        لا توجد مواصفات خاصة بهذه العائلة. العائلتان <code>fashion</code> و<code>other</code> هما الحالة الافتراضية لقاعدة
                        الاشتقاق، وإضافة حقول لهما تعني اختراع بيانات لا يعرفها النظام. لو احتاج هذا النوع مواصفات، يُضاف قسم له في{' '}
                        <code dir="ltr">config/catalog.php</code> وتُشتق العائلة من تصنيف مناسب.
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {block.fields.map((field) => {
                            const error = errors[`specs.${field.key}`] ?? null;

                            if (field.type === 'boolean') {
                                return (
                                    <SwitchField
                                        key={field.key}
                                        label={field.label}
                                        error={error}
                                        checked={values[field.key] === true || values[field.key] === 1 || values[field.key] === '1'}
                                        onChange={(checked) => set(field.key, checked)}
                                    />
                                );
                            }

                            if (field.type === 'lookup') {
                                return (
                                    <SelectField
                                        key={field.key}
                                        label={field.label}
                                        error={error}
                                        placeholder="—"
                                        value={text(field.key)}
                                        options={lookups[field.lookup ?? ''] ?? []}
                                        onChange={(value) => set(field.key, value === '' ? null : value)}
                                    />
                                );
                            }

                            // A number and its unit are one row: a measurement without a unit is
                            // not a measurement, and the schema pairs them for that reason.
                            return (
                                <div key={field.key} className={field.unit === undefined ? '' : 'grid grid-cols-[1fr_8rem] gap-2'}>
                                    <TextField
                                        label={field.label}
                                        error={error}
                                        dir="ltr"
                                        type={field.type === 'string' ? 'text' : 'number'}
                                        value={text(field.key)}
                                        onChange={(value) => set(field.key, value === '' ? null : value)}
                                    />
                                    {field.unit === undefined ? null : (
                                        <SelectField
                                            label="الوحدة"
                                            error={errors[`specs.${field.unit}`] ?? null}
                                            placeholder="—"
                                            value={text(field.unit)}
                                            options={lookups.units ?? []}
                                            onChange={(value) => set(field.unit as string, value === '' ? null : value)}
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
