# Proposal: API Token Authentication (`api-token-auth`)

## Intent

The `posterMobile` Flutter client lives in a separate repository and has no way to authenticate. This
change adds the minimum credentials-to-bearer-token surface it needs, plus a versioned contract
document so the two repos cannot drift silently. It is change 1 of 9; the security boundary it
establishes is inherited by the eight resource changes that follow, so it must be right now.

## Scope

### In Scope

- `POST /api/v1/login` — email + password, mints an ability-scoped Sanctum PAT.
- `GET /api/v1/user` — returns the authenticated owner via `UserResource`.
- `POST /api/v1/logout` — revokes the presenting token only.
- `/api` route registration and bearer guard wiring in `bootstrap/app.php`.
- An ability boundary between `/api/v1` and the existing `/mcp` surface (both directions).
- Hand-authored `openapi/v1.json` plus a test that keeps it honest.
- A web settings surface for revoking the mobile token (see Decision 4).

### Out of Scope

- Any project / issue / board / sprint / label / comment endpoint (changes 2–9).
- Anything Flutter.
- Token expiry (product decision: tokens do not expire).

## Capabilities

### New Capabilities

- `api-auth`: the `/api/v1` bearer-token authentication surface, its ability boundary, its brute-force
  policy, and its JSON error contract.

### Modified Capabilities

- `mcp-server`: "Exactly One Token Exists" (`openspec/specs/mcp-server/spec.md:35-59`) narrows to
  *exactly one **MCP** token* — a mobile token may now coexist. `/mcp` additionally requires the `mcp`
  ability. Existing 401 bearer-challenge behavior (`spec.md:13-33`) is unchanged and cross-referenced,
  not restated.
