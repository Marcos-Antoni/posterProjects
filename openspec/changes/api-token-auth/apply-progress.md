# Apply Progress: API Token Authentication (`api-token-auth`)

## Scope of this batch

Phase 1 only (Commit 1/3): tasks 1.1–1.13. Phases 2 (web revoke UI), 3 (OpenAPI contract), and 4
(operational) are explicitly out of scope for this batch and were not started.

**Mode**: Strict TDD (RED → GREEN → REFACTOR, enforced per task).

## Completed Tasks

- [x] 1.1 RED — `tests/Feature/ApiAuthTest.php` created (5 scenarios). Confirmed failing: 4× 404
      (no routes), 1× fatal error (`TokenName` class not found).
- [x] 1.2 `app/Enums/TokenName.php` — backed string enum `Mcp`/`Mobile`.
- [x] 1.3 `bootstrap/app.php` — `api:`/`apiPrefix` routing, `abilities`/`ability` aliases,
      `api/*`-scoped `AuthenticationException`/`MissingAbilityException` renders.
- [x] 1.4 `routes/api.php`, `App\Http\Controllers\Api\V1\AuthController`,
      `App\Http\Requests\Api\V1\LoginRequest`, `App\Http\Resources\UserResource`.
- [x] 1.5 `AppServiceProvider::configureRateLimiting()` — named limiter `api-login`.
- [x] 1.6 `ApiAuthTest` confirmed GREEN (5/5, 22 assertions).
- [x] 1.7 RED — `tests/Feature/McpServerTest.php` extended with 3 ability-boundary scenarios.
      Confirmed exactly 1 failure (mobile-only token wrongly reaching `/mcp`); the wildcard and
      mcp-only-at-`/api/v1` assertions already passed (pre-existing `abilities:mobile` guard from
      1.4 already covered the second one).
- [x] 1.8 `routes/ai.php:29` — added `abilities:mcp`. Confirmed GREEN (7/7, 11 assertions).
- [x] 1.9 `McpTokenController::show()` — read scoped to `where('name', TokenName::Mcp->value)`.
- [x] 1.10 `McpTokenController::store()` — delete and create both scoped to `TokenName::Mcp`.
- [x] 1.11 `tests/Feature/McpTokenTest.php:26` rewritten — asserts count of `mcp`-named tokens is
      1, stored name is `mcp`, and a pre-existing `mobile` token survives untouched (checked by
      re-fetching the token row by id and asserting name + abilities). Uniqueness assertion not
      loosened.
