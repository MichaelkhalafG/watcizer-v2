import { Field, type FieldShellProps } from '@/components/form/Field';
import { Input, Select, Textarea } from '@/components/ui/input';

type Common = FieldShellProps & { value: string; onChange: (value: string) => void; disabled?: boolean; placeholder?: string };

/**
 * `min`, `max` and `step` are passed through on purpose (task 4.2, 2026-09-11).
 *
 * A price of -5 or a stock of "abc" must be refused BY THE FIELD, not by the validator after a
 * submit: `type="number"` with `min={0}` makes the browser's own spinner and keyboard refuse the
 * negative, and `inputMode="decimal"` puts the right keypad in front of a phone. The server still
 * validates — an HTML attribute is a courtesy to the operator, never a guarantee — but the
 * operator should never have to discover a rule by breaking it.
 */
export function TextField({
    value,
    onChange,
    disabled,
    placeholder,
    type = 'text',
    dir,
    min,
    max,
    step,
    inputMode,
    ...shell
}: Common & {
    type?: string;
    dir?: 'rtl' | 'ltr';
    min?: number | string;
    max?: number | string;
    step?: number | string;
    inputMode?: 'text' | 'decimal' | 'numeric';
}) {
    return (
        <Field
            {...shell}
            render={(attrs) => (
                <Input
                    {...attrs}
                    type={type}
                    value={value}
                    dir={dir}
                    disabled={disabled}
                    placeholder={placeholder}
                    min={min}
                    max={max}
                    step={step}
                    inputMode={inputMode ?? (type === 'number' ? 'decimal' : undefined)}
                    onChange={(event) => onChange(event.target.value)}
                />
            )}
        />
    );
}

export function TextareaField({ value, onChange, disabled, placeholder, rows, ...shell }: Common & { rows?: number }) {
    return (
        <Field
            {...shell}
            render={(attrs) => (
                <Textarea {...attrs} value={value} rows={rows} disabled={disabled} placeholder={placeholder} onChange={(event) => onChange(event.target.value)} />
            )}
        />
    );
}

/** A short list (a family, a locale, a status): the native control, for the reasons in ui/input.tsx. */
export function SelectField({
    value,
    onChange,
    options,
    disabled,
    placeholder,
    ...shell
}: FieldShellProps & {
    value: string;
    onChange: (value: string) => void;
    options: Array<{ value: string; label: string }>;
    disabled?: boolean;
    placeholder?: string;
}) {
    return (
        <Field
            {...shell}
            render={(attrs) => (
                <Select {...attrs} value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)}>
                    {placeholder !== undefined ? <option value="">{placeholder}</option> : null}
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>
            )}
        />
    );
}
