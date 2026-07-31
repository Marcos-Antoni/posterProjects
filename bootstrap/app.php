<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));

        $middleware->trustProxies(at: '*');

        // The anti-FOUC inline script in `app.blade.php` reads this cookie
        // via `document.cookie` before any JS bundle loads, so it must stay
        // as plain text instead of Laravel's default encrypted format.
        $middleware->encryptCookies(except: ['appearance']);

        // Not framework defaults (see Middleware::defaultAliases()) — the
        // `/api/v1` and `/mcp` bearer surfaces are separated by Sanctum
        // token abilities, guarded through these aliases. `alias()` merges
        // with the defaults, so nothing already registered is lost.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Scoped to `api/*` only: returning `null` for every other request
        // falls through to default handling, so `redirectGuestsTo` above
        // and `/mcp`'s own bearer challenge stay untouched.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'No autenticado.'], 401)->header('WWW-Authenticate', 'Bearer')
            : null);

        $exceptions->render(fn (MissingAbilityException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Este token no tiene permiso para usar esta API.'], 403)
            : null);
    })->create();
