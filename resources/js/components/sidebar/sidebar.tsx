import { Link, usePage } from '@inertiajs/react';
import { Calendar, LayoutGrid } from 'lucide-react';
import { motion } from 'motion/react';

import { SidebarNavLink } from '@/components/sidebar/sidebar-nav-link';
import { SidebarProjectList } from '@/components/sidebar/sidebar-project-list';
import { SidebarUserMenu } from '@/components/sidebar/sidebar-user-menu';
import { ThemeToggle } from '@/components/sidebar/theme-toggle';
import { Separator } from '@/components/ui/separator';
import { home } from '@/routes';
import { index as projectsIndex } from '@/routes/projects';

/**
 * Persistent app shell sidebar: app name, primary nav (Proyectos,
 * Calendario), the user's project list, and the account/logout section.
 */
export function Sidebar() {
    const { url } = usePage();

    return (
        <motion.aside
            initial={{ opacity: 0, x: -12 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3, ease: 'easeOut' }}
            className="flex w-64 shrink-0 flex-col gap-4 border-r border-sidebar-border bg-sidebar p-4 text-sidebar-foreground"
        >
            <Link
                href={home()}
                className="px-2.5 font-heading text-lg font-medium"
            >
                Jira Clone
            </Link>

            <nav className="flex flex-col gap-0.5">
                <SidebarNavLink
                    href={projectsIndex()}
                    icon={<LayoutGrid className="size-4" />}
                    active={url.startsWith('/projects')}
                >
                    Proyectos
                </SidebarNavLink>
                <SidebarNavLink
                    href="/calendar"
                    icon={<Calendar className="size-4" />}
                    active={url.startsWith('/calendar')}
                >
                    Calendario
                </SidebarNavLink>
            </nav>

            <Separator />

            <div className="flex flex-1 flex-col gap-1 overflow-y-auto">
                <p className="px-2.5 text-xs font-medium tracking-wide text-sidebar-foreground/50 uppercase">
                    Mis proyectos
                </p>
                <SidebarProjectList />
            </div>

            <Separator />

            <div className="flex flex-col gap-1">
                <ThemeToggle />
                <SidebarUserMenu />
            </div>
        </motion.aside>
    );
}
