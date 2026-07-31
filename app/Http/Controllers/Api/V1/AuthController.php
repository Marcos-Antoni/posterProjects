<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TokenName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    /**
     * Exchange valid credentials for a `mobile`-ability bearer token.
     * Revokes every previous token named `mobile` first, so at most one
     * exists per owner. A token named `mcp` is never touched here.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();

        $user->tokens()->where('name', TokenName::Mobile->value)->delete();

        $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value]);

        return response()->json(['token' => $token->plainTextToken]);
    }

    /**
     * Return the authenticated owner.
     */
    public function user(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Revoke only the presenting token, leaving every other token (of
     * either name) untouched.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
