<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\IssuesMobileToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    use IssuesMobileToken;

    /**
     * Exchange valid credentials for a `mobile`-ability bearer token. See
     * `IssuesMobileToken::issueMobileToken()` for the revoke-then-mint
     * shape shared with `POST /api/v1/qr-login`.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        return $this->issueMobileToken($request->authenticate());
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
