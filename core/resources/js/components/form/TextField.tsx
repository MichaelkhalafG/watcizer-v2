import { Field, type FieldShellProps } from '@/components/form/Field';
import { Input, Select, Textarea } from '@/components/ui/input';

type Common = FieldShellProps & { value: string; onChange: (value: string) => void; disabled?: boolean; placeholder?: string };

export function TextField({ value, onChange, disabled, placeholder, type = 'text', dir, ...shell }: Common & { type?: string; dir?: 'rtl' | 'ltr' }) {
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
