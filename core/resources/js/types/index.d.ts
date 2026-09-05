/** Props shared with every Inertia page by App\Http\Middleware\HandleInertiaRequests. */
export interface SharedProps {
    app: { name: string };
    locale: 'ar' | 'en';
    dir: 'rtl' | 'ltr';
    [key: string]: unknown;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = SharedProps & T;
