import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useSyncExternalStore } from 'react';

import type { Appearance } from '@/types/global';

const COOKIE_NAME = 'appearance';
const STORAGE_KEY = 'appearance';
const COOKIE_MAX_AGE_SECONDS = 365 * 24 * 60 * 60;

const VALID_APPEARANCES: readonly Appearance[] = ['light', 'dark', 'system'];

function isAppearance(value: string | null): value is Appearance {
    return VALID_APPEARANCES.includes(value as Appearance);
}

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

/** Allowlisted (same as the server-side cookie check) — garbage in localStorage falls back to null. */
function readPersisted(): Appearance | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const stored = localStorage.getItem(STORAGE_KEY);

    return isAppearance(stored) ? stored : null;
}

/**
 * Module-level store shared by every mounted consumer (desktop sidebar and
 * mobile drawer both render a ThemeToggle). The mobile drawer unmounts its
 * content on close, so per-instance useState would reset to the stale
 * Inertia prop on every reopen and revert the theme.
 */
let currentAppearance: Appearance | null = null;
const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

function setCurrentAppearance(next: Appearance): void {
    currentAppearance = next;
    listeners.forEach((listener) => listener());
}

/**
 * Reads/writes the user's light|dark|system preference. The persisted value
 * (localStorage) wins over the `appearance` Inertia shared prop: the prop
 * only refreshes on server visits, so it goes stale as soon as the user
 * changes theme client-side. The prop seeds the very first read (set
 * server-side by `HandleInertiaRequests` from the same cookie the anti-FOUC
 * inline script reads), so there's no hydration flash. While `system` is
 * selected, listens live for OS/browser `prefers-color-scheme` changes and
 * re-applies the `.dark` class accordingly.
 */
export function useAppearance() {
    const { props } = usePage();

    const appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance ?? readPersisted() ?? props.appearance,
        () => props.appearance,
    );

    const updateAppearance = useCallback((next: Appearance) => {
        setCurrentAppearance(next);
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
