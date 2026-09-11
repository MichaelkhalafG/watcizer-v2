import { Head, useForm, usePage } from '@inertiajs/react';
import { Loader2, LogIn } from 'lucide-react';
import { useEffect } from 'react';

import { Brand } from '@/components/manage/Brand';
import { TextField } from '@/components/form/TextField';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { SharedProps } from '@/types';

/**
 * Dashboard sign-in.
 *
 * No developer credit here, by instruction — the footer of the shell is its only home. No
 * "remember me" either, and that is a server constraint rather than a design choice: the checkbox
 * would write `users.remember_token`, and `users` is a legacy table this application may not write
 * (see LoginController).
 *
 * The e-mail box is `dir="ltr"` inside an RTL page, because an address read right-to-left is
 * unreadable.
 */
export default function Login({ status }: { status: string | null }) {
    const { dir, locale, branding, errors } = usePage<SharedProps>().props;
    const form = useForm({ email: '', password: '' });

    useEffect(() => {
        document.documentElement.setAttribute('dir', dir);
        document.documentElement.setAttribute('lang', locale);
    }, [dir, locale]);

    return (
        <div dir={dir} className="flex min-h-screen items-center justify-center bg-muted/40 px-4 py-10">
            <Head title="تسجيل الدخول" />

            <div className="w-full max-w-sm space-y-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <Brand className="h-10" />
                    <div>
                        <h1 className="text-lg font-semibold">{branding.suffix}</h1>
                        <p className="text-sm text-muted-foreground">{branding.name}</p>
                    </div>
                </div>

                {status !== null ? <Alert tone="success">{status}</Alert> : null}

                <Card>
                    <CardContent className="py-6">
                        <form
                            className="space-y-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/manage/login', { onFinish: () => form.reset('password') });
                            }}
                        >
                            <TextField
                                label="البريد الإلكتروني"
                                type="email"
                                dir="ltr"
                                required
                                value={form.data.email}
                                onChange={(value) => form.setData('email', value)}
                                error={errors.email ?? null}
                                placeholder="name@example.com"
                            />

                            <TextField
                                label="كلمة المرور"
                                type="password"
                                dir="ltr"
                                required
                                value={form.data.password}
                                onChange={(value) => form.setData('password', value)}
                                error={errors.password ?? null}
                            />

                            <Button type="submit" className="w-full gap-2" disabled={form.processing}>
                                {form.processing ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <LogIn className="h-4 w-4" aria-hidden="true" />}
                                {form.processing ? 'جارٍ الدخول…' : 'تسجيل الدخول'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <p className="text-center text-xs text-muted-foreground">
                    الدخول لفريق العمل فقط. الحسابات نفسها المستخدمة في اللوحة الحالية.
                </p>
            </div>
        </div>
    );
}
