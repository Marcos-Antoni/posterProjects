# Design: API Token Authentication (`api-token-auth`)

## Technical Approach

Add a stateless bearer surface at `api/v1` alongside the existing session/Inertia app and the existing
`/mcp` bearer surface, separated by Sanctum **abilities**. Token identity becomes a two-axis pair
(`name`, `abilities`) carried by a single `App\Enums\TokenName` backed enum, so every mint, query, and
revocation is name-scoped and every guarded route is ability-scoped. No new dependency; no migration.

## Architecture Decisions

### D-1 — Web revoke UI: **sibling page**, not a unified screen

**Choice**: a new `settings/mobile-token` page + `MobileTokenController`. `settings/mcp-token` keeps its
route names, component name, heading, copy, and flash key. `tests/Browser/McpTokenFlowTest.php` is
**not touched**.

| Option | Cost | Verdict |
|---|---|---|
| Unify into "Tokens de acceso" | Renames `settings.mcp-token.show/store`, the component asserted at `tests/Feature/McpTokenTest.php:21`, the sidebar item (`resources/js/components/sidebar/sidebar-user-menu.tsx:63-66`), the `flash.plainMcpToken` prop (`app/Http/Middleware/HandleInertiaRequests.php:71-73`), forces a Wayfinder regen, and forces a deliberate rewrite of the only browser E2E guarding token generation (`tests/Browser/McpTokenFlowTest.php:16-52` asserts Spanish copy verbatim) | Rejected |
| Sibling page | +1 route pair, +1 controller, +1 page (~70 lines TSX), +1 sidebar item; `McpTokenController` touched only where Decision 2 already requires it | **Chosen** |

**Rationale**: the two pages are *not* near-duplicates. The MCP page **mints** (generate/regenerate,
one-shot plaintext flash, copy button). The mobile token is minted by `POST /api/v1/login` from the
phone and its plaintext is **never** shown in a browser — so the mobile page only **shows status and
revokes**: no flash, no plaintext input, no copy button. Unifying does not remove lines, it adds them,
and it spends the review budget on the *safe* half of a change whose risk is the ability boundary.
Adding a sidebar item labelled `Token móvil` cannot collide with `->click('Token MCP')` — neither
string contains the other. Merging both into one screen later is a pure front-end refactor with zero
auth surface; the reverse is not. Deferred, not lost.

### D-2 — Other decisions

| # | Choice | Rejected alternative | Rationale |
|---|---|---|---|
| D-2 | `App\Enums\TokenName: string { Mcp='mcp'; Mobile='mobile'; }` supplies both the token name and its ability | String literals in 4 files | Matches `app/Enums/IssueType.php`; a security boundary must not drift on a typo |
| D-3 | `McpTokenController::show()` **line 26** also gets `->where('name', 'mcp')` | Scope only line 46 as the proposal says | `$user->tokens()->latest()->first()` is correct only while one token can exist. Once `mobile` coexists, a fresh mobile login would make the **MCP page display the mobile token's metadata**. Latent defect the proposal missed |
| D-4 | API login uses `Auth::validate()` + `Auth::getLastAttempted()` (`vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php:311-315,864`) | `Auth::attempt()` as in `app/Http/Requests/Auth/LoginRequest.php:64` | `attempt()` logs into the **web session guard**. A bearer endpoint must never mint a session cookie. `validate()` uses the configured provider/hasher and stays stateless |
| D-5 | `POST /api/v1/login` returns `{"token": "..."}` only; the user shape lives solely at `GET /api/v1/user` → `{"data": {...}}` | Return `{"token", "user"}` | Top-level `JsonResource` wraps in `data`, nested does not — returning both shapes would document two user representations. One canonical shape, less hand-authored OpenAPI |
| D-6 | 429 body reuses the Spanish copy at `app/Http/Requests/Auth/LoginRequest.php:92` | New wording | Consistency; `openspec/config.yaml:12` |

## Route and Guard Wiring (`bootstrap/app.php`)

