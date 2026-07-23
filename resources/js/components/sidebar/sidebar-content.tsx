import { Link, usePage } from '@inertiajs/react';
import { Calendar, LayoutGrid, Repeat } from 'lucide-react';

import { SidebarNavLink } from '@/components/sidebar/sidebar-nav-link';
import { SidebarProjectList } from '@/components/sidebar/sidebar-project-list';
import { SidebarUserMenu } from '@/components/sidebar/sidebar-user-menu';
import { ThemeToggle } from '@/components/sidebar/theme-toggle';
import { Separator } from '@/components/ui/separator';
import { home } from '@/routes';
import { today as habitsToday } from '@/routes/habits';
import { index as projectsIndex } from '@/routes/projects';

/**
 * Shared sidebar content: app name, primary nav (Proyectos, Calendario),
 * the user's project list, and the theme/account footer. Prop-less — it
 * self-sources `url` via `usePage()`, same as `SidebarProjectList`,
 * `SidebarUserMenu`, and `ThemeToggle` — so both the desktop `Sidebar`
 * and the mobile `MobileSidebar` drawer can mount this exact tree without
 * threading any state down.
 */
export function SidebarContent() {
    const { url } = usePage();

    return (
        <>
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
                    href={habitsToday()}
                    icon={<Repeat className="size-4" />}
                    active={url.startsWith('/habits')}
                >
                    Hábitos
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
        </>
    );
}
