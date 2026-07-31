<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TokenName;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class McpTokenController extends Controller
{
    /**
     * Display the MCP token settings page. Only token metadata is sent —
     * the plain-text token exists exclusively in the one-shot session
     * flash set by `store()`, never in a persistent prop.
     *
     * Scoped to tokens named `mcp`: a `mobile` token (minted by
     * `POST /api/v1/login`) may now coexist, and an unscoped `latest()`
     * read would leak the mobile token's metadata onto this page.
     */
    public function show(Request $request): Response
    {
        $token = $request->user()->tokens()->where('name', TokenName::Mcp->value)->latest()->first();

        return Inertia::render('settings/mcp-token', [
            'token' => $token === null ? null : [
                'created_at' => $token->created_at?->toISOString(),
                'last_used_at' => $token->last_used_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Generate (or regenerate) the MCP token. Delete-then-create keeps
     * the token unique: any previously issued token named `mcp` stops
     * working the moment a new one is created, and a `mobile` token is
     * never touched. The plain text is flashed once for the user to copy
     * and is never retrievable again.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->tokens()->where('name', TokenName::Mcp->value)->delete();

        $token = $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value]);

        return redirect()
            ->route('settings.mcp-token.show')
            ->with('plainMcpToken', $token->plainTextToken);
    }
}
