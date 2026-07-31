<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Registered `api/*` routes as sorted `"{METHOD} {uri}"` keys. `HEAD`/`OPTIONS`
 * are framework-synthesised (never hand-authored), so they are excluded.
 */
function registeredApiRouteOperations(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/'))
        ->flatMap(fn (RoutingRoute $route): Collection => collect($route->methods())
            ->reject(fn (string $method): bool => in_array($method, ['HEAD', 'OPTIONS'], true))
            ->map(fn (string $method): string => "{$method} {$route->uri()}"))
        ->sort()
        ->values();
}

/**
 * @return array<string, mixed>
 */
function openApiContract(): array
{
    $path = base_path('openapi/v1.json');

    if (! File::exists($path)) {
        throw new RuntimeException("openapi/v1.json does not exist at {$path}.");
    }

    return json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Documented `openapi/v1.json` operations as sorted `"{METHOD} {uri}"` keys,
 * normalised the same way as `registeredApiRouteOperations()` (leading slash
 * stripped so `/api/v1/login` matches the router's `api/v1/login`).
 */
function documentedApiOperations(): Collection
{
    return collect(openApiContract()['paths'] ?? [])
        ->flatMap(fn (array $operations, string $path): Collection => collect($operations)
            ->keys()
            ->map(fn (string $method): string => strtoupper($method).' '.ltrim($path, '/')))
        ->sort()
        ->values();
}

test('every registered api route is documented, and every documented operation has a matching route', function () {
    $registered = registeredApiRouteOperations();
    $documented = documentedApiOperations();

    $undocumented = $registered->diff($documented)->values()->all();
    $orphaned = $documented->diff($registered)->values()->all();

    expect($undocumented)->toBe(
        [],
        'Registered api/* routes missing from openapi/v1.json: '.(implode(', ', $undocumented) ?: 'none'),
    );
    expect($orphaned)->toBe(
        [],
        'openapi/v1.json operations with no matching registered route: '.(implode(', ', $orphaned) ?: 'none'),
    );
});

test('every documented operation except login declares bearer auth security', function () {
    $paths = openApiContract()['paths'] ?? [];

    foreach ($paths as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $key = strtoupper($method).' '.ltrim((string) $path, '/');

            if ($key === 'POST api/v1/login') {
                continue;
            }

            expect($operation['security'] ?? null)->toBe(
                [['bearerAuth' => []]],
                "{$key} must declare 'security: [{\"bearerAuth\": []}]' in openapi/v1.json.",
            );
        }
    }
});
