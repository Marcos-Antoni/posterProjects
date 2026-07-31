# Tasks: API Token Authentication (`api-token-auth`)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~780 (370 + 240 + 170) |
| 400-line budget risk | High |
| Chained PRs recommended | No |
| Suggested split | Single branch, 3 atomic commits (confirmed, see below) |
| Delivery strategy | single-pr-default |
| Chain strategy | size-exception |

Decision needed before apply: **RESOLVED — see the exception record below**
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

Decision content: forecast (~780) exceeds the 400-line budget under `single-pr-default`. The owner
does not want chained/stacked PRs, so `sdd-apply` MUST require a recorded `size:exception` before
starting, per proposal Risk table. The 3-commit split below is the mitigation, not a substitute for
the exception record.

### `size:exception` — RECORDED

**Granted by the owner on 2026-07-30.** The change proceeds as a single branch at ~780 changed lines
against a 400-line review budget.

Justification accepted: review load is distributed across three atomic commits, each of which leaves
the full suite green on its own and has an independent rollback boundary (see the Work Units table
above). This matches the owner's stated delivery rule — one dedicated branch, commit-level
segmentation of roughly 400 lines, and a single merge to `main` only after E2E passes.

`sdd-apply` is cleared to start. It MUST still honor the commit boundaries; the exception covers the
total size, not a licence to collapse the three commits into one.

### Branch plan (owner decision, 2026-07-30)

`openspec/` does not exist on `main` — the entire spec tree lives only in commit `6a77bc1` on
`docs/openspec-reconstruction`. Therefore, before implementation:

1. Merge `docs/openspec-reconstruction` into `main` (documentation only: 14 files, 1210 insertions,
   zero code).
2. Branch the change from the updated `main`.
3. Commit these planning artifacts on the change branch.

This keeps the change's diff free of the 1210 unrelated documentation lines.

### Suggested Work Units (commits on one branch — not chained PRs)

| Unit | Goal | Commit | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | API surface: login/user/logout, `TokenName`, ability boundary, McpTokenController name-scoping | 1/3 | `php artisan test --compact --filter=ApiAuthTest\|McpServerTest\|McpTokenTest` | `curl -X POST /api/v1/login -d '{"email":...,"password":...}'` against local serve | Revert `routes/api.php`, `AuthController`, API `LoginRequest`, `UserResource`, `bootstrap/app.php` additions, limiter, `routes/ai.php:29`, `McpTokenController.php:26,46,48` — web app + `/mcp` (unability-scoped) intact |
| 2 | Web revoke surface for the mobile token + browser E2E | 2/3 | `php artisan test --compact --filter=MobileTokenTest` | `tests/Browser/MobileTokenRevokeFlowTest.php` (real login → revoke → dead-token HTTP check) | Revert `routes/web.php` mobile-token routes, `MobileTokenController`, `mobile-token.tsx`, sidebar item, its tests — commit 1's API and `McpTokenFlowTest` stay green |
| 3 | `openapi/v1.json` + bidirectional contract test | 3/3 | `php artisan test --compact --filter=ApiContractTest` | N/A — static contract check, no external client | Delete `openapi/v1.json` + `ApiContractTest`; commits 1-2 unaffected |

Sequencing: phases are strictly sequential (commit N's tests require commit N-1's code). Within
Phase 1, tasks 1.1–1.2 may run in parallel (independent files); all other tasks are sequential
(RED before GREEN, GREEN before scoping fixes, fixes before regression).

## Requirement Legend

api-auth: R1 Credentials Exchange · R2 Identity Exposure · R3 Logout Revocation · R4 Single Active
Mobile Token · R5 Ability Boundary · R6 Brute-Force · R7 JSON Error Contract · R8 Web Revocation ·
R9 OpenAPI Contract. mcp-server delta: D1 Exactly One MCP Token (modified) · D2 `mcp` Ability (added).

## Phase 1: API Surface, Ability Boundary & McpTokenController Scoping (Commit 1/3)

