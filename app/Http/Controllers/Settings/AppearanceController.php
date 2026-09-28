<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Appearance;
use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Requests\Settings\UpdateAppearanceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Apariencia: the single theme selector (claro / oscuro /
 * sistema). The choice is stored on the user, so it follows the owner to
 * any browser; the `appearance` shared prop already carries it, so the page
 * needs no props of its own.
 */
class AppearanceController extends Controller
{
    /**
     * Display the appearance settings page.
     */
    public function show(): Response
    {
        return Inertia::render('settings/appearance');
    }

    /**
     * Store the chosen theme. The browser cookie is refreshed too, so guest
     * pages in this browser (the login screen after logging out) keep the
     * same theme without a flash.
     */
    public function update(UpdateAppearanceRequest $request): RedirectResponse
    {
        $appearance = Appearance::from($request->validated('appearance'));

        $request->user()->update(['appearance' => $appearance]);

        Cookie::queue(HandleInertiaRequests::appearanceCookie($appearance));

        return back();
    }
}
