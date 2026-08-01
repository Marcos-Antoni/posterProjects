<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\TokenName;
use App\Models\User;
use Illuminate\Http\JsonResponse;

trait IssuesMobileToken
{
    /**
     * Exchange an already-resolved user for a `mobile`-ability bearer
     * token. Revokes every previous token named `mobile` first, so at most
     * one exists per owner. A token named `mcp` is never touched here —
     * the delete is `where('name', 'mobile')`-scoped, the same scoping as
     * `MobileTokenController::destroy`.
     *
     * Lifted verbatim from the three lines `AuthController::login` used to
     * own directly, so a token minted via `POST /api/v1/qr-login` is
     * byte-identical to one minted via `POST /api/v1/login` — indistin-
     * guishability by construction, not by assertion (design.md).
     */
    protected function issueMobileToken(User $user): JsonResponse
    {
        $user->tokens()->where('name', TokenName::Mobile->value)->delete();

        $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value]);

        return response()->json(['token' => $token->plainTextToken]);
    }
}
