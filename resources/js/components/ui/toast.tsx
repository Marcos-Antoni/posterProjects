import { useEffect, useSyncExternalStore } from 'react';

import { cn } from '@/lib/utils';

/**
 * Marcos OS toast (§5.1: "Hecho · Deshacer"): a small basalt strip with an
 * optional underlined text action. It announces politely (`role="status"`),
 * never steals focus, never plays a sound, and dismisses itself. One toast
 * at a time: a new one replaces the previous (no stacks, no feed).
 *
 *   toast('Hecho', { action: { label: 'Deshacer', onClick: undo } });
 *
 * Mount `<Toaster />` once per layout.
 */
type ToastAction = { label: string; onClick: () => void };

type ToastMessage = {
    id: number;
    message: string;
    action?: ToastAction;
    duration: number;
};

let current: ToastMessage | null = null;
let nextId = 1;
const listeners = new Set<() => void>();

function emit(): void {
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function toast(
    message: string,
    options: { action?: ToastAction; duration?: number } = {},
): void {
    current = {
        id: nextId++,
        message,
        action: options.action,
        duration: options.duration ?? 5000,
    };
    emit();
}

export function dismissToast(): void {
    current = null;
    emit();
}

export function Toaster({ className }: { className?: string }) {
    const active = useSyncExternalStore(
        subscribe,
        () => current,
        () => null,
    );

    useEffect(() => {
        if (active === null) {
            return;
        }

        const timer = window.setTimeout(() => {
            if (current?.id === active.id) {
                dismissToast();
            }
        }, active.duration);

        return () => window.clearTimeout(timer);
    }, [active]);

    return (
        <div
            role="status"
            aria-live="polite"
            className={cn(
                'pointer-events-none fixed inset-x-4 bottom-4 z-[60] flex justify-center sm:inset-x-auto sm:right-6 sm:bottom-6',
                className,
            )}
        >
            {active && (
                <div
                    key={active.id}
                    data-slot="toast"
                    className="pointer-events-auto inline-flex items-center gap-3 rounded-sm bg-foreground px-4 py-2.5 text-sm text-background shadow-elevation-2 duration-200 animate-in fade-in-0"
                >
                    <span>{active.message}</span>
                    {active.action && (
                        <button
                            type="button"
                            className="min-h-6 cursor-pointer font-semibold underline underline-offset-[3px]"
                            onClick={() => {
                                active.action?.onClick();
                                dismissToast();
                            }}
                        >
                            {active.action.label}
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