```php
->withRouting(web: ..., api: __DIR__.'/../routes/api.php', apiPrefix: 'api/v1', commands: ..., health: '/up')
->withMiddleware(function (Middleware $middleware): void {
    // lines 17-29 unchanged — web group, redirectGuestsTo (line 22), trustProxies, encryptCookies
    $middleware->alias([                          // NOT framework defaults: Middleware.php:803-826
        'abilities' => CheckAbilities::class,     // Laravel\Sanctum\Http\Middleware
        'ability' => CheckForAnyAbility::class,
    ]);
})
->withExceptions(function (Exceptions $exceptions): void {
    // shouldRenderJsonWhen (lines 32-34) unchanged
    $exceptions->render(fn (AuthenticationException $e, Request $r) => $r->is('api/*')
        ? response()->json(['message' => 'No autenticado.'], 401)->header('WWW-Authenticate', 'Bearer')
        : null);                                  // null ⇒ fall through, web keeps redirectGuestsTo
    $exceptions->render(fn (MissingAbilityException $e, Request $r) => $r->is('api/*')
        ? response()->json(['message' => 'Este token no tiene permiso para usar esta API.'], 403)
        : null);
});
```

Three safety properties: `Middleware::alias()` **merges** with defaults (`Middleware.php:795`), so no
default alias is lost. Returning `null` from a render callback falls through to default handling, so
`redirectGuestsTo` (line 22) and `/mcp`'s existing 401 bearer challenge
(`tests/Feature/McpServerTest.php:61-63`) are untouched. The stock `api` group carries
`EnsureFrontendRequestsAreStateful` only when `statefulApi` is enabled
(`Middleware.php:496`) — it is not, so bearer-only mode holds.

`routes/api.php`: `POST login` → `throttle:api-login` only. `GET user` + `POST logout` →
`['auth:sanctum', 'abilities:mobile']`.
`routes/ai.php:29` → `->middleware(['auth:sanctum', 'abilities:mcp'])` (array order preserved;
`CheckAbilities` is absent from the middleware priority list, so it cannot be hoisted above `auth`).

