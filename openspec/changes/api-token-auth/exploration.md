# Exploration — `api-token-auth`

**Phase:** sdd-explore
**Change:** `api-token-auth` (change 1 of 9 toward a Flutter mobile client in a separate `posterMobile` repo)
**Artifact store:** openspec
**Status:** done — ready for proposal

## Scope

A credentials-based token authentication API:

- Endpoint accepting email + password, returning a Sanctum personal access token.
- Endpoint returning the authenticated user (`/me`-equivalent).
- Logout / token revocation endpoint.
- API route surface and guard wiring for `auth:sanctum` bearer-token mode.
- A versioned OpenAPI contract document — the mobile client lives in a different repository, so the contract needs a single source of truth.

Out of scope: any project / issue / board / sprint / label / comment resources, and anything Flutter.

## Current state

### Auth is entirely session/Inertia-based

- `config/auth.php:40-45` declares only the `web` session guard.
- `routes/auth.php` has `guest`-gated `login` (GET/POST) and `auth`-gated `logout`. No registration route — single-owner app, owner created via an artisan command (`openspec/specs/auth/spec.md:7-8`).
- `AuthenticatedSessionController::store` (`app/Http/Controllers/Auth/AuthenticatedSessionController.php:26-33`) calls `$request->authenticate()` then `session()->regenerate()`.

### A bearer-token surface already exists: `/mcp`

`routes/ai.php:29` — `Mcp::web('/mcp', PosterServer::class)->middleware('auth:sanctum');`

This proves `auth:sanctum` works with **no explicit `sanctum` guard entry** in `config/auth.php`; Sanctum's service provider auto-registers the guard from the package default (no `config/sanctum.php` is published in this repo). Unauthenticated and revoked requests receive a 401 plus a `WWW-Authenticate: Bearer` challenge rather than an HTML redirect (`tests/Feature/McpServerTest.php:56-79`).

### Token minting pattern

`app/Http/Controllers/Settings/McpTokenController.php`:

- `TOKEN_NAME = 'mcp'` (line 17) — a single hardcoded name.
- `store()` (lines 42-53): `$user->tokens()->delete()` at line 46 deletes **all** of the user's tokens regardless of name, then `$user->createToken('mcp')`. This enforces the "Exactly One Token Exists" invariant deliberately specified for MCP (`openspec/specs/mcp-server/spec.md:35-59`).
- No abilities/scopes are passed to `createToken()` anywhere in the app — tokens carry Sanctum's default `['*']`.
- `show()` (lines 24-34) exposes only `created_at` / `last_used_at`; the plain token is a one-shot Inertia session flash, never a persistent prop.
- `app/Models/User.php:32` — `use HasApiTokens, HasFactory, Notifiable;` with no overrides.

### Routing and middleware

`bootstrap/app.php`:

- `withRouting()` (lines 11-15) registers only `web`, `commands`, `health`. There is **no `api:` key and no `routes/api.php`** — the `/api` surface must be registered from scratch.
- The `web` middleware group appends `HandleInertiaRequests` and `AddLinkHeadersForPreloadedAssets` (lines 17-20).
- `redirectGuestsTo(fn () => route('login'))` (line 22) is guard-agnostic, but the stock `Authenticate` middleware only calls `redirectTo()` when `!$request->expectsJson()`, and `withExceptions()->shouldRenderJsonWhen()` (lines 32-34) already treats `api/*` and `expectsJson()` requests as JSON.
- `EnsureFrontendRequestsAreStateful` appears **nowhere** in the codebase (zero matches outside vendor). Sanctum is bearer-token-only here; there is no cookie/SPA-stateful mode to collide with.

### Rate limiting

The only throttling lives inside `app/Http/Requests/Auth/LoginRequest.php` — hand-rolled `RateLimiter::hit` / `tooManyAttempts` / `clear` (lines 62-96), 5 max attempts (line 18), keyed by `email|ip` (lines 101-104).

It is **not** a reusable trait, **not** `throttle:` route middleware, and **not** a named `RateLimiter::for()` limiter (zero `RateLimiter::for(` calls in the codebase). `routes/auth.php` applies no `throttle` middleware.

### Testing conventions

- `tests/Pest.php:17-19` globally applies `RefreshDatabase` to the `Feature` and `Browser` suites — no per-file `uses()` needed.
- `database/factories/UserFactory.php:31` defaults the password to `'password'` (memoized hash).
- Closest bearer-token precedent: `tests/Feature/McpServerTest.php` — `User::factory()->create()`, `$user->createToken('mcp')->plainTextToken`, `$this->postJson($uri, $payload, ['Authorization' => "Bearer {$token}"])`, `assertStatus(401)` plus a `WWW-Authenticate` header assertion.
- Closest E2E precedent: `tests/Browser/McpTokenFlowTest.php` (form login → token generation, asserting Spanish UI strings verbatim).
- Test files are **flat** under `tests/Feature/` — e.g. `McpServerTest.php`, `McpTokenTest.php`, `McpIssueToolsTest.php`. There is no `tests/Feature/Mcp/` subdirectory.

### No OpenAPI, versioning, or Resources precedent

- `composer.json` contains no Scribe / Scramble / l5-swagger / OpenAPI package.
- `app/Http/Resources/` does not exist.
- No `routes/api.php`, no `/api/v1` prefix anywhere.

