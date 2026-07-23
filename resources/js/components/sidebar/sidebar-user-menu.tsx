import { Link, usePage } from '@inertiajs/react';
import { ChevronsUpDown, KeyRound, LogOut } from 'lucide-react';

import { destroy } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { show as mcpTokenShow } from '@/actions/App/Http/Controllers/Settings/McpTokenController';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * Bottom-of-sidebar account section. The whole row is a dropdown
 * trigger: it opens the user's info with the entry to Settings (MCP
 * token) and the logout action.
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
        <DropdownMenu>
            <DropdownMenuTrigger className="flex w-full items-center gap-2 rounded-lg p-2 text-left transition-colors hover:bg-sidebar-accent data-[state=open]:bg-sidebar-accent">
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

                <ChevronsUpDown className="size-4 shrink-0 text-sidebar-foreground/70" />
            </DropdownMenuTrigger>

            <DropdownMenuContent side="top" align="start" className="w-56">
                <DropdownMenuLabel>
                    <p className="truncate text-sm font-medium">{user.name}</p>
                    <p className="truncate text-xs font-normal text-muted-foreground">
                        {user.email}
                    </p>
                </DropdownMenuLabel>

                <DropdownMenuSeparator />

                <DropdownMenuItem asChild>
                    <Link href={mcpTokenShow()}>
                        <KeyRound />
                        Token MCP
                    </Link>
                </DropdownMenuItem>

                <DropdownMenuSeparator />

                <DropdownMenuItem asChild>
                    <Link href={destroy()} as="button" className="w-full">
                        <LogOut />
                        Cerrar sesión
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
