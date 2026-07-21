import { Link, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';

import { destroy } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';

/**
 * Bottom-of-sidebar account section: avatar + name/email of the
 * authenticated user, plus a working logout button (POST via Wayfinder).
 */
export function SidebarUserMenu() {
    const { props } = usePage();
    const { user } = props.auth;

    const initials =
        user.name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase())
            .join('') || '?';

    return (
        <div className="flex items-center gap-2 rounded-lg p-2">
            <Avatar size="sm">
                <AvatarFallback>{initials}</AvatarFallback>
            </Avatar>

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-sidebar-foreground">
                    {user.name}
                </p>
                <p className="truncate text-xs text-sidebar-foreground/60">
                    {user.email}
                </p>
            </div>

            <Link
                href={destroy()}
                as="button"
                title="Cerrar sesión"
                className="flex size-7 shrink-0 items-center justify-center rounded-lg text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
            >
                <LogOut className="size-4" />
                <span className="sr-only">Cerrar sesión</span>
            </Link>
        </div>
    );
}