- `auth` (`openspec/specs/auth/spec.md`): **unchanged.** Web session login is not touched.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | Token name `mobile`, ability `['mobile']`. `/mcp` becomes `['auth:sanctum','abilities:mcp']` (`routes/ai.php:29`); `McpTokenController` mints `['mcp']`. | `PersonalAccessToken::can()` treats `*` as a wildcard (`vendor/laravel/sanctum/src/PersonalAccessToken.php:77-81`), so **existing MCP tokens carrying `['*']` keep working** — no regression. A `['mobile']` token fails `abilities:mcp` → 403. `/mcp` is in-scope surface with its own regression test. Aliases `abilities`/`ability` are **not** framework defaults (`Middleware.php:803-826`) and must be registered in `bootstrap/app.php`. |
| 2 | Every `tokens()` query is name-scoped, **reads as well as writes**. Login: `tokens()->where('name','mobile')->delete()`. Logout: `currentAccessToken()->delete()`. `McpTokenController.php:46` changes to `where('name','mcp')`, **and so does the read at `McpTokenController.php:26`** (`tokens()->latest()->first()`). | Today line 46 is unscoped, so minting either token would kill the other. **Found during `sdd-design` and verified: line 26 is unscoped too.** It is correct today only because exactly one token can exist; once a `mobile` token coexists, a fresh mobile login would make the MCP settings page display the *mobile* token's `created_at`/`last_used_at`. Missing this ships a silently wrong settings page. Consequence: one active mobile token at a time (re-login on a new phone kills the old one) — accepted, and it prevents immortal orphan tokens given no expiry. |
| 3 | Named limiter `RateLimiter::for('api-login')` in `AppServiceProvider::boot()`, applied as `throttle:api-login`. Two composite limits: 5/min by `email\|ip` **and** 10/min by `ip`. Spanish 429 body via `Limit::response()`. | Strictly stricter than the web login, which has only the `email\|ip` limit (`LoginRequest.php:18,101-104`) and no IP-wide cap. `throttle` is an existing alias; a named limiter is reusable by changes 2–9. Counts every attempt, not only failures. |
| 4 | Tokens never expire. Revocation paths shipped: `POST /api/v1/logout`, a fresh login revoking prior `mobile` tokens, **and a web settings surface that revokes the mobile token from the browser**. | **Owner decision (2026-07-30): the web revoke UI is IN SCOPE for this change, not deferred.** Rationale: tokens never expire, so without a browser-reachable revoke path a lost phone can only be handled by re-authenticating from another device or by `artisan tinker` break-glass — an unacceptable operational gap for a credential with no expiry. Consequence: this change gains a browser surface, which reinstates a `tests/Browser` E2E obligation (see E2E Acceptance) and raises the size forecast. **Open for `sdd-design`:** whether this is a sibling page or a unified "access tokens" settings screen listing both the `mcp` and `mobile` tokens. Recommendation: unify, since the two tokens are the same concept and a single screen avoids a second near-duplicate page — but `sdd-design` owns the call, and must account for the regression risk on the existing `settings/mcp-token` page and `tests/Browser/McpTokenFlowTest.php` if it unifies. |
| 5 | `openapi/v1.json` at repo root. Kept in sync by `tests/Feature/ApiContractTest.php`, asserting set equality **both ways** between registered `api/*` routes and documented operations. | JSON, not YAML, so parsing uses `json_decode` and never leans on the transitive `symfony/yaml`. No new dependency. **`openapi/` as a new top-level directory was explicitly APPROVED by the owner on 2026-07-30**, satisfying the CLAUDE.md rule against new base folders. It is a cross-repo contract, not app code, so a root location is discoverable from `posterMobile`. |
| 6 | Prefix `api/v1` via `withRouting(api: ..., apiPrefix: 'api/v1')`. New `App\Http\Resources\UserResource`. | No `/api` precedent exists, so the Laravel Boost default governs. The stock `api` group is only `SubstituteBindings` (`Middleware.php:495-499`) — it does **not** pull in `EnsureFrontendRequestsAreStateful`, so bearer-only mode is preserved. |
| 7 | Error envelope `{"message", "errors"?}`. 401 + `WWW-Authenticate: Bearer` (scoped `$exceptions->render()` for `api/*` only); 403 on missing ability; 422 for validation and bad credentials; 429 with `Retry-After`. Spanish `message` copy reusing `LoginRequest.php:49-52,68`. | `shouldRenderJsonWhen` already covers `api/*` (`bootstrap/app.php:32-34`), but the shape has never been exercised. The `WWW-Authenticate` header matches `/mcp` (`tests/Feature/McpServerTest.php:63`), giving the client one uniform "token is dead" signal. The `api/*` scope keeps `redirectGuestsTo` (line 22) untouched. |

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `bootstrap/app.php` | Modified | `api:` routing + `apiPrefix`, `abilities`/`ability` aliases, `api/*`-scoped 401 render |
| `routes/api.php` | New | login, user, logout |
| `app/Http/Controllers/Api/V1/AuthController.php` | New | three actions |
| `app/Http/Requests/Api/V1/LoginRequest.php` | New | JSON-shaped, Spanish messages |
| `app/Http/Resources/UserResource.php` | New | created via `make:resource` |
| `app/Providers/AppServiceProvider.php` | Modified | `RateLimiter::for('api-login')` |
| `app/Http/Controllers/Settings/McpTokenController.php` | Modified | name-scoped delete (line 46) **and name-scoped read (line 26)**, `['mcp']` ability |
| `app/Enums/TokenName.php` | New | shared token-name constant; matches the existing `app/Enums/` convention (`IssueType`, `HabitType`, `IssuePriority`, `RecurrenceType`). Added by `sdd-design`; not in the original forecast |
| `routes/ai.php` | Modified | `abilities:mcp` |
| `openapi/v1.json` | New | hand-authored contract (root directory approved by owner) |
| `tests/Feature/ApiAuthTest.php`, `ApiContractTest.php` | New | flat files, matching existing convention |
| `routes/web.php` | Modified | revoke route for the mobile token, alongside `settings/mcp-token` (lines 40-41) |
| `resources/js/pages/settings/mcp-token.tsx` | Modified or superseded | existing settings page; `sdd-design` decides unify vs sibling page |
| `app/Http/Controllers/Settings/` | New or modified | revoke action for the mobile token |
| `tests/Browser/` | New, plus regression | browser E2E for the revoke flow; `tests/Browser/McpTokenFlowTest.php` must keep passing |
| `config/auth.php` | Unchanged | Sanctum auto-registers its guard; no entry needed |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Adding `abilities:mcp` breaks the owner's live MCP client | Low | `['*']` is a wildcard; regression test asserts a `['*']` token still reaches `/mcp` |
| **Residual:** a pre-existing `['*']` MCP token can also call `/api/v1` | Med | Bounded — `/api/v1` exposes only `user` + `logout` in this change. Self-heals on next MCP token regeneration. No data migration on an auth table. Rollout note: regenerate the MCP token once. |
| `mcp-server` test "generating a token stores a single pat" breaks once deletes are name-scoped | High | Expected — it is the spec delta. `sdd-spec` must rewrite that requirement and the test. |
| JSON 401 behavior inferred, not proven | Med | Explicit assertions on status, body, and `WWW-Authenticate` in `ApiAuthTest` |
| OpenAPI drifts from routes | Med | `ApiContractTest` fails on drift in either direction |
| `openapi/` new base directory | — | **Resolved** — explicitly approved by the owner on 2026-07-30 |
| Revoke UI regresses the existing MCP token page | Med | If `sdd-design` unifies the two into one screen, `tests/Browser/McpTokenFlowTest.php` asserts Spanish UI strings verbatim and will break on copy changes. Keep it green or update it deliberately as part of the spec delta, never by loosening assertions. |
| Revoke UI pushes the change further past the review budget | High | Forecast rises to ~780 lines. Handled by a third atomic commit; `sdd-tasks` must confirm and the review guard will require a recorded `size:exception` before apply. |

