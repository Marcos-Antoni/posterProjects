import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { google } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                // Marcos OS type (design.md D16): Overpass for the interface,
                // Zilla Slab only for objective titles, summits and reviews.
                // Downloaded at build time and self-hosted; Latin-ext for ñ/tildes.
                google('Overpass', {
                    weights: [400, 600, 700],
                    subsets: ['latin', 'latin-ext'],
                    display: 'swap',
                }),
                google('Zilla Slab', {
                    weights: [500, 600],
                    subsets: ['latin', 'latin-ext'],
                    display: 'swap',
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
