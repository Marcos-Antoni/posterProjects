import type { ComponentProps, ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * The 2-minute version chip (§5.3): the primary way into a task. 48px
 * tall, `radius-sm`, 2px `--blaze-ink` border over `--blaze` at 20% on the
 * surface, a mini blaze on the left, basalt text that is always a physical
 * verb ("Abrir `routes/api.php`"). With no 2-minute version yet it reads
 * "Definir 2 minutos" so the gap is an invitation, not an error.
 */
type TwoMinuteChipProps = ComponentProps<'button'> & {
    version?: ReactNode | null;
};

export function TwoMinuteChip({
    version,
    className,
    ...props
}: TwoMinuteChipProps) {
    const hasVersion =
        version !== null && version !== undefined && version !== '';

    return (
        <button
            type="button"
            data-slot="two-minute-chip"
            data-empty={hasVersion ? undefined : true}
            className={cn(
                'flex min-h-12 w-full cursor-pointer items-center gap-3 rounded-sm border-2 border-blaze-ink bg-[color-mix(in_srgb,var(--blaze)_20%,var(--surface))] px-4 py-2.5 text-left text-base font-semibold text-foreground transition-transform duration-100 ease-out active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50 data-empty:border-dashed',
                className,
            )}
            {...props}
        >
            <span className="blaze-mini" aria-hidden="true" />
            {hasVersion ? (
                <span>2 min: {version}</span>
            ) : (
                <span>Definir 2 minutos</span>
            )}
        </button>
    );
}
