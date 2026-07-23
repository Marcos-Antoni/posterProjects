<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // Allowlist, not just a type hint: the cookie is client-controlled
        // and unencrypted (see `bootstrap/app.php`), so any garbage value
        // must collapse to the safe default instead of reaching the front
        // end, where `ThemeToggle` looks it up in a fixed icon map.
        $rawAppearance = $request->cookie('appearance', 'system');

        /** @var 'light'|'dark'|'system' $appearance */
        $appearance = in_array($rawAppearance, ['light', 'dark', 'system'], true)
            ? $rawAppearance
            : 'system';

        // The root Blade view (`app.blade.php`) reads this to set the
        // initial `.dark` class on `<html>` server-side, avoiding FOUC
        // for the `dark` case (the `system` case is resolved client-side
        // by the inline script, since the server can't see the media
        // query).
        View::share('appearance', $appearance);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'appearance' => $appearance,
            // One-shot flash: only ever present on the request right
            // after regenerating the MCP token (see McpTokenController).
            // The closure defers session access so the value is consumed
            // exactly once and never re-serialized into later visits.
            'flash' => [
                'plainMcpToken' => fn (): ?string => $request->session()->get('plainMcpToken'),
            ],
            'sidebarProjects' => $user
                ? $user->projects()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Project $project): array => [
                        'id' => $project->id,
                        'key' => $project->key,
                        'name' => $project->name,
                    ])
                : [],
        ];
    }
}
