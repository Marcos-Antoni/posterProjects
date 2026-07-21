import type { PropsWithChildren } from 'react';

import { Sidebar } from '@/components/sidebar/sidebar';

/**
 * Shared shell for authenticated pages: a persistent sidebar (project
 * list, nav, account) next to the page content. Attach via the Inertia
 * persistent-layout pattern, e.g.:
 *
 *   ProjectsIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
 */
export default function AppLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-svh bg-background">
            <Sidebar />
            <main className="flex-1 overflow-y-auto p-6 md:p-8">
                {children}
            </main>
        </div>
    );
}
