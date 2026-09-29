import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

import { update } from '@/actions/App/Http/Controllers/Settings/AppearanceController';
import type { Appearance } from '@/types/global';

/**
 * Applies a preference to `<html>`: `data-appearance` (read by the inline
 * anti-flash script in `app.blade.php`, which also keeps following OS
 * changes while `system` is selected) and the `.dark` class. Same rule as
 * that script.
 */
export function applyAppearance(appearance: Appearance): void {
    const root = document.documentElement;
    const isDark =
        appearance === 'dark' ||
        (appearance === 'system' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);

    root.dataset.appearance = appearance;
    root.classList.toggle('dark', isDark);
    root.style.colorScheme = isDark ? 'dark' : 'light';
}

/**
 * Keeps `<html>` in step with the `appearance` shared prop across Inertia
 * visits that never reload the document (e.g. logging in: the guest page
 * used the browser cookie, the next page carries the user's stored
 * preference). Mount once per layout.
 */
export function useSyncAppearance(): void {
    const { props } = usePage();

    useEffect(() => {
        applyAppearance(props.appearance);
    }, [props.appearance]);
}

/**
 * Reads and changes the owner's theme. The stored per-user preference (the
 * `appearance` shared prop) is the source of truth; a change is applied
 * instantly and saved with a PATCH, and reverts if the save fails.
 */
export function useAppearance() {
    const { props } = usePage();
    const [pending, setPending] = useState<Appearance | null>(null);

    const appearance = pending ?? props.appearance;

    const updateAppearance = useCallback(
        (next: Appearance) => {
            const previous = props.appearance;

            setPending(next);
            applyAppearance(next);

            router.patch(
                update.url(),
                { appearance: next },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onError: () => applyAppearance(previous),
                    onFinish: () => setPending(null),
                },
            );
        },
        [props.appearance],
    );

    return { appearance, updateAppearance } as const;
}
