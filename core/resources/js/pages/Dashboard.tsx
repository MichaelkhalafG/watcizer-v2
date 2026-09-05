import { Head } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout';

/** Wave-0 walking skeleton: proves Inertia + React + shadcn/ui render inside the RTL shell. */
export default function Dashboard() {
    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />
            <div className="rounded-lg border bg-card p-6 text-card-foreground shadow-sm">
                <p className="mb-4 text-sm text-muted-foreground">لوحة التحكم — الهيكل الأساسي يعمل.</p>
                <Button type="button">Skeleton OK</Button>
            </div>
        </AppLayout>
    );
}
