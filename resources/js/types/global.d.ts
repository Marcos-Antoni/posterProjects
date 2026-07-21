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

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarProjects: SidebarProject[];
            [key: string]: unknown;
        };
    }
}
