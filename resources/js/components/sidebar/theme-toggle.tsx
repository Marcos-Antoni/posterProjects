import { Monitor, Moon, Sun } from 'lucide-react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/types/global';

const APPEARANCE_ICONS: Record<Appearance, typeof Sun> = {
    light: Sun,
    dark: Moon,
    system: Monitor,
};

/**
 * Standalone theme selector for the sidebar footer, mounted next to
 * `SidebarUserMenu`. Selecting an option applies it instantly and persists
 * it via `useAppearance` (cookie + localStorage) — reload-safe. `/login`
 * has no toggle; it just inherits the persisted/system theme.
 */
export function ThemeToggle() {
    const { appearance, updateAppearance } = useAppearance();
    const CurrentIcon = APPEARANCE_ICONS[appearance];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    title="Cambiar tema"
                    className="flex size-7 shrink-0 items-center justify-center rounded-lg text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                >
                    <CurrentIcon className="size-4" />
                    <span className="sr-only">Cambiar tema</span>
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start">
                <DropdownMenuRadioGroup
                    value={appearance}
                    onValueChange={(value) =>
                        updateAppearance(value as Appearance)
                    }
                >
                    <DropdownMenuRadioItem value="light">
                        <Sun />
                        Claro
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="dark">
                        <Moon />
                        Oscuro
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="system">
                        <Monitor />
                        Sistema
                    </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
