import type { Auth } from '@/types/auth';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

/** An active objective as navigation lists it (shared prop). */
export type NavigationObjective = { key: string; title: string };

/** Theme preference: `system` follows the OS/browser `prefers-color-scheme`. */
export type Appearance = 'light' | 'dark' | 'system';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            /** Only the owner's ACTIVE objectives, in manual order. */
            navigationObjectives: NavigationObjective[];
            appearance: Appearance;
            /**
             * One-shot session flashes. `plainMcpToken` is only present on
             * the visit right after generating an MCP token — it is never
             * persisted nor retrievable again.
             */
            flash: {
                plainMcpToken: string | null;
                /** Items that became available with the last check. */
                unlocked: { key: string; title: string }[];
            };
            [key: string]: unknown;
        };
    }
}
