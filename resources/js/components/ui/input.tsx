import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Marcos OS text input: 44px minimum height, 2px `--input` border (3:1
 * against the surface), `radius-sm`, surface fill, 16px text so mobile
 * browsers never zoom on focus.
 */
function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
    return (
        <input
            type={type}
            data-slot="input"
            className={cn(
                'min-h-11 w-full min-w-0 rounded-sm border-2 border-input bg-surface px-3.5 py-2 text-base text-foreground outline-none file:inline-flex file:border-0 file:bg-transparent file:text-sm file:font-semibold placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive',
                className,
            )}
            {...props}
        />
    );
}

export { Input };
