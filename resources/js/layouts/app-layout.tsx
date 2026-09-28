import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { CommandPalette } from '@/components/marcos/command-palette';
import { SettingsIcon } from '@/components/marcos/icons';
import { Toaster } from '@/components/ui/toast';
import { useSyncAppearance } from '@/hooks/use-appearance';
import { today as habitsToday } from '@/routes/habits';
import { index as objectivesIndex } from '@/routes/objectives';
import { show as mcpTokenShow } from '@/routes/settings/mcp-token';

type NavEntry = { label: string; href: string; match: string };

/**
 * The mockups' primary navigation, in their order: Ahora · Objetivos · Mapa ·
 * Hábitos · Revisiones · Retirados · IA. Each phase adds its own entry on its
 * own line, where the mockup shows it, when its screen exists.
 */
const NAV: NavEntry[] = [
    {
        label: 'Objetivos',
        href: objectivesIndex().url,
        match: '/objectives',
    } /* phase 2 */,
    {
        label: 'Hábitos',
        href: habitsToday().url,
        match: '/habits',
    } /* phase 2 (existing screen) */,
];

/**
 * Shared shell for authenticated pages (every mockup's `.appbar`): the
 * wordmark, the primary navigation and, on the right, "Ir a…" (Ctrl K) and
 * Ajustes. The content column is the mockups' `.page` (max 1200px, 40/32px
 * padding; 28/16px below 900px). Everything sits under `.mos`, the scope of
 * the ported mockup styles. Attach via the Inertia persistent-layout
 * pattern, e.g.:
 *
 *   ObjectivesIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
 */
export default function AppLayout({ children }: PropsWithChildren) {
    useSyncAppearance();

    const { url } = usePage();
    const path = url.split('?')[0];

    return (
        <div className="mos flex min-h-svh flex-col bg-background">
            <header className="appbar">
                <Link
                    className="brand"
                    href={objectivesIndex()}
                    aria-label="Marcos OS, ir a Objetivos"
                >
                    <i aria-hidden="true" />
                    <span>Marcos OS</span>
                </Link>
                <nav className="nav" aria-label="Principal">
                    {NAV.map((entry) => (
                        <Link
                            key={entry.href}
                            href={entry.href}
                            aria-current={
                                path.startsWith(entry.match)
                                    ? 'page'
                                    : undefined
                            }
                        >
                            {entry.label}
                        </Link>
                    ))}
                </nav>
                <div className="right">
                    <CommandPalette />
                    <Link
                        className="btn-sm btn-ghost"
                        href={mcpTokenShow()}
                        aria-label="Ajustes"
                        aria-current={
                            path.startsWith('/settings') ? 'page' : undefined
                        }
                    >
                        <SettingsIcon />
                    </Link>
                </div>
            </header>
            <main className="min-w-0 flex-1">
                <div className="mx-auto w-full max-w-[1200px] px-4 pt-7 pb-12 min-[900px]:px-8 min-[900px]:pt-10 min-[900px]:pb-16">
                    {children}
                </div>
            </main>
            <Toaster />
        </div>
    );
}