## Rollback Plan

Revert the branch merge. There are **no migrations and no schema changes**, so nothing to un-migrate.
Reverting restores: `routes/api.php` deleted, `bootstrap/app.php` (`api:` entry, aliases, render
closure) to origin, `AppServiceProvider` limiter removed, `openapi/v1.json` deleted,
`McpTokenController.php:25,46,48` back to unscoped — **the read at line 25 as well as the write at
line 46** — and `routes/ai.php:29` back to plain `auth:sanctum`.

- **Web session auth is untouched by design** — `routes/auth.php`, `AuthenticatedSessionController`,
  `app/Http/Requests/Auth/LoginRequest.php`, and `config/auth.php` are not modified, so revert cannot
  affect it.
- **`/mcp` survives revert**: tokens minted during the window carry `['mcp']`; after revert `/mcp` has
  no ability check, so they still authenticate.
- **Mandatory revert step:** delete `personal_access_tokens` rows named `mobile`. After revert `/mcp`
  no longer checks abilities, so an orphan `['mobile']` token would otherwise gain MCP access.

## Dependencies

- None. No new Composer or npm package. `openapi/` directory creation needs user approval.

## Delivery Forecast

Estimated ~780 changed lines against the 400-line review budget → **400-line budget risk: High**. Under
the `single-pr-default` strategy this is one branch with **three atomic commits**:

1. Route surface, guard wiring, ability boundary, three endpoints, `ApiAuthTest`, MCP regression
   (~370 lines).
2. Web revoke settings surface plus its browser E2E (~240 lines).
3. `openapi/v1.json` + `ApiContractTest` (~170 lines).

The forecast rose from ~540 after the owner pulled the revoke UI into scope. Nothing merges to the
production branch until the full suite, including the browser E2E, passes.

## E2E Acceptance

This change requires E2E coverage at **two levels**, because it now spans both a bearer API and a
browser surface. The owner's delivery rule is that nothing merges to the production branch until E2E
passes, so both are merge-blocking.

1. **Feature-level HTTP E2E (`ApiAuthTest`)** — proves the full token cycle: mint → use → revoke →
   dead, plus both cross-surface denials (a `mobile` token is refused at `/mcp`; an existing `['*']`
   token still reaches `/mcp`). A browser test cannot cover this: `tests/Browser` drives real Inertia
   pages and cannot present a bearer header.
2. **Browser E2E (`tests/Browser`)** — required now that the revoke UI is in scope. Proves the owner
   can revoke the mobile token from the browser and that the token is genuinely dead afterwards.
   `tests/Browser/McpTokenFlowTest.php` must stay green; if `sdd-design` unifies the two token screens,
   update it deliberately rather than loosening its assertions.

Note: the earlier revision of this proposal argued a browser test did not apply because the change had
no browser surface. That argument no longer holds — the owner pulled the revoke UI into scope on
2026-07-30.

## Success Criteria

- [ ] `curl -X POST /api/v1/login` with the owner's credentials returns a plain-text token.
- [ ] `GET /api/v1/user` with that bearer token returns the owner; without it, 401 + `WWW-Authenticate: Bearer`.
- [ ] `POST /api/v1/logout` revokes the presenting token, and the very next request fails.
- [ ] A `mobile` token is rejected at `/mcp` with 403; an existing `['*']` token still reaches `/mcp`.
- [ ] Minting a mobile token leaves the MCP token intact, and vice versa.
- [ ] Brute-force: the 6th login attempt within a minute returns 429 with a Spanish message.
- [ ] `openapi/v1.json` documents exactly the three endpoints, enforced by `ApiContractTest`.
- [ ] The owner can revoke the mobile token from a browser settings screen, and the next API request
      with that token fails with 401.
- [ ] Revoking the mobile token from the web leaves the MCP token intact.
- [ ] `tests/Browser/McpTokenFlowTest.php` still passes.
- [ ] `php artisan test` and `npm run build` pass.
