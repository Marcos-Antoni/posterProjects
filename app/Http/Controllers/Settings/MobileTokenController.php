<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TokenName;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MobileTokenController extends Controller
{
    /**
     * Display the mobile token settings page. Only metadata is sent —
     * the plain-text token is minted directly on the phone via
     * `POST /api/v1/login` and never reaches a browser, so this page
     * has no flash, no plaintext input, and no copy button. It only
     * shows status and offers revocation (design.md decision D-1).
     *
     * Scoped to tokens named `mobile`: an `mcp` token may coexist, and
     * an unscoped `latest()` read would leak its metadata onto this page.
     */
    public function show(Request $request): Response
    {
        $token = $request->user()->tokens()->where('name', TokenName::Mobile->value)->latest()->first();

        return Inertia::render('settings/mobile-token', [
            'token' => $token === null ? null : [
                'created_at' => $token->created_at?->toISOString(),
                'last_used_at' => $token->last_used_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Revoke the active mobile token. Name-scoped delete: a coexisting
     * `mcp` token is never touched. After this, the phone's next
     * `/api/v1/*` request with that token fails 401.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->tokens()->where('name', TokenName::Mobile->value)->delete();

        return redirect()->route('settings.mobile-token.show');
    }
}
