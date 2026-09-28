<?php

namespace App\Http\Middleware;

use App\Enums\Appearance;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

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

        // An authenticated user's stored preference is the source of truth
        // (it follows them to any browser). Guests fall back to the
        // browser cookie, allowlisted because it is client-controlled and
        // unencrypted (see `bootstrap/app.php`).
        $appearance = $user !== null
            ? $user->appearance
            : Appearance::fromInput($request->cookie('appearance'));

        // Keep the cookie in step with the stored preference, so the guest
        // login screen after a logout renders the same theme with no flash.
        if ($user !== null && $request->cookie('appearance') !== $appearance->value) {
            Cookie::queue(self::appearanceCookie($appearance));
        }

        // The root Blade view (`app.blade.php`) reads this to render
        // `data-appearance` and, for `dark`, the `.dark` class on `<html>`
        // server-side. `system` is resolved by the inline script before
        // any CSS loads, since only the browser knows the OS setting.
        View::share('appearance', $appearance->value);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'appearance' => $appearance->value,
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

    /**
     * The long-lived, unencrypted `appearance` cookie guest pages read.
     */
    public static function appearanceCookie(Appearance $appearance): SymfonyCookie
    {
        return Cookie::make('appearance', $appearance->value, minutes: 60 * 24 * 365, sameSite: 'lax');
    }
}
