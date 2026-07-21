import { Link } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';

import { cn } from '@/lib/utils';

type SidebarNavLinkProps = ComponentProps<typeof Link> & {
    icon: ReactNode;
    active?: boolean;
};

/**
 * A single top-level sidebar navigation item (e.g. "Proyectos",
 * "Calendario"). Highlights itself when `active` matches the current page.
 */
export function SidebarNavLink({
    icon,
    active = false,
    className,
    children,
    ...props
}: SidebarNavLinkProps) {
    return (
        <Link
            {...props}
            className={cn(
                'flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-sm font-medium text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                active && 'bg-sidebar-accent text-sidebar-accent-foreground',
                className,
            )}
        >
            {icon}
            {children}
        </Link>
    );
}
