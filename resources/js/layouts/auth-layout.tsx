import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { BrandMark } from '@/components/brand-mark';
import { Toaster } from '@/components/ui/toast';
import { useSyncAppearance } from '@/hooks/use-appearance';
import { home } from '@/routes';

/**
 * Shell for single-box auth screens (mockup 30, password confirmation): a
 * centered 440px surface box (`radius-lg`, elevation 1) with the brand
 * mark on top. The login screen composes its own two-column layout
 * (mockup 01) and does not use this.
 */
export default function AuthLayout({ children }: PropsWithChildren) {
    useSyncAppearance();

    return (
        <main className="grid min-h-svh place-items-center bg-background px-4 py-12">
            <div className="w-full max-w-[440px] rounded-lg border border-border bg-surface px-[18px] py-6 shadow-elevation-1 sm:p-8">
                <Link href={home()} className="no-underline">
                    <BrandMark />
                </Link>
                {children}
            </div>
            <Toaster />
        </main>
    );
}
