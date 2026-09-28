import type { ComponentProps, ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * "La marca" (§5.1), the most carefully built element of the system: a
 * full-width clickable row (56px) with a 28×20 blaze on the left. Empty =
 * 2px `--blaze-ink` outline; checked = `--blaze` fill + ink border +
 * basalt check, painted left to right in 180ms; `missed` (a missed but
 * repairable day) = dashed outline, never red. State is also exposed as
 * `role="checkbox"` + `aria-checked`, so it never relies on color alone.
 */
type CheckItemProps = Omit<ComponentProps<'button'>, 'onChange'> & {
    checked: boolean;
    onCheckedChange?: (checked: boolean) => void;
    missed?: boolean;
    label: ReactNode;
    description?: ReactNode;
};

export function BlazeMark({
    checked = false,
    missed = false,
    className,
}: {
    checked?: boolean;
    missed?: boolean;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn('blaze-mark', className)}
            data-checked={checked || undefined}
            data-gap={missed || undefined}
        >
            <svg
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                strokeWidth={2.5}
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <path d="M3 8.5l3 3 7-7" />
            </svg>
        </span>
    );
}

export function CheckItem({
    checked,
    onCheckedChange,
    missed = false,
    label,
    description,
    className,
    onClick,
    ...props
}: CheckItemProps) {
    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={checked}
            data-slot="check-item"
            className={cn(
                'flex min-h-14 w-full cursor-pointer items-center gap-4 rounded-sm px-3 py-2 text-left text-base text-foreground transition-colors duration-100 ease-out hover:bg-sunken disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            onClick={(event) => {
                onClick?.(event);

                if (!event.defaultPrevented) {
                    onCheckedChange?.(!checked);
                }
            }}
            {...props}
        >
            <BlazeMark missed={missed && !checked} />
            <span className="min-w-0">
                {label}
                {description ? (
                    <span className="block text-sm text-muted-foreground">
                        {description}
                    </span>
                ) : null}
            </span>
        </button>
    );
}
