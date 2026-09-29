import { XIcon } from 'lucide-react';
import { Dialog as SheetPrimitive } from 'radix-ui';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Marcos OS sheet (§4.4/§4.5/§5.10): a side panel (480px on the web, e.g.
 * the AI negotiation panel) or a bottom sheet on mobile widths with
 * `radius-xl` top corners. Elevation 3 over a 40% basalt overlay; opens in
 * 200ms with opacity + 8px (`--ease-out-soft`), fade only under reduced
 * motion. Built on the same Radix dialog primitive as `Dialog`, so focus is
 * trapped and Esc closes it.
 */
function Sheet(props: React.ComponentProps<typeof SheetPrimitive.Root>) {
    return <SheetPrimitive.Root data-slot="sheet" {...props} />;
}

function SheetTrigger(
    props: React.ComponentProps<typeof SheetPrimitive.Trigger>,
) {
    return <SheetPrimitive.Trigger data-slot="sheet-trigger" {...props} />;
}

function SheetClose(props: React.ComponentProps<typeof SheetPrimitive.Close>) {
    return <SheetPrimitive.Close data-slot="sheet-close" {...props} />;
}

function SheetOverlay({
    className,
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Overlay>) {
    return (
        <SheetPrimitive.Overlay
            data-slot="sheet-overlay"
            className={cn(
                'fixed inset-0 z-50 overlay-basalt duration-200 data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0',
                className,
            )}
            {...props}
        />
    );
}

type SheetSide = 'right' | 'bottom';

function SheetContent({
    className,
    children,
    side = 'right',
    showCloseButton = true,
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Content> & {
    side?: SheetSide;
    showCloseButton?: boolean;
}) {
    return (
        <SheetPrimitive.Portal>
            <SheetOverlay />
            <SheetPrimitive.Content
                data-slot="sheet-content"
                data-side={side}
                className={cn(
                    'fixed z-50 flex flex-col gap-4 border-border bg-popover p-6 text-popover-foreground shadow-elevation-3 ease-(--ease-out-soft) outline-none data-open:animate-in data-open:duration-200 data-open:fade-in-0 data-closed:animate-out data-closed:duration-150 data-closed:fade-out-0',
                    side === 'right' &&
                        'inset-y-0 right-0 h-full w-full max-w-[480px] rounded-l-lg border-l data-open:slide-in-from-right-2',
                    side === 'bottom' &&
                        'inset-x-0 bottom-0 max-h-[90svh] rounded-t-xl border-t pb-[max(1.5rem,env(safe-area-inset-bottom))] data-open:slide-in-from-bottom-2',
                    className,
                )}
                {...props}
            >
                {children}
                {showCloseButton && (
                    <SheetPrimitive.Close
                        className="absolute top-4 right-4 inline-flex size-10 cursor-pointer items-center justify-center rounded-sm text-muted-foreground transition-colors hover:bg-sunken hover:text-foreground"
                        aria-label="Cerrar"
                    >
                        <XIcon className="size-5" strokeWidth={1.75} />
                    </SheetPrimitive.Close>
                )}
            </SheetPrimitive.Content>
        </SheetPrimitive.Portal>
    );
}

function SheetHeader({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="sheet-header"
            className={cn('flex flex-col gap-1 pr-12', className)}
            {...props}
        />
    );
}

function SheetFooter({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="sheet-footer"
            className={cn('mt-auto flex flex-wrap gap-2', className)}
            {...props}
        />
    );
}

function SheetTitle({
    className,
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Title>) {
    return (
        <SheetPrimitive.Title
            data-slot="sheet-title"
            className={cn('text-lg font-bold', className)}
            {...props}
        />
    );
}

function SheetDescription({
    className,
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Description>) {
    return (
        <SheetPrimitive.Description
            data-slot="sheet-description"
            className={cn('text-sm text-muted-foreground', className)}
            {...props}
        />
    );
}

export {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetOverlay,
    SheetTitle,
    SheetTrigger,
};
