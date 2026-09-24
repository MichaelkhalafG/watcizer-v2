import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Lock, SearchX } from 'lucide-react';

import { Brand } from '@/components/manage/Brand';
import { Button } from '@/components/ui/button';
import { Num } from '@/components/ui/bidi';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

/**
 * The dashboard's dead end (D-17).
 *
 * Every word arrives from the server already translated — `App\Http\ManageError` decides what a
 * 403 and a 404 each have to say, because "what can this person do next" is a server question
 * (who they are, what they hold) and not a string table.
 *
 * Two shells, one page. Signed in, it renders inside `ManageLayout`, so the sidebar is right there
 * and the dead end is a screen of the dashboard rather than an exit from it. Signed out — a guest
 * mistyping a `/manage` URL — there is no menu to render and no session to show, so it falls back
 * to a centred card on the dashboard's own background. The one thing both must have is a way back,
 * which is what the pages this replaces did not.
 */

interface Props {
    status: number;
    title: string;
    body: string;
    who_to_ask: string | null;
    home: string;
    signed_in: boolean;
}

function Body({ status, title, body, who_to_ask: whoToAsk, home, signed_in: signedIn }: Props) {
    const t = useT();
    const Icon = status === 403 ? Lock : SearchX;

    return (
        <div className="mx-auto flex w-full max-w-xl flex-col items-center gap-5 py-10 text-center sm:py-16">
            <span className="flex h-14 w-14 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Icon className="h-7 w-7" aria-hidden="true" />
            </span>

            <div className="space-y-2">
                <h1 className="text-xl font-semibold">{title}</h1>
                <p className="text-sm leading-relaxed text-muted-foreground">{body}</p>
            </div>

            {/* Only printed when there IS somebody on the other side of it — a 404 has no
                gatekeeper, and sending the reader to ask about an address that does not exist
                wastes two people's afternoon instead of one's. */}
            {whoToAsk === null ? null : (
                <p className="rounded-md border border-dashed px-4 py-3 text-sm leading-relaxed text-muted-foreground">
                    {whoToAsk}
                </p>
            )}

            <Button asChild>
                <Link href={home} className="gap-2">
                    <ArrowLeft className="h-4 w-4 rtl:rotate-180" aria-hidden="true" />
                    {signedIn
                        ? t('errors.back_home', 'ارجع إلى لوحة التحكم')
                        : t('errors.back_login', 'اذهب إلى تسجيل الدخول')}
                </Link>
            </Button>

            {/* The code, small and last. It is the one thing worth quoting when reporting this,
                and the one thing the reader does not need in order to know what to do.

                `<Num>` rather than `dir="ltr"` on the paragraph: direction on a BLOCK resolves
                `text-align: start` against the element's own direction, which is what pulled every
                dashboard table's values out from under their headers (see components/ui/bidi). */}
            <p className="text-xs text-muted-foreground">
                <Num>{status}</Num>
            </p>
        </div>
    );
}

export default function ManageErrorPage(props: Props) {
    const { auth } = usePage<SharedProps>().props;

    if (auth.user !== null) {
        return (
            <ManageLayout title={props.title}>
                <Body {...props} />
            </ManageLayout>
        );
    }

    return (
        <div className="flex min-h-screen flex-col bg-background px-4 text-foreground">
            <Head title={props.title} />
            <div className="flex items-center justify-center py-8">
                <Brand className="h-8 w-auto" />
            </div>
            <div className="flex flex-1 items-start justify-center">
                <Body {...props} />
            </div>
        </div>
    );
}
