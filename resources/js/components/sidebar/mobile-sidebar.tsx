import { router } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { useEffect, useState } from 'react';

import { SidebarContent } from '@/components/sidebar/sidebar-content';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogOverlay,
    DialogPortal,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

/**
 * Mobile-only (`md:hidden`) sticky header with a hamburger trigger that
 * opens a left-anchored drawer over the SAME navigation as the desktop
 * `Sidebar` (`SidebarContent`). Unlike `DialogContent` in `ui/dialog.tsx`
 * (centered, `zoom-in-95`), this renders a custom side-panel `Content` —
 * see D3 in the T-11 design doc.
 *
 * Closes on overlay tap or Escape (both free from Radix `Dialog`, since
 * `open` is controlled), or on navigating anywhere via `router.on('start')`
 * — see D2 in the design doc for why that beats threading a close callback
 * through every shared nav link.
 */
export function MobileSidebar() {
    const [open, setOpen] = useState(false);

    useEffect(() => router.on('start', () => setOpen(false)), []);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <header className="sticky top-0 z-30 flex items-center gap-2 border-b bg-sidebar px-4 py-2 text-sidebar-foreground md:hidden">
                <DialogTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-lg"
                        aria-label="Abrir navegación"
                    >
                        <Menu />
                    </Button>
                </DialogTrigger>

                <span className="font-heading text-lg font-medium">
                    Jira Clone
                </span>
            </header>

            <DialogPortal>
                <DialogOverlay />
                <DialogPrimitive.Content
                    data-slot="dialog-content"
                    className="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85%] flex-col gap-4 bg-sidebar p-4 text-sidebar-foreground outline-none data-open:animate-in data-open:slide-in-from-left data-closed:animate-out data-closed:slide-out-to-left"
                >
                    <DialogTitle className="sr-only">Navegación</DialogTitle>
                    <SidebarContent />
                </DialogPrimitive.Content>
            </DialogPortal>
        </Dialog>
    );
}
