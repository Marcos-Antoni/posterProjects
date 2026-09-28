import { Link, usePage } from '@inertiajs/react';
import { KeyRound, Smartphone, Sun } from 'lucide-react';
import type { PropsWithChildren, ReactNode } from 'react';

import { show as appearanceShow } from '@/actions/App/Http/Controllers/Settings/AppearanceController';
import { show as mcpTokenShow } from '@/actions/App/Http/Controllers/Settings/McpTokenController';
import { show as mobileTokenShow } from '@/actions/App/Http/Controllers/Settings/MobileTokenController';
import { cn } from '@/lib/utils';

type SettingsSection = {
    label: string;
    href: string;
    icon: ReactNode;
};

const SECTIONS: SettingsSection[] = [
    {
        label: 'Token MCP',
        href: mcpTokenShow.url(),
        icon: <KeyRound className="size-4" strokeWidth={1.75} />,
    },
    {
        label: 'App móvil y QR',
        href: mobileTokenShow.url(),
        icon: <Smartphone className="size-4" strokeWidth={1.75} />,
    },
    {
        label: 'Apariencia',
        href: appearanceShow.url(),
        icon: <Sun className="size-4" strokeWidth={1.75} />,
    },
];

/**
 * Settings shell from mockups 27–29: a 220px section nav (sticky, current
 * page marked with an inset blaze-ink bar) next to a 720px content column.
 * Below 900px the nav becomes a wrapping row above the content. Settings
 * are deliberately few (D14): tokens and appearance — no profile or
 * password pages.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { url } = usePage();
    const path = url.split('?')[0];

    return (
        <div className="grid items-start gap-5 min-[900px]:grid-cols-[220px_minmax(0,720px)] min-[900px]:gap-12">
            <nav
                aria-label="Ajustes"
                className="flex flex-row flex-wrap gap-0.5 min-[900px]:sticky min-[900px]:top-4 min-[900px]:flex-col"
            >
                <p className="mb-2 ml-3 hidden text-[13px] leading-[18px] font-semibold text-muted-foreground min-[900px]:block">
                    Ajustes
                </p>
                {SECTIONS.map((section) => {
                    const current = path === section.href;

                    return (
                        <Link
                            key={section.href}
                            href={section.href}
                            aria-current={current ? 'page' : undefined}
                            className={cn(
                                'flex min-h-10 items-center gap-2.5 rounded-sm px-3 py-2 text-sm font-semibold text-muted-foreground no-underline hover:bg-sunken hover:text-foreground',
                                current &&
                                    'bg-surface text-foreground shadow-[inset_0_-3px_0_var(--blaze-ink)] min-[900px]:shadow-[inset_3px_0_0_var(--blaze-ink)]',
                            )}
                        >
                            {section.icon}
                            {section.label}
                        </Link>
                    );
                })}
            </nav>
            <div className="min-w-0">{children}</div>
        </div>
    );
}
