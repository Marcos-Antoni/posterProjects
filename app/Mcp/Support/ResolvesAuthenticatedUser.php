<?php

namespace App\Mcp\Support;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;

trait ResolvesAuthenticatedUser
{
    /**
     * The Sanctum-authenticated user behind the MCP request, narrowed
     * from the Authenticatable contract to the concrete model. The
     * route's auth middleware guarantees a user is present — anything
     * else is answered as an authentication error.
     */
    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
