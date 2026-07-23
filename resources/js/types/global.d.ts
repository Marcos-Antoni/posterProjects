import type { Auth } from '@/types/auth';
import type { Project } from '@/types/models';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

/** The subset of `Project` fields the sidebar needs to list a user's projects. */
export type SidebarProject = Pick<Project, 'id' | 'key' | 'name'>;

/** Theme preference: `system` follows the OS/browser `prefers-color-scheme`. */
export type Appearance = 'light' | 'dark' | 'system';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarProjects: SidebarProject[];
            appearance: Appearance;
            /**
             * One-shot session flashes. `plainMcpToken` is only present on
             * the visit right after generating an MCP token — it is never
             * persisted nor retrievable again.
             */
            flash: {
                plainMcpToken: string | null;
            };
            [key: string]: unknown;
        };
    }
}
