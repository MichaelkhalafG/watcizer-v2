import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';

import { Field, type FieldShellProps } from '@/components/form/Field';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

/**
 * A password box with a reveal toggle.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────────────────────
 *
 * The developer's reason, and it is the right one: *"They will be typing an Arabic-keyboard-layout
 * password wrong at least once a week, and this is the cheapest fix for it."* A password typed on
 * the wrong keyboard layout is invisible by definition — every character is a dot, so there is
 * nothing to notice and nothing to correct. The operator's only feedback is a refusal that cannot
 * tell them which half was wrong.
 *
 * ── Built on `Field`, not beside it ──────────────────────────────────────────────────────────
 *
 * `Field` owns the label, the hint, the error and the `aria-describedby` / `aria-invalid` wiring
 * that makes a screen reader read all three as one thing. A password box that reimplemented that
 * would be the fourth copy of accessibility code in this application; instead the toggle is drawn
 * INSIDE the control `Field` already asks for.
 *
 * ── The toggle's own accessibility ───────────────────────────────────────────────────────────
 *
 * `aria-pressed` rather than a label that changes meaning: a screen reader announces the button's
 * STATE, so "show password, pressed" is unambiguous where a button whose name flips between "show"
 * and "hide" is read differently depending on when you arrive at it. It is also `tabindex`-reachable
 * and never a `div`, because somebody on a keyboard needs it at least as much as somebody with a
 * mouse.
 *
 * `type="button"`, explicitly: a bare `<button>` inside a form submits it, so without this the eye
 * icon would try to sign the operator in with a half-typed password.
 */
export function PasswordField({
    value,
    onChange,
    autoComplete,
    ...shell
}: FieldShellProps & {
    value: string;
    onChange: (value: string) => void;
    autoComplete?: string;
}) {
    const t = useT();
    const [revealed, setRevealed] = useState(false);
    const Icon = revealed ? EyeOff : Eye;

    return (
        <Field
            {...shell}
            render={(attrs) => (
                /*
                 * ── Why everything here is PHYSICAL, not logical ──────────────────────────────
                 *
                 * The eye sits at the physical right of the box and the padding is reserved on the
                 * physical right, in both languages. That reads backwards until you notice the box
                 * itself never turns around: the `<input>` is always `dir="ltr"`, because a password
                 * is a sequence rather than a sentence and reading it right-to-left once revealed
                 * would defeat the point of revealing it. The button belongs where that fixed text
                 * ends, which is the right — so a logical `end-0` is not just unnecessary, it is
                 * the bug.
                 *
                 * It was the bug, twice over:
                 *
                 *  • `end-0` resolved to the LEFT on the RTL page while `pe-10` on the LTR input
                 *    reserved its space on the right, so the eye sat on top of the first characters
                 *    of the password. Only visible with it revealed — the one state that has
                 *    characters there to collide with.
                 *  • The obvious patch, `dir="ltr"` on this wrapper, is the exact thing
                 *    `RtlTableGuardTest` forbids, and it failed the battery. That rule was written
                 *    after `dir` on a box misaligned every table on the dashboard home: `text-align:
                 *    start` resolves against the box's OWN direction, so a div that declares itself
                 *    LTR silently left-aligns while its label stays right. The rule is right, and
                 *    the answer is not to turn a box around at all.
                 */
                <div className="relative">
                    <Input
                        {...attrs}
                        type={revealed ? 'text' : 'password'}
                        dir="ltr"
                        className="pr-10"
                        autoComplete={autoComplete}
                        value={value}
                        onChange={(event) => onChange(event.target.value)}
                    />

                    <button
                        type="button"
                        onClick={() => setRevealed((current) => !current)}
                        aria-pressed={revealed}
                        aria-label={t('auth.show_password', 'إظهار كلمة المرور')}
                        title={
                            revealed
                                ? t('auth.hide_password', 'إخفاء كلمة المرور')
                                : t('auth.show_password', 'إظهار كلمة المرور')
                        }
                        className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-md text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <Icon className="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>
            )}
        />
    );
}
