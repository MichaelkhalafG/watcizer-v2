import { X } from 'lucide-react';
import { useState } from 'react';

import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * A list of e-mail addresses as chips (2026-10-01, the re-engagement rework). Type an address and
 * press Enter, a comma or a space — or paste several at once. Each one is checked as it is added: a
 * bad one stays in the box with the reason, a duplicate is refused and said so, Backspace in an empty
 * box takes the last chip off. The server checks again (`team_emails.*`); its message for a chip is
 * shown under the field via `errors`.
 */
const EMAIL = /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]{2,}$/;

export default function EmailChips({
    id,
    value,
    onChange,
    max = 20,
    errors = [],
}: {
    id?: string;
    value: string[];
    onChange: (emails: string[]) => void;
    max?: number;
    /** Server messages for this field and its chips, already resolved to text. */
    errors?: string[];
}) {
    const t = useT();
    const [draft, setDraft] = useState('');
    const [problem, setProblem] = useState<string | null>(null);

    /** Add every address in `text`; whatever cannot be added stays in the box with the reason. */
    const commit = (text: string): void => {
        const parts = text.split(/[\s,;]+/).map((part) => part.trim()).filter(Boolean);
        if (parts.length === 0) {
            setDraft('');
            setProblem(null);
            return;
        }
        const next = [...value];
        const left: string[] = [];
        let reason: string | null = null;
        for (const part of parts) {
            const email = part.toLowerCase();
            if (!EMAIL.test(email)) {
                left.push(part);
                reason = t('chips.invalid', '«:email» ليس بريداً إلكترونياً صحيحاً.', { email: part });
            } else if (next.includes(email)) {
                reason = t('chips.duplicate', '«:email» مضاف بالفعل.', { email });
            } else if (next.length >= max) {
                left.push(part);
                reason = t('chips.max', 'يمكن إضافة :max بريداً على الأكثر.', { max });
            } else {
                next.push(email);
            }
        }
        if (next.length !== value.length) onChange(next);
        setDraft(left.join(' '));
        setProblem(reason);
    };

    return (
        <div className="space-y-1">
            <div
                className={cn(
                    'flex min-h-10 flex-wrap items-center gap-1.5 rounded-md border bg-background p-1.5 focus-within:ring-2 focus-within:ring-ring',
                    (problem || errors.length > 0) && 'border-destructive',
                )}
                dir="ltr"
            >
                {value.map((email) => (
                    <span key={email} className="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-sm">
                        {email}
                        <button
                            type="button"
                            className="rounded hover:bg-background"
                            onClick={() => onChange(value.filter((x) => x !== email))}
                            aria-label={t('chips.remove', 'إزالة :email', { email })}
                            title={t('chips.remove', 'إزالة :email', { email })}
                        >
                            <X className="size-3.5" />
                        </button>
                    </span>
                ))}
                <input
                    id={id}
                    type="email"
                    inputMode="email"
                    autoComplete="off"
                    className="min-w-[12rem] flex-1 bg-transparent px-1 py-1 text-sm outline-none"
                    value={draft}
                    placeholder={value.length === 0 ? 'name@example.com' : ''}
                    onChange={(event) => {
                        const text = event.target.value;
                        if (/[\s,;]$/.test(text)) commit(text);
                        else {
                            setDraft(text);
                            setProblem(null);
                        }
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            commit(draft);
                        } else if (event.key === 'Backspace' && draft === '' && value.length > 0) {
                            onChange(value.slice(0, -1));
                        }
                    }}
                    onPaste={(event) => {
                        event.preventDefault();
                        commit(`${draft} ${event.clipboardData.getData('text')}`);
                    }}
                    onBlur={() => draft.trim() !== '' && commit(draft)}
                />
            </div>
            {problem ? <p className="text-sm text-destructive">{problem}</p> : null}
            {errors.map((message) => (
                <p key={message} className="text-sm text-destructive">
                    {message}
                </p>
            ))}
            <p className="text-xs text-muted-foreground">{t('chips.hint', 'اكتب بريداً ثم اضغط Enter أو فاصلة لإضافته. يمكنك لصق عدة عناوين مرة واحدة.')}</p>
        </div>
    );
}