- [x] 1.1 RED — create `tests/Feature/ApiAuthTest.php`: mint→use→logout→dead [R1,R2,R3]; single-active-mobile-token on re-login [R4]; 401+`WWW-Authenticate`, 422 bad creds, 429 on 6th attempt [R6,R7]. Fails: no routes yet.
- [x] 1.2 Create `app/Enums/TokenName.php` — backed string enum `Mcp='mcp'`, `Mobile='mobile'` (name + ability source of truth).
- [x] 1.3 GREEN — `bootstrap/app.php`: add `api: routes/api.php`, `apiPrefix: 'api/v1'`; register `abilities`/`ability` middleware aliases (NOT framework defaults) [R5]; add `api/*`-scoped renders for `AuthenticationException` (401 + bearer header) and `MissingAbilityException` (403) [R7], returning `null` for non-`api/*` so `redirectGuestsTo` and `/mcp`'s 401 stay untouched.
- [x] 1.4 GREEN — create `routes/api.php` (login/user/logout), `App\Http\Controllers\Api\V1\AuthController` (`Auth::validate()`+`getLastAttempted()`, never `Auth::attempt()`), `App\Http\Requests\Api\V1\LoginRequest` (Spanish messages, reused copy), `App\Http\Resources\UserResource` (id/name/email only). `user`+`logout` get `['auth:sanctum','abilities:mobile']`; `login` gets `throttle:api-login` only [R1,R2,R3,R5].
- [x] 1.5 GREEN — `AppServiceProvider::boot()`: call `configureRateLimiting()`; `RateLimiter::for('api-login')` with 5/min `email|ip` + 10/min `ip`, Spanish 429 via `Limit::response()` [R6].
- [x] 1.6 Run `php artisan test --compact --filter=ApiAuthTest` — confirm 1.1 green.
- [x] 1.7 RED — extend `tests/Feature/McpServerTest.php`: `['*']` token still 200s at `/mcp`; `mobile`-only token gets 403 at `/mcp`; `mcp`-only token gets 403 at `GET /api/v1/user` [R5,D2]. Second assertion fails: no ability check on `/mcp` yet.
- [x] 1.8 GREEN — `routes/ai.php:29`: add `abilities:mcp` to the existing middleware array (order preserved). Confirm 1.7 green.
- [x] 1.9 Non-negotiable — `McpTokenController::show()` line 26: scope `tokens()->latest()->first()` to `tokens()->where('name', TokenName::Mcp)->latest()->first()` [D1]. Missing this leaks the mobile token's metadata onto the MCP settings page.
- [x] 1.10 `McpTokenController::store()` line 46: scope delete to `tokens()->where('name', TokenName::Mcp)->delete()`; line 48: `createToken(TokenName::Mcp->value, [TokenName::Mcp->value])` [D1].
- [x] 1.11 Non-negotiable, deliberate rewrite — `tests/Feature/McpTokenTest.php:26`: assert count of `mcp`-named tokens is 1, stored name is `mcp`, and a pre-existing `mobile` token survives generate/regenerate unmodified. Never delete or loosen the uniqueness assertion [D1].
- [x] 1.12 Add scenario (`McpTokenTest.php` or `ApiAuthTest.php`): minting `mobile` via `POST /api/v1/login` leaves an existing `mcp` token intact, and vice versa [R4,D1].
- [x] 1.13 Run `php artisan test --compact` — full suite green, browser suite unmodified and unaffected.

## Phase 2: Web Revoke Surface For The Mobile Token (Commit 2/3)

- [ ] 2.1 RED — create `tests/Feature/MobileTokenTest.php`: `show()` returns metadata for an active `mobile` token; `destroy()` name-scoped delete leaves `mcp` intact; next `/api/v1/*` request with the revoked token fails 401 [R8]. Fails: no route/controller yet.
- [ ] 2.2 GREEN — `routes/web.php`: add `settings.mobile-token.show` (GET) + `.destroy` (DELETE) beside lines 40-41.
- [ ] 2.3 GREEN — create `App\Http\Controllers\Settings\MobileTokenController`: `show()` metadata name-scoped to `mobile`, `destroy()` name-scoped delete [R8].
- [ ] 2.4 Run `npm run build` — regenerates Wayfinder actions for the new routes and refreshes `public/build/manifest.json`. Required before writing the page/sidebar (which import the generated actions) and before any test hashes the manifest.
- [ ] 2.5 GREEN — create `resources/js/pages/settings/mobile-token.tsx`: status + empty state, destructive `<Form {...destroy.form()}>` with an explicit confirmation step (no plaintext, no copy button — D-1), Spanish voseo copy matching `mcp-token.tsx` [R8].
- [ ] 2.6 GREEN — `resources/js/components/sidebar/sidebar-user-menu.tsx`: add a `Token móvil` item after line 67, linking the new `show` action.
- [ ] 2.7 Run `npm run build` again (page/sidebar changed) — final manifest before running Inertia-header-dependent tests.
- [ ] 2.8 Confirm 2.1 green; re-run `tests/Feature/McpTokenTest.php` (lines 17, 40, 75 hash the manifest) to confirm the fresh build didn't break them.
- [ ] 2.9 RED — create `tests/Browser/MobileTokenRevokeFlowTest.php`: login → sidebar → `Token móvil` → confirm revoke → empty state; then the token fails over HTTP [R8].
- [ ] 2.10 GREEN — confirm 2.9 passes against 2.2–2.6.
- [ ] 2.11 Regression — run `tests/Browser/McpTokenFlowTest.php` unmodified; it asserts Spanish UI copy verbatim and MUST stay green (D-1).
- [ ] 2.12 Run `php artisan test --compact` — full suite green.

## Phase 3: Versioned OpenAPI Contract (Commit 3/3)

- [ ] 3.1 RED — create `tests/Feature/ApiContractTest.php`: bidirectional set-equality between registered `api/*` routes and `openapi/v1.json` operations, with named diffs each direction; assert every documented op except `POST /api/v1/login` declares `security: [{"bearerAuth": []}]` [R9]. Fails: no `openapi/v1.json` yet.
- [ ] 3.2 GREEN — create `openapi/v1.json` at repo root documenting exactly `POST /api/v1/login`, `GET /api/v1/user`, `POST /api/v1/logout` and the `bearerAuth` security scheme [R9].
- [ ] 3.3 Run `php artisan test --compact` — full suite green (final commit; includes browser suite).

## Phase 4: Rollout & Rollback (operational — no code)

- [ ] 4.1 [Operational, post-merge] Regenerate the MCP token once from `settings/mcp-token` so it carries `['mcp']` instead of `['*']`, closing the residual wildcard-reaches-`/api/v1` gap. Not a code change.
- [ ] 4.2 [Rollback plan, mandatory if reverted] Delete `personal_access_tokens` rows named `mobile`. After a revert, `/mcp` has no ability check again, so an orphan `mobile` token would otherwise gain MCP access. Document in the PR description; not a code change.
