import type { PropsWithChildren } from 'react';

import { MobileSidebar } from '@/components/sidebar/mobile-sidebar';
import { Sidebar } from '@/components/sidebar/sidebar';
import { Toaster } from '@/components/ui/toast';
import { useSyncAppearance } from '@/hooks/use-appearance';

/**
 * Shared shell for authenticated pages: a persistent sidebar (project
 * list, nav, account) next to the page content. Below `md` the sidebar is
 * replaced by `MobileSidebar`'s sticky header + drawer, stacked above the
 * content instead of beside it. The content column follows the mockups'
 * `.page` (max 1200px, 40px/32px padding; 28px/16px on small screens).
 * Attach via the Inertia persistent-layout pattern, e.g.:
 *
 *   ProjectsIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
 */
export default function AppLayout({ children }: PropsWithChildren) {
    useSyncAppearance();

    return (
        <div className="flex min-h-svh flex-col bg-background md:flex-row">
            <MobileSidebar />
            <Sidebar />
            <main className="min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto w-full max-w-[1200px] px-4 pt-7 pb-12 md:px-8 md:pt-10 md:pb-16">
                    {children}
                </div>
            </main>
            <Toaster />
        </div>
    );
}
