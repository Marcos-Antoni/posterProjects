<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') === 'dark']) data-appearance="{{ $appearance ?? 'system' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Anti-FOUC: the server renders `data-appearance` (the user's
        stored preference, or the guest cookie) and the `.dark` class for
        `dark`. Only `system` needs the browser: this resolves it against
        `prefers-color-scheme` before any CSS or JS bundle loads, and keeps
        following OS changes while `system` is selected. Vanilla JS. --}}
        <script id="appearance-script">
            (function () {
                const root = document.documentElement;
                const media = window.matchMedia('(prefers-color-scheme: dark)');

                function apply() {
                    const appearance = root.dataset.appearance || 'system';
                    const isDark = appearance === 'dark' || (appearance === 'system' && media.matches);

                    root.classList.toggle('dark', isDark);
                    root.style.colorScheme = isDark ? 'dark' : 'light';
                }

                apply();
                media.addEventListener('change', apply);
            })();
        </script>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
