import { usePage } from '@inertiajs/react';

import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/**
 * The storefront mark. Two files, one per surface: dark ink for light backgrounds, white ink for
 * the dark sidebar — the mark is dark on transparency, so a single asset would vanish on one of
 * them.
 *
 * Both paths come from the `branding` shared prop, i.e. from App\Support\Branding, i.e. from config
 * today and from storefront settings later. Nothing here knows a filename.
 */
export function Brand({ variant = 'default', className }: { variant?: 'default' | 'light'; className?: string }) {
    const { branding } = usePage<SharedProps>().props;
    const src = variant === 'light' ? branding.logo_light : branding.logo;

    return (
        <img
            src={src}
            alt={branding.name}
            width={branding.logo_width}
            height={branding.logo_height}
            className={cn('h-8 w-auto object-contain', className)}
            // The intrinsic box is known, so the header never reflows once the mark loads.
            style={{ aspectRatio: `${branding.logo_width} / ${branding.logo_height}` }}
        />
    );
}
