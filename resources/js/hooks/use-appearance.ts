import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

import type { Appearance } from '@/types/global';

const COOKIE_NAME = 'appearance';
const STORAGE_KEY = 'appearance';
const COOKIE_MAX_AGE_SECONDS = 365 * 24 * 60 * 60;

function prefersDark(): boolean {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

/** Toggles the `.dark` class on `<html>` — same rule as the anti-FOUC inline script in `app.blade.php`. */
function applyAppearance(appearance: Appearance): void {
    const isDark =
        appearance === 'dark' || (appearance === 'system' && prefersDark());
    document.documentElement.classList.toggle('dark', isDark);
}

/** Same cookie name/format the server (`HandleInertiaRequests`) and the anti-FOUC inline script read. */
function persistAppearance(appearance: Appearance): void {
    localStorage.setItem(STORAGE_KEY, appearance);
    document.cookie = `${COOKIE_NAME}=${appearance}; path=/; max-age=${COOKIE_MAX_AGE_SECONDS}; SameSite=Lax`;
}

/**
 * Reads/writes the user's light|dark|system preference. The initial value
 * comes from the `appearance` Inertia shared prop (set server-side by
 * `HandleInertiaRequests` from the same cookie the anti-FOUC inline script
 * reads), so there's no hydration flash. While `system` is selected,
 * listens live for OS/browser `prefers-color-scheme` changes and re-applies
 * the `.dark` class accordingly.
 */
export function useAppearance() {
    const { props } = usePage();
    const [appearance, setAppearance] = useState<Appearance>(props.appearance);

    const updateAppearance = useCallback((next: Appearance) => {
        setAppearance(next);
        persistAppearance(next);
        applyAppearance(next);
    }, []);

    useEffect(() => {
        applyAppearance(appearance);

        if (appearance !== 'system') {
            return;
        }

        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        const handleChange = () => applyAppearance('system');

        mediaQuery.addEventListener('change', handleChange);

        return () => mediaQuery.removeEventListener('change', handleChange);
    }, [appearance]);

    return { appearance, updateAppearance } as const;
}