The Laravel Boost project rule ("default to Eloquent API Resources and API versioning unless existing API routes do not, then follow existing convention") therefore has no escape-clause precedent — the default governs cleanly.

### Language and validation conventions

`LoginRequest::messages()` (lines 46-53) uses Spanish strings ("El correo electrónico es obligatorio.", "Estas credenciales no coinciden con nuestros registros.", "Demasiados intentos de acceso...") with English identifiers, matching `openspec/config.yaml:12`.

Validation errors today are **session-flash shaped** (Inertia `$errors` bag via `ValidationException::withMessages()`). There is no existing precedent for the JSON `{"message", "errors": {...}}` shape a mobile client consumes. That shape is Laravel's default JSON exception rendering and is auto-triggered by `shouldRenderJsonWhen`, but it has never been exercised end-to-end in this app. The spec must define it explicitly as new contract surface.

## Affected areas

| Path | Nature of change |
| --- | --- |
| `bootstrap/app.php` | Add an `api:` routing entry; must not disturb the `web` group, `shouldRenderJsonWhen`, or `redirectGuestsTo` |
| `routes/api.php` | New file — credentials login, `/me`, logout/revoke |
| `config/auth.php` | Decide whether to add an explicit `sanctum` guard entry for clarity (not strictly required) |
| `app/Http/Controllers/Settings/McpTokenController.php` | Reference only — its unscoped `tokens()->delete()` is a collision risk if copied |
| `app/Http/Requests/Auth/LoginRequest.php` | Throttling precedent; the new endpoint needs equivalent protection in a new JSON-shaped FormRequest |
| `app/Models/User.php` | Likely unchanged; may need an ability constant |
| `openspec/specs/mcp-server/spec.md` | Cross-reference rather than restate the bearer-challenge and single-token rules |
| OpenAPI contract file | Location undecided — no existing convention |
| `tests/Feature/` | New flat-file test, following the existing convention (not a subdirectory) |

## Approaches considered

### 1. Separate named and ability-scoped token (recommended)

Mint a distinctly named token (e.g. `mobile`) with explicit abilities, scope revocation to `tokens()->where('name', 'mobile')->delete()`, and enforce ability checks so an MCP token cannot authenticate the mobile API and vice versa.

- **Pros:** no collision with the existing MCP token; establishes a real security boundary between two client integrations at change 1 of 9, before eight more resource surfaces depend on it; forward-compatible with per-resource scopes.
- **Cons:** introduces the app's first use of Sanctum abilities; requires deciding now whether `/mcp` and `/api` are mutually exclusive by ability.
- **Effort:** Medium.

### 2. Generalize the MCP pattern as-is (not recommended)

Treat the new endpoint as a second door onto the same one-token-total invariant.

- **Pros:** minimal new code.
- **Cons:** logging into the mobile app would silently kill the Claude Desktop MCP session, and regenerating the MCP token would silently log out the phone. User-hostile side effect between unrelated integrations.
- **Effort:** Low, but rejected.

### 3. No scoping at all (not recommended)

Add `/api` routes under `auth:sanctum` with a differently named but unscoped token.

- **Pros:** simplest implementation.
- **Cons:** a leaked mobile token grants full MCP tool access, including destructive board/sprint/issue mutations that already exist per `openspec/specs/mcp-server/spec.md`.
- **Effort:** Low, but rejected on security grounds.

## Recommendation

Approach 1, paired with:

- A new JSON-shaped FormRequest for the credentials endpoint (not a reuse of the web `LoginRequest`, whose response shape differs), carrying equivalent or stricter brute-force throttling on the same `RateLimiter` primitive.
- A hand-authored versioned OpenAPI document — no new dependency, given the project rule that dependencies require explicit approval and the complete absence of generator tooling.
- Eloquent API Resources and an `/api/v1` prefix, since no existing `/api` precedent justifies deviating from the project default.

## Risks

| Severity | Risk |
| --- | --- |
| CRITICAL | **Token scope collision.** Without explicit abilities, any token minted by this change authenticates the existing `/mcp` surface and its full mutating tool set, and vice versa. Must be resolved in spec/design, not deferred. |
| CRITICAL | **Brute force on a token-minting endpoint.** A credentials endpoint returning a long-lived bearer token is a higher-value target than the session login. The only throttling precedent is hand-rolled inside `LoginRequest` and is not directly reusable. |
| MEDIUM | **Wholesale token deletion.** `McpTokenController::store()` line 46 deletes all tokens unscoped by name. Copying it verbatim will cross-revoke tokens between integrations. |
| LOW-MEDIUM | **Guest redirect vs JSON 401.** Correct JSON-401 behavior for `/api` is inferred from framework defaults, not yet proven for this route group. Needs an explicit feature test, not an assumption. |
| LOW | **Inaccurate spec citation.** `openspec/specs/mcp-server/spec.md:3` cites a `tests/Feature/Mcp/*` directory that does not exist. |
| INFO | **No OpenAPI tooling.** The proposal must choose hand-authored spec vs a new dependency; a new dependency requires explicit user approval. |

## Verification note

All structural claims in this document were independently re-verified by the orchestrator against the working tree: `routes/ai.php:29`, `McpTokenController.php:17/46/48`, zero `EnsureFrontendRequestsAreStateful` matches, absent `app/Http/Resources/`, absent `tests/Feature/Mcp/`, and `bootstrap/app.php` registering only `web` / `commands` / `health`.
