import { createInertiaApp } from '@inertiajs/react';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        // Token instead of a hardcoded hex so the bar stays visible in
        // both themes (Inertia injects this string as raw CSS `background`).
        color: 'var(--color-primary)',
    },
});