**Ability regression proof**: `PersonalAccessToken::can()` treats `*` as a wildcard
(`vendor/laravel/sanctum/src/PersonalAccessToken.php:79-80`). RED test in `McpServerTest.php`:
`$user->createToken('mcp')` (no abilities ⇒ `['*']`, exactly the live token's shape) still reaches
`/mcp` with 200 — and a `createToken('mobile', ['mobile'])` token gets 403 there.

## Data Flow

```
MINT                                                REVOKE
phone ──POST api/v1/login──┐         browser ──POST settings/mcp-token──┐
   throttle:api-login      │             (auth session)                 │
   Auth::validate()        │                                           │
   tokens()->where(name,'mobile')->delete()   tokens()->where(name,'mcp')->delete()
   createToken('mobile', ['mobile'])          createToken('mcp', ['mcp'])
   200 {"token": "..."}    │             redirect + flash plainMcpToken │
      'mcp' row survives ──┘                     'mobile' row survives ─┘

phone ──POST api/v1/logout──> currentAccessToken()->delete()   → only that row dies
browser ──DELETE settings/mobile-token──> tokens()->where(name,'mobile')->delete()
   └─> phone's next GET api/v1/user ⇒ 401 + WWW-Authenticate: Bearer;  'mcp' row survives
```

Invariant enforced everywhere: **no query over `$user->tokens()` may omit a `name` filter.**

## File Changes

| File | Action | Description |
|---|---|---|
| `app/Enums/TokenName.php` | Create | `Mcp`/`Mobile`; source of truth for name **and** ability |
| `bootstrap/app.php` | Modify | `api:`+`apiPrefix`, `abilities`/`ability` aliases, two `api/*`-scoped renders |
| `routes/api.php` | Create | login / user / logout |
| `app/Http/Controllers/Api/V1/AuthController.php` | Create | `login`, `user`, `logout` |
| `app/Http/Requests/Api/V1/LoginRequest.php` | Create | JSON-shaped; Spanish messages copied from `Auth/LoginRequest.php:49-52,68`; `Auth::validate()` |
| `app/Http/Resources/UserResource.php` | Create | allowlist `id`, `name`, `email`, `created_at` (ISO). No `email_verified_at`, no timestamps churn |
| `app/Providers/AppServiceProvider.php` | Modify | `configureRateLimiting()` called from `boot()` (line 26 pattern) |
| `routes/ai.php` | Modify | line 29 gains `abilities:mcp` |
| `app/Http/Controllers/Settings/McpTokenController.php` | Modify | name-scope **line 26 and line 46**; `createToken('mcp', ['mcp'])` at line 48 |
| `routes/web.php` | Modify | `settings.mobile-token.show` (GET) + `.destroy` (DELETE) beside lines 40-41 |
| `app/Http/Controllers/Settings/MobileTokenController.php` | Create | `show()` metadata, `destroy()` name-scoped delete |
| `resources/js/pages/settings/mobile-token.tsx` | Create | status + destructive `<Form {...destroy.form()}>`; empty state |
| `resources/js/components/sidebar/sidebar-user-menu.tsx` | Modify | add `Token móvil` item after line 67 |
| `openapi/v1.json` | Create | hand-authored (root dir owner-approved) |
| `tests/Feature/ApiAuthTest.php`, `MobileTokenTest.php`, `ApiContractTest.php` | Create | flat files |
| `tests/Browser/MobileTokenRevokeFlowTest.php` | Create | browser E2E |
| `tests/Feature/McpServerTest.php`, `McpTokenTest.php` | Modify | ability regressions; name-scoped count assertions |
| `tests/Browser/McpTokenFlowTest.php` | **Unchanged** | D-1 preserves every asserted string and path |

## Rate Limiting

`AppServiceProvider::configureRateLimiting()`:

```php
RateLimiter::for('api-login', fn (Request $request): array => [
    Limit::perMinute(5)->by(Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip()))
        ->response($this->throttled(...)),
    Limit::perMinute(10)->by($request->ip())->response($this->throttled(...)),
]);
```

`throttled(Request $r, array $headers)` → `response()->json(['message' => sprintf('Demasiados
intentos de acceso. Por favor, inténtalo de nuevo en %d segundos.', $headers['Retry-After'])], 429,
$headers)`. `Retry-After` and `X-RateLimit-*` come from `ThrottleRequests`. Both limits count every
attempt, not just failures — strictly stricter than the web login, which has no IP-wide cap
(`app/Http/Requests/Auth/LoginRequest.php:18,101-104`).

## JSON Error Contract

| Status | Producer | Body |
|---|---|---|
| 401 | `AuthenticationException` render above | `{"message":"No autenticado."}` + `WWW-Authenticate: Bearer` |
| 403 | `MissingAbilityException` render (extends `AuthorizationException`) | `{"message":"Este token no tiene permiso para usar esta API."}` |
| 422 | `ValidationException` via `shouldRenderJsonWhen` (`bootstrap/app.php:32-34`) — validation **and** bad credentials | `{"message": ..., "errors": {"email": [...]}}` |
| 429 | `Limit::response()` | `{"message": "Demasiados intentos..."}` + `Retry-After` |

## OpenAPI Sync Mechanism (`tests/Feature/ApiContractTest.php`)

Build two `Collection<string>` of canonical `"{METHOD} {uri}"` keys and assert equality **both ways**
with named diffs, so a failure says *which* side drifted:

```php
$registered = collect(Route::getRoutes()->getRoutes())
    ->filter(fn (RoutingRoute $r): bool => str_starts_with($r->uri(), 'api/'))
    ->flatMap(fn (RoutingRoute $r) => collect($r->methods())
        ->reject(fn (string $m): bool => in_array($m, ['HEAD', 'OPTIONS'], true))
        ->map(fn (string $m): string => $m.' '.$r->uri()))
    ->sort()->values();

$documented = collect(json_decode(file_get_contents(base_path('openapi/v1.json')), true,
        flags: JSON_THROW_ON_ERROR)['paths'])
    ->flatMap(fn (array $ops, string $path) => collect($ops)->keys()
        ->map(fn (string $m): string => strtoupper($m).' '.ltrim($path, '/')))
    ->sort()->values();

expect($registered->diff($documented)->all())->toBe([]);   // route with no doc
expect($documented->diff($registered)->all())->toBe([]);   // doc with no route
```

`HEAD`/`OPTIONS` are framework-synthesised, never authored, so they are excluded. `ltrim('/')`
normalises OpenAPI's `/api/v1/login` against the router's `api/v1/login`. A second assertion keeps the
guard honest: every documented operation except `POST /api/v1/login` MUST declare
`security: [{"bearerAuth": []}]` — mirroring the `abilities:mobile` group.

## Testing Strategy (Strict TDD — RED before code, inside the same commit)

| Layer | What | Where |
|---|---|---|
| Feature | mint → use → logout → dead; 401 shape + `WWW-Authenticate`; 422 bad creds; 429 on 6th attempt | `tests/Feature/ApiAuthTest.php` |
| Feature (regression) | `['*']` token still reaches `/mcp`; `mobile` token ⇒ 403 at `/mcp`; `mcp` token ⇒ 403 at `/api/v1/user` | `tests/Feature/McpServerTest.php` |
| Feature (delta) | minting `mobile` leaves `mcp` intact and vice versa; `show()` never reads the other token | `tests/Feature/McpTokenTest.php`, `MobileTokenTest.php` |
| Contract | bidirectional route↔document equality | `tests/Feature/ApiContractTest.php` |
| Browser E2E | login → sidebar → `Token móvil` → revoke → empty state; then the token is dead over HTTP | `tests/Browser/MobileTokenRevokeFlowTest.php` |

## Threat Matrix

| Boundary | Applicability | Reason |
|---|---|---|
| Documentation-like paths | N/A | No file-type classification or execution of repo files |
| Git repository selection | N/A | No VCS invocation |
| Commit state | N/A | No index/worktree manipulation |
| Push state | N/A | No push automation |
| PR commands | N/A | No PR automation |

The routing boundary this change *does* introduce (guard + ability + exception rendering) is covered
by the RED tests above, not by this matrix.

## Commit Boundaries

| # | Scope | ~Lines | Green at end |
|---|---|---|---|
| 1 | `TokenName`, `bootstrap/app.php`, `routes/api.php`, `AuthController`, API `LoginRequest`, `UserResource`, limiter, `routes/ai.php`, `McpTokenController` (26/46/48), `ApiAuthTest`, `McpServerTest` + `McpTokenTest` updates | ~370 | `php artisan test` — browser suite untouched |
| 2 | web routes, `MobileTokenController`, `mobile-token.tsx`, sidebar item, `MobileTokenTest`, browser E2E | ~240 | needs `npm run build` first (Wayfinder actions + the manifest hash used at `McpTokenTest.php:17`) |
| 3 | `openapi/v1.json` + `ApiContractTest` | ~170 | full suite |

Commit 3 last on purpose: the contract test only becomes meaningful once the routes exist, and it then
fails loudly if commit 1's route set ever changes.

## Migration / Rollout

No migration, no schema change. Post-deploy the owner regenerates the MCP token once so it carries
`['mcp']` instead of `['*']`, closing the residual `['*']`-reaches-`/api/v1` gap. Revert is per the
proposal's rollback plan; the mandatory step is deleting `personal_access_tokens` rows named `mobile`.

## Open Questions

- [ ] None blocking. Unifying `settings/mcp-token` and `settings/mobile-token` into one "Tokens de
      acceso" screen is deliberately deferred to a later front-end-only change (D-1).
