import { Head, useForm, usePage } from '@inertiajs/react';
import { Loader2, LogIn, ShieldCheck } from 'lucide-react';
import { useEffect } from 'react';

import { Brand } from '@/components/manage/Brand';
import { PasswordField } from '@/components/form/PasswordField';
import { TextField } from '@/components/form/TextField';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

/**
 * Dashboard sign-in (item 16 — second pass, developer 2026-09-18).
 *
 * ── What the first redesign got wrong ────────────────────────────────────────────────────────
 *
 * *"Visually it's empty and cold. A small white card floating in a huge white page, nothing like
 * the dashboard behind it."* That was accurate. A centred card on a near-white ground has no
 * subject: the eye lands on nothing, and the page could belong to any application ever built.
 *
 * ── …and what the second attempt got wrong ──────────────────────────────────────────────────
 *
 * The split was right; what filled it was not. A navy gradient mesh with a dotted grid over it is
 * *"on every admin template on the internet"*, and the developer was right to say so. It was
 * decoration hired to do the job a point of view should have done.
 *
 * ── Where this page's look comes from now ────────────────────────────────────────────────────
 *
 * From what the shop sells. A watch is sold on black, alone, with room around it — so the panel is
 * black, the mark is the only object on it, and everything else there is space. That is a borrowed
 * language rather than an invented one, which is also what the earlier instruction asked for:
 * *"don't invent a second visual language."*
 *
 * The one accent is `--brand`, spent once, on the hairline at the seam — the dashboard's own accent
 * token, the same one an active nav item and a checked switch use. Spending it in a single place is
 * what makes it read as a decision instead of a theme.
 *
 * ── Why the form has no card ─────────────────────────────────────────────────────────────────
 *
 * *"Stop the card looking like a lost dialog."* A card separates its contents from a surrounding
 * context; on this page the form IS the context, so the border was drawing a box around the only
 * thing on that half of the screen. The split itself now does the work a card was failing at.
 *
 * ── Direction ────────────────────────────────────────────────────────────────────────────────
 *
 * The grid follows `dir`, so the panel sits on the reading-start side in both languages — right in
 * Arabic, left in English, which is where the sidebar is in each. The e-mail and password boxes stay
 * `dir="ltr"` regardless: an address or a password read right-to-left is unreadable.
 *
 * ── What did NOT change, and must not ────────────────────────────────────────────────────────
 *
 * No "remember me". The checkbox would write `users.remember_token`, and `users` is a legacy table
 * this application may not write (see LoginController). A server constraint, not a design choice.
 */
