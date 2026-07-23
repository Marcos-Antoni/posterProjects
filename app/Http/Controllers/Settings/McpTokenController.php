<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class McpTokenController extends Controller
{
    /**
     * The single Sanctum PAT that authenticates MCP clients. The user
     * holds at most one: regenerating deletes any previous token first.
     */
    private const string TOKEN_NAME = 'mcp';

    /**
     * Display the MCP token settings page. Only token metadata is sent —
     * the plain-text token exists exclusively in the one-shot session
     * flash set by `store()`, never in a persistent prop.
     */
    public function show(Request $request): Response
    {
        $token = $request->user()->tokens()->latest()->first();

        return Inertia::render('settings/mcp-token', [
            'token' => $token === null ? null : [
                'created_at' => $token->created_at?->toISOString(),
                'last_used_at' => $token->last_used_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Generate (or regenerate) the MCP token. Delete-then-create keeps
     * the token unique: any previously issued token stops working the
     * moment a new one is created. The plain text is flashed once for
     * the user to copy and is never retrievable again.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->tokens()->delete();

        $token = $user->createToken(self::TOKEN_NAME);

        return redirect()
            ->route('settings.mcp-token.show')
            ->with('plainMcpToken', $token->plainTextToken);
    }
}