- [x] 1.12 Two scenarios added covering both directions: `ApiAuthTest` ("a fresh login retires
      the previous mobile token, not the mcp token") for mobile-mint-preserves-mcp, and
      `McpTokenTest` (new test, see below) for mcp-generate-preserves-mobile.
- [x] 1.13 Full suite run — 429 tests, 424 passed, 5 pre-existing failures (unchanged from
      baseline, see Issues Found). Browser suite unaffected.

## Files Changed

| File | Action | What Was Done |
|---|---|---|
| `app/Enums/TokenName.php` | Created | Backed string enum `Mcp='mcp'`, `Mobile='mobile'` |
| `bootstrap/app.php` | Modified | `api:`/`apiPrefix` routing; `abilities`/`ability` aliases; `api/*`-scoped 401/403 renders (Spanish) |
| `routes/api.php` | Created | `POST login` (throttled only), `GET user` + `POST logout` (`auth:sanctum`,`abilities:mobile`) |
| `app/Http/Controllers/Api/V1/AuthController.php` | Created | `login`, `user`, `logout` |
| `app/Http/Requests/Api/V1/LoginRequest.php` | Created | `Auth::validate()` + `Auth::getLastAttempted()`, Spanish messages |
| `app/Http/Resources/UserResource.php` | Created | Allowlist `id`, `name`, `email` only |
| `app/Providers/AppServiceProvider.php` | Modified | `configureRateLimiting()`: named limiter `api-login`, 5/min `email\|ip` + 10/min `ip`, Spanish 429 |
| `routes/ai.php` | Modified | `/mcp` middleware gains `abilities:mcp` |
| `app/Http/Controllers/Settings/McpTokenController.php` | Modified | `show()` read and `store()` delete/create both scoped to `TokenName::Mcp` |
| `tests/Feature/ApiAuthTest.php` | Created | 5 scenarios: mint→use→logout→dead, single-active-mobile-token, wrong password, no-token challenge, 6th-attempt throttle |
| `tests/Feature/McpServerTest.php` | Modified | +3 scenarios: wildcard still works, mobile-only refused at `/mcp`, mcp-only refused at `/api/v1/user` |
| `tests/Feature/McpTokenTest.php` | Modified | Rewrote the uniqueness test (name-scoped + mobile-survives); added a dedicated read-scope regression test |

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 1.1 | `tests/Feature/ApiAuthTest.php` | Feature | N/A (new file) | ✅ Written | ✅ Passed (1.6) | ✅ 5 scenarios | ➖ None needed |
| 1.2 | (used by 1.1's tests) | — | N/A (new file) | ➖ Structural (backed enum, no branching) | ✅ Passed | ➖ Skipped — pure constant definition, single possible output | ➖ None needed |
| 1.3–1.5 | `tests/Feature/ApiAuthTest.php` | Feature | N/A (new files/routes) | (from 1.1) | ✅ Passed (1.6: 5/5, 22 assertions) | ✅ Covered by 1.1's 5 scenarios | ➖ None needed |
| 1.7 | `tests/Feature/McpServerTest.php` | Feature | ✅ 4/4 (pre-existing tests green before edit) | ✅ Written — confirmed exactly 1 genuine failure (mobile-only-at-`/mcp`) | ✅ Passed (1.8: 7/7, 11 assertions) | ✅ 3 scenarios (wildcard / mobile-only / mcp-only) | ➖ None needed |
| 1.9–1.10 | `tests/Feature/McpTokenTest.php` | Feature | ✅ 4/4 (pre-existing tests re-confirmed green before edit) | ✅ Written — see note below | ✅ Passed (6/6, 28 assertions) | ✅ 2 scenarios (write-scope in the rewritten test, read-scope in the new dedicated test) | ➖ None needed |
| 1.11 | `tests/Feature/McpTokenTest.php` | Feature | (same as 1.9–1.10) | ✅ Written | ✅ Passed | ✅ Included above | ➖ None needed |
| 1.12 | `ApiAuthTest` + `McpTokenTest` | Feature | (same as above) | ✅ Written | ✅ Passed | ✅ Both directions covered | ➖ None needed |

**Note on 1.9–1.10–1.11 RED discipline**: the task list orders the production change (1.9, 1.10)
before the test rewrite (1.11), which risked writing production code before its test — the one
strict-TDD rule that cannot be broken. To close that gap honestly: after implementing the scoped
controller and rewriting the tests, I used `git stash` to temporarily revert
`McpTokenController.php` to its original unscoped form, re-ran the rewritten/new tests against it,
and confirmed **two independent genuine RED failures**:
1. Write-scope: `Expecting null not to be null` — the coexisting `mobile` token was deleted by the
   unscoped `tokens()->delete()`.
2. Read-scope: a dedicated new test (`the mcp token page reports the mcp token even when a mobile
   token is minted more recently`, using `$this->travel(1)->minute()` to unambiguously order the
   two tokens) failed with the unscoped `show()` returning the *mobile* token's `created_at`
   instead of the *mcp* token's — reproducing exactly the defect described in the non-negotiable
   for task 1.9.

I then restored the scoped implementation via `git stash pop` and re-confirmed both tests GREEN
(6/6, 28 assertions). This is the actual mechanism, not just code review, that proves task 1.9's
non-negotiable (scoping the *read*, not just the write) is real and necessary — the original test
suite would have stayed green even with only the write scoped, because no test exercised a
mobile token minted *after* the mcp token.

### Test Summary
- **Total tests written/modified**: 9 new tests (5 in `ApiAuthTest`, 3 in `McpServerTest`, 1 new
  dedicated test in `McpTokenTest`), plus 1 rewritten test in `McpTokenTest`.
- **Total tests passing**: 429/434 project-wide (424 project tests + this batch); 5 failures are
  pre-existing and unrelated (see Issues Found).
- **Layers used**: Feature (9 new + 1 rewritten). No Unit or Browser layer needed for this phase.
- **Approval tests** (refactoring `McpTokenController`): the pre-existing `McpTokenTest` suite
  (4 tests) served as the approval/safety-net baseline, re-confirmed green before and after the
  scoping edit.
- **Pure functions created**: 0 — this phase is routing/middleware/controller wiring by nature;
  `TokenName` is a pure value enum with no logic to extract further.

## Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `php -d extension=sockets.so vendor/bin/pest --compact tests/Feature/ApiAuthTest.php tests/Feature/McpServerTest.php tests/Feature/McpTokenTest.php` → `17/17 passed, 59 assertions` |
| Runtime harness command/scenario and exact result | `curl -X POST /api/v1/login -d '{"email":...,"password":...}'` against local serve was the planned runtime harness (per tasks.md Work Units table). Not executed as a manual curl in this batch — equivalent coverage is provided by `ApiAuthTest`'s full HTTP-level mint→use→logout→dead cycle, which exercises the exact same route through the real Laravel HTTP kernel (not mocked), including the 401/422/429 JSON error contract. Manual curl against a running `php artisan serve` was skipped as redundant with this Feature-level HTTP E2E; flagging this explicitly rather than silently claiming it was run. |
| Rollback boundary | Revert: `routes/api.php` (delete file), `bootstrap/app.php` (`api:` entry, `apiPrefix`, `abilities`/`ability` aliases, the two `render()` closures), `AppServiceProvider` (`configureRateLimiting()` + `throttled()` methods and the `boot()` call), `app/Http/Controllers/Api/V1/`, `app/Http/Requests/Api/V1/`, `app/Http/Resources/UserResource.php`, `app/Enums/TokenName.php`, `routes/ai.php:29` (back to plain `auth:sanctum`), `McpTokenController.php:25,46,48` (back to unscoped), `tests/Feature/ApiAuthTest.php` (delete), `tests/Feature/McpServerTest.php` + `tests/Feature/McpTokenTest.php` (revert to pre-batch content). Web session auth (`routes/auth.php`, `AuthenticatedSessionController`, `Auth/LoginRequest`) untouched by this batch, so revert cannot affect it. `/mcp` survives revert per the proposal's rollback plan (tokens minted during this window carry `['mcp']`, which still authenticates once `abilities:mcp` is removed). Mandatory manual step on revert (per proposal): delete `personal_access_tokens` rows named `mobile`. |

## Deviations from Design

1. **`UserResource` field set**: design.md's File Changes table lists the allowlist as "`id`,
   `name`, `email`, `created_at` (ISO)". This conflicts with `specs/api-auth/spec.md`'s
   Requirement "Authenticated Identity Exposure", which explicitly pins the response to
   **exactly** `id`, `name`, `email` and explicitly excludes "timestamps", and with `tasks.md`
   1.4's own wording ("`UserResource` (id/name/email only)"). I implemented exactly `id`, `name`,
   `email` — no `created_at` — following the spec's explicit, testable acceptance criterion and
   the task list's own wording over the design table's cell, which reads as a drafting
   inconsistency (likely a stale mention from an earlier design iteration). Flagging this rather
   than silently picking one.
2. **401/403 JSON message language**: `specs/api-auth/spec.md`'s "JSON Error Response Contract"
   table shows illustrative English strings (`"Unauthenticated."`, `"Invalid ability provided."`)
   for 401/403, while only the 429 row is marked explicitly Spanish. `design.md`'s "Route and
   Guard Wiring" code block and its own JSON Error Contract table both specify literal Spanish
   strings (`"No autenticado."`, `"Este token no tiene permiso para usar esta API."`), matching
   proposal Decision 7 ("Spanish `message` copy reusing `LoginRequest.php:49-52,68`") and this
   session's explicit language contract (user-facing copy in Spanish). I implemented the Spanish
   strings from design.md, treating the spec table's English text as illustrative shape, not
   literal required copy — no scenario in the spec pins the literal 401/403 string, only the 429
   scenario and the status codes/headers. No test asserts a specific English string, so nothing
   in the acceptance criteria was broken by this choice.

No other deviations. `bootstrap/app.php`, `routes/api.php`, `AuthController`, `LoginRequest`,
`AppServiceProvider`, and the `McpTokenController`/`routes/ai.php` scoping all match design.md's
architecture decisions (D-1 through D-6, D-2's route/guard wiring) as written.

## Issues Found

1. **Environment: PHP `sockets` extension not loaded by default, breaks `php artisan test`
   entirely (not just Browser tests).** This machine's PHP 8.5.8 build ships `sockets` as a shared
   module (`/usr/lib/php/modules/sockets.so`) but it is not enabled in `/etc/php/php.ini` or any
   `conf.d/*.ini`. The Pest browser plugin's `ServerManager` calls
   `Pest\Browser\Support\Port::find()` → `socket_create_listen()` **unconditionally at Kernel
   shutdown**, even for a pure `tests/Feature/*` run with zero Browser tests selected. Without the
   extension, `php artisan test` (and plain `vendor/bin/pest`) fatal-error on shutdown before
   printing any result — the entire suite appears to produce zero output. I did not modify
   `php.ini` (a system-wide, out-of-scope change). Instead, every test invocation in this batch
   used `php -d extension=sockets.so vendor/bin/pest --compact ...`, which loads the extension for
   that process only. This is a pre-existing environment gap, not something introduced by or
   fixable within this change — flagging it for the owner since `php artisan test` as literally
   written in the task's "Definition of done" does not work as-is on this machine without the
   flag.
2. **5 pre-existing test failures, confirmed present in the baseline before any Phase 1 edit, and
   unchanged (same tests, same messages) after it:**
   - `Feature\AppearanceTest` — "the anti-fouc inline script is rendered in the head before the
     vite asset tags" — `mb_strpos($html, '/build/assets/')` returns `false`; unrelated to auth,
     not touched by this batch.
   - `Browser\HabitFlowTest` — 2 tests — page text not found / timeout.
   - `Browser\McpTokenFlowTest` — page renders as a **blank white screenshot** (confirmed by
     reading the saved screenshot); the headless browser never sees any content at `/login`.
   - `Browser\SmokeTest` — same blank-page symptom at `/login`.
   These 4 Browser failures all share the same shape (blank page, "text not found" or timeout),
   consistent with a browser/environment-level issue (e.g., the headless browser process unable
   to fully render/connect in this sandbox) rather than an application defect — `public/build/
   manifest.json` exists and is fresh, so it is not a missing-build issue. Per strict-TDD's safety
   net rule, these are reported, not fixed. I did not touch `AppearanceTest.php` or any Browser
   test file. **`tests/Browser/McpTokenFlowTest.php` was explicitly required to "stay green" by
   this batch's instructions — it was already red at baseline, before I touched anything, for a
   reason unrelated to token scoping (blank page render, not a Spanish-copy or route assertion
   failure).** Full baseline vs. after-batch comparison: 420→429 tests, 415→424 passed, 5→5 failed
   (identical failure set both times).
3. **Sanctum guard caching within a single Pest test method.** `Illuminate\Auth\RequestGuard`
   (which Sanctum's bearer guard uses) caches the resolved user for the lifetime of the guard
   instance, and `AuthManager` caches guard instances per name. Since Laravel's HTTP test helpers
   reuse the same application container across multiple simulated requests within one test method
   (only rebuilt between test methods), a test that authenticates with a bearer token, then
   revokes it, then makes another authenticated call with the same token in the *same* test method
   will see a false-positive success instead of the expected 401 — the guard never re-runs its
   resolution callback. This is a testing-only artifact (production serves every request from a
   fresh process). Fix used: `$this->app['auth']->forgetGuards();` before the post-revocation
   assertion in `ApiAuthTest`. This exact pattern already exists in the codebase
   (`tests/Feature/McpEndToEndTest.php:197`, with an identical comment), so this is not a new
   pattern — just applied consistently to the new bearer-token cycle.

## Remaining Tasks

- [ ] Phase 2: 2.1–2.12 (web revoke surface for the mobile token) — not started, separate commit.
- [ ] Phase 3: 3.1–3.3 (`openapi/v1.json` + `ApiContractTest`) — not started, separate commit.
- [ ] Phase 4: 4.1–4.2 (operational rollout/rollback) — not started, post-merge/no-code.

## Workload / PR Boundary

- Mode: `size:exception` (recorded by the owner 2026-07-30) — single branch, 3 atomic commits.
- Current work unit: Unit 1 of 3 (API surface, ability boundary, `McpTokenController` scoping).
- Boundary: starts from `main` (via `feat/api-token-auth`, already branched) with only the
  planning artifacts committed; ends with Phase 1's code + tests, full suite green modulo the 5
  pre-existing, unrelated failures documented above. **Not committed** — left in the working tree
  per instructions; the owner reviews before `git commit`.
- Estimated review budget impact: ~408 changed lines (122 modified + 286 new, by `git diff --stat`
  plus new-file line counts), close to the ~370 forecast in `tasks.md`'s Work Units table, within
  the `size:exception` envelope for the total ~780-line change.

## Status

13/13 Phase 1 tasks complete. Ready for owner review of Commit 1/3 (uncommitted), then Phase 2
apply.
