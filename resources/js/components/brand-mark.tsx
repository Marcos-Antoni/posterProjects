import { cn } from '@/lib/utils';

/**
 * The "Marcos OS" wordmark from the mockups' app nav: a small painted blaze
 * (18×13, `radius-xs`) next to the name in Overpass 700.
 */
export function BrandMark({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-2.5 text-[17px] leading-6 font-bold text-foreground',
                className,
            )}
        >
            <span
                aria-hidden="true"
                className="h-[13px] w-[18px] rounded-xs border-2 border-blaze-ink bg-blaze"
            />
            Marcos OS
        </span>
    );
}