export default function Login({ status }: { status: string | null }) {
    const t = useT();
    const { dir, locale, errors } = usePage<SharedProps>().props;
    const form = useForm({ email: '', password: '' });

    useEffect(() => {
        document.documentElement.setAttribute('dir', dir);
        document.documentElement.setAttribute('lang', locale);
    }, [dir, locale]);

    const failed = typeof errors.email === 'string' && errors.email !== '';

    return (
        <div dir={dir} className="min-h-screen bg-background lg:grid lg:grid-cols-[1.05fr_1fr]">
            <Head title={t('auth.sign_in', 'تسجيل الدخول')} />

            {/*
              * ── The panel ────────────────────────────────────────────────────────────────────
              *
              * Black, empty, and the mark alone in it.
              *
              * The version before this one had a navy gradient mesh and a dotted grid, and the
              * developer's objection was that it is *"on every admin template on the internet"* —
              * which is true, and worse than looking generic, it was decoration standing in for a
              * point of view. What this shop sells is watches, and the way watches are sold is a
              * black field with one object in it and nothing else asking for attention.
              *
              * So: no gradient, no pattern, no texture. The only things drawn are the mark, the
              * one caption under it, and the hairline at the seam. Everything else is space, and
              * the space is the design — it stops being emptiness the moment nothing competes
              * with the object sitting in it.
              *
              * Black rather than the sidebar's `zinc-900`: at this scale, with nothing else on the
              * surface, zinc-900 reads as a grey that could not commit.
              */}
            {/*
              * ── The seam ───────────────────────────────────────────────────────────────────
              *
              * The one deliberate tie between the two halves, and the only accent anywhere on the
              * panel. Without it the black field and the white form are two screens that happen to
              * be adjacent; with it they share an edge that was obviously drawn on purpose.
              *
              * `--brand`, because that is the dashboard's accent everywhere else — the active nav
              * item, a checked switch, a selected row. Spending it in exactly one place is what
              * makes it read as a decision rather than a theme.
              *
              * It is a BORDER on the panel, not a positioned hairline. The first attempt was an
              * absolutely-placed div, and it drew nothing: `inset-x-0` sets the physical `left` and
              * `right`, `end-0` sets the logical `inset-inline-end`, and which of them wins comes
              * down to the order two unrelated rules happen to land in the stylesheet. A border has
              * no such argument with itself — and `border-e` is the panel's inner edge in both
              * directions, since the panel is always on the reading-start side.
              */}
            <div className="relative flex items-center justify-center border-b border-brand bg-black px-6 py-16 text-zinc-100 sm:px-10 lg:border-b-0 lg:border-e lg:py-24">
                <div className="flex flex-col items-center text-center">
                    {/* The light asset: the default mark is dark ink and would vanish here. It
                        carries the wordmark, so the business name is not set a second time under
                        it — that would be the same word twice, which is what a logo is for. */}
                    <Brand variant="light" className="h-20 w-auto sm:h-24 lg:h-36" />

                    {/*
                      * No letter-spacing, deliberately. Tracked-out capitals are the usual way to
                      * set a caption like this, and in Arabic they are wrong: the script joins, and
                      * spacing the glyphs pulls apart letters that are meant to connect.
                      */}
                    <p className="mt-6 text-sm text-zinc-500 lg:mt-10 lg:text-base">
                        {t('shell.dashboard', 'لوحة التحكم')}
                    </p>
                </div>

                {/*
                  * The three feature lines that used to sit here are GONE, not hidden.
                  *
                  * They were shot both ways at the developer's request. Demoted to small muted text
                  * at the foot of the panel they did not read as a deliberate quiet layer — they
                  * read as something left behind, because a black field with one object in it has no
                  * room for a second thing that is only nearly invisible. The panel is stronger with
                  * nothing there, so there is nothing there.
                  *
                  * `auth.panel_orders` / `panel_catalog` / `panel_stock` went with them.
                  */}
            </div>

            {/* ── The form half ─────────────────────────────────────────────────────────────── */}
            <div className="flex items-center justify-center px-6 py-12 sm:px-10">
                <div className="w-full max-w-sm space-y-8">
                    {/* No sub-heading. "Sign in with your email and password" described the two
                        labelled boxes directly beneath it — the interface narrating itself. */}
                    <h1 className="text-2xl font-semibold tracking-tight">{t('auth.sign_in', 'تسجيل الدخول')}</h1>

                    {status !== null ? <Alert tone="success">{status}</Alert> : null}

                    {/*
                      * ── The refusal ──────────────────────────────────────────────────────────
                      *
                      * One line. What happened, and nothing else.
                      *
                      * It got here in three cuts. It began as five bullets plus a paragraph of
                      * security reasoning; the bullets went because *"nobody reads that at 9am"*.
                      * The last survivor was a line telling the operator to press the eye icon if
                      * they had typed on an Arabic layout, and it went too:
                      *
                      *   *"that's not an error message — it's a usage tip that assumes the operator
                      *   is stupid. The eye icon is right there; it doesn't need instructions."*
                      *
                      * Which is the rule now, here and everywhere else: a message says what
                      * happened. It does not narrate the interface back to the person looking at it.
                      *
                      * The security reasoning stays in this comment, where it costs nobody time: the
                      * server answers "no such address" and "wrong password" with the SAME sentence
                      * on purpose, because told apart this form would answer "does this address have
                      * an account here?" for anyone who asked. `LoginScreenTest` pins that the two
                      * produce one identical message, so it cannot drift apart later by somebody
                      * making each case individually more helpful.
                      */}
                    {failed ? <Alert tone="error">{errors.email}</Alert> : null}

                    <form
                        className="space-y-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/manage/login', { onFinish: () => form.reset('password') });
                        }}
                    >
                        <TextField
                            label={t('auth.email', 'البريد الإلكتروني')}
                            type="email"
                            dir="ltr"
                            required
                            autoComplete="username"
                            value={form.data.email}
                            onChange={(value) => form.setData('email', value)}
                            // Marked as invalid, but with no message of its own: the sentence is in
                            // the alert above, and repeating it under each box would say the same
                            // thing three times for one mistake.
                            error={failed ? ' ' : null}
                            placeholder="name@example.com"
                        />

                        <PasswordField
                            label={t('common.password', 'كلمة المرور')}
                            required
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(value) => form.setData('password', value)}
                            error={failed ? ' ' : (errors.password ?? null)}
                        />

                        <Button type="submit" className="w-full gap-2" size="lg" disabled={form.processing}>
                            {form.processing ? (
                                <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                            ) : (
                                <LogIn className="h-4 w-4" aria-hidden="true" />
                            )}
                            {form.processing ? t('auth.signing_in', 'جارٍ الدخول…') : t('auth.sign_in', 'تسجيل الدخول')}
                        </Button>
                    </form>

                    {/* The panel's foot belongs to the demoted lines now, so this sits here at
                        every width rather than only on a phone. */}
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <ShieldCheck className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {t('auth.staff_only', 'الحسابات نفسها المستخدمة في اللوحة الحالية.')}
                    </p>
                </div>
            </div>
        </div>
    );
}
