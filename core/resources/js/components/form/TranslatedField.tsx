import { Field, type FieldShellProps } from '@/components/form/Field';
import { Input, Textarea } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type Translations = Record<string, string>;

/**
 * The ar/en pair every catalog form needs (product title, category name, spec label…).
 *
 * Both locales are on screen at once — no tabs. The team fills Arabic and English in one pass, and
 * a missing Arabic value is VISIBLE rather than hidden behind an unopened tab, which matters
 * because translation fallback is OFF (AGENTS §2.17: a missing Arabic row is a missing row, and
 * wave 4 must block publishing without it).
 *
 * Each box carries its own `dir` and `lang`, so Arabic types right-to-left and English
 * left-to-right inside the same RTL screen, and each shows its own server-side error: Laravel
 * reports `title.ar` and `title.en` separately, and this component reads them that way.
 */
export function TranslatedField({
    name,
    value,
    onChange,
    errors = {},
    locales = ['ar', 'en'],
    multiline = false,
    required = false,
    ...shell
}: Omit<FieldShellProps, 'error'> & {
    /** Field name as Laravel sees it — used to look up `name.ar` / `name.en` in the error bag. */
    name: string;
    value: Translations;
    onChange: (value: Translations) => void;
    errors?: Record<string, string>;
    locales?: string[];
    multiline?: boolean;
    required?: boolean;
}) {
    const LABELS: Record<string, string> = { ar: 'العربية', en: 'English' };

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2">
                <span className="text-sm font-medium">{shell.label}</span>
                {required ? (
                    <span className="text-destructive" aria-hidden="true">
                        *
                    </span>
                ) : null}
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                {locales.map((locale) => {
                    const error = errors[`${name}.${locale}`] ?? null;
                    const rtl = locale === 'ar';

                    return (
                        <Field
                            key={locale}
                            label={LABELS[locale] ?? locale}
                            error={error}
                            className={cn('rounded-lg border bg-muted/30 p-3', error !== null && 'border-destructive/50')}
                            render={(attrs) =>
                                multiline ? (
                                    <Textarea
                                        {...attrs}
                                        dir={rtl ? 'rtl' : 'ltr'}
                                        lang={locale}
                                        value={value[locale] ?? ''}
                                        onChange={(event) => onChange({ ...value, [locale]: event.target.value })}
                                    />
                                ) : (
                                    <Input
                                        {...attrs}
                                        dir={rtl ? 'rtl' : 'ltr'}
                                        lang={locale}
                                        value={value[locale] ?? ''}
                                        onChange={(event) => onChange({ ...value, [locale]: event.target.value })}
                                    />
                                )
                            }
                        />
                    );
                })}
            </div>

            {shell.hint ? <p className="text-xs text-muted-foreground">{shell.hint}</p> : null}
            {locales.some((locale) => (value[locale] ?? '') === '') ? (
                <Badge variant="warning">
                    {locales
                        .filter((locale) => (value[locale] ?? '') === '')
                        .map((locale) => LABELS[locale] ?? locale)
                        .join(' · ')}{' '}
                    ناقص
                </Badge>
            ) : null}
        </div>
    );
}
