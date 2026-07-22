import type { PropsWithChildren } from 'react';

import { MobileSidebar } from '@/components/sidebar/mobile-sidebar';
import { Sidebar } from '@/components/sidebar/sidebar';

/**
 * Shared shell for authenticated pages: a persistent sidebar (project
 * list, nav, account) next to the page content. Below `md` the sidebar is
 * replaced by `MobileSidebar`'s sticky header + drawer, stacked above the
 * content instead of beside it. Attach via the Inertia persistent-layout
 * pattern, e.g.:
 *
 *   ProjectsIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
 */
export default function AppLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-svh flex-col bg-background md:flex-row">
            <MobileSidebar />
            <Sidebar />
            <main className="flex-1 overflow-y-auto p-6 md:p-8">
                {children}
            </main>
        </div>
    );
}
