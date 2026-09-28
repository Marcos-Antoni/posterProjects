import { cva, type VariantProps } from 'class-variance-authority';
import { Slot } from 'radix-ui';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Marcos OS button (visual/marcos-os-styleguide.html `.btn`): `radius-sm`,
 * 2px border, 600 weight. Hover only changes the background (primary darkens
 * 8% via `color-mix`), pressed moves 1px down and darkens 14%, disabled is
 * 50% opacity. `destructive` is reserved for destructive-confirmation UI
 * (system actions), never for anything about Marco's conduct.
 */
const buttonVariants = cva(
    "group/button inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 rounded-sm border-2 border-transparent font-semibold whitespace-nowrap transition-[background-color,transform] duration-100 ease-out outline-none select-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring active:not-aria-[haspopup]:translate-y-px disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-50 aria-invalid:border-destructive [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 [&_svg]:stroke-[1.75]",
    {
        variants: {
            variant: {
                default:
                    'bg-primary text-primary-foreground hover:bg-[color-mix(in_srgb,var(--primary)_92%,var(--foreground))] active:bg-[color-mix(in_srgb,var(--primary)_86%,var(--foreground))]',
                outline:
                    'border-input bg-transparent text-foreground hover:bg-sunken aria-expanded:bg-sunken',
                secondary:
                    'bg-sunken text-foreground hover:bg-[color-mix(in_srgb,var(--muted)_92%,var(--foreground))]',
                ghost: 'bg-transparent text-foreground hover:bg-sunken aria-expanded:bg-sunken',
                destructive:
                    'bg-destructive text-destructive-foreground hover:bg-[color-mix(in_srgb,var(--destructive)_92%,var(--foreground))]',
                link: 'border-0 text-primary underline underline-offset-[3px] hover:text-foreground',
            },
            size: {
                default: 'min-h-10 px-4 py-2 text-[15px] leading-5',
                xs: 'min-h-7 gap-1 px-2 text-xs [&_svg:not([class*=size-])]:size-3',
                sm: 'min-h-9 px-3 py-1.5 text-sm',
                lg: 'min-h-12 px-5 py-3 text-base',
                icon: 'size-10',
                'icon-xs': 'size-7 [&_svg:not([class*=size-])]:size-3',
                'icon-sm': 'size-9',
                'icon-lg': 'size-12',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

function Button({
    className,
    variant = 'default',
    size = 'default',
    asChild = false,
    ...props
}: React.ComponentProps<'button'> &
    VariantProps<typeof buttonVariants> & {
        asChild?: boolean;
    }) {
    const Comp = asChild ? Slot.Root : 'button';

    return (
        <Comp
            data-slot="button"
            data-variant={variant}
            data-size={size}
            className={cn(buttonVariants({ variant, size, className }))}
            {...props}
        />
    );
}

export { Button, buttonVariants };
