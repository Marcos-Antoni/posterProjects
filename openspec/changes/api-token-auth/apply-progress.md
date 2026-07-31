# Apply Progress: API Token Authentication (`api-token-auth`)

## Scope of this batch

Phase 1 (Commit 1/3, tasks 1.1–1.13) **and Phase 2 (Commit 2/3, tasks 2.1–2.12)** are complete.
Phase 3 (OpenAPI contract, 3.1–3.3) and Phase 4 (operational, 4.1–4.2) are explicitly out of scope
for this batch and were not started.

**Mode**: Strict TDD (RED → GREEN → REFACTOR, enforced per task).

## Phase 1 Completed Tasks

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

## Phase 1 Files Changed

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

## Phase 1 TDD Cycle Evidence

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

### Phase 1 Test Summary
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

## Phase 1 Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `php -d extension=sockets.so vendor/bin/pest --compact tests/Feature/ApiAuthTest.php tests/Feature/McpServerTest.php tests/Feature/McpTokenTest.php` → `17/17 passed, 59 assertions` |
| Runtime harness command/scenario and exact result | `curl -X POST /api/v1/login -d '{"email":...,"password":...}'` against local serve was the planned runtime harness (per tasks.md Work Units table). Not executed as a manual curl in this batch — equivalent coverage is provided by `ApiAuthTest`'s full HTTP-level mint→use→logout→dead cycle, which exercises the exact same route through the real Laravel HTTP kernel (not mocked), including the 401/422/429 JSON error contract. Manual curl against a running `php artisan serve` was skipped as redundant with this Feature-level HTTP E2E; flagging this explicitly rather than silently claiming it was run. |
| Rollback boundary | Revert: `routes/api.php` (delete file), `bootstrap/app.php` (`api:` entry, `apiPrefix`, `abilities`/`ability` aliases, the two `render()` closures), `AppServiceProvider` (`configureRateLimiting()` + `throttled()` methods and the `boot()` call), `app/Http/Controllers/Api/V1/`, `app/Http/Requests/Api/V1/`, `app/Http/Resources/UserResource.php`, `app/Enums/TokenName.php`, `routes/ai.php:29` (back to plain `auth:sanctum`), `McpTokenController.php:25,46,48` (back to unscoped), `tests/Feature/ApiAuthTest.php` (delete), `tests/Feature/McpServerTest.php` + `tests/Feature/McpTokenTest.php` (revert to pre-batch content). Web session auth (`routes/auth.php`, `AuthenticatedSessionController`, `Auth/LoginRequest`) untouched by this batch, so revert cannot affect it. `/mcp` survives revert per the proposal's rollback plan (tokens minted during this window carry `['mcp']`, which still authenticates once `abilities:mcp` is removed). Mandatory manual step on revert (per proposal): delete `personal_access_tokens` rows named `mobile`. |

## Phase 1 Deviations from Design

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

## Phase 1 Issues Found

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

## Phase 2 Completed Tasks

- [x] 2.1 RED — `tests/Feature/MobileTokenTest.php` created (5 scenarios: guest redirect,
      empty-state read, name-scoped read with a coexisting `mcp` token, name-scoped
      destroy leaving `mcp` intact, and the revoked token dying over `/api/v1/*`). Confirmed
      failing: 4× 404 (no route), 1× wrong-status (destroy silently 404'd, so the token was
      never revoked and the follow-up API call returned 200 instead of the expected 401).
- [x] 2.2 `routes/web.php` — added `settings.mobile-token.show` (GET) and `.destroy` (DELETE)
      beside the existing `settings/mcp-token` routes, inside the same `auth` group.
- [x] 2.3 `App\Http\Controllers\Settings\MobileTokenController` — `show()` read and `destroy()`
      delete both scoped to `where('name', TokenName::Mobile->value)`.
- [x] 2.4 `npm run build` #1 — regenerated Wayfinder actions
      (`resources/js/actions/App/Http/Controllers/Settings/MobileTokenController.ts`) and the
      manifest, ahead of writing the page/sidebar. Confirmed green: `MobileTokenTest` 5/5.
- [x] 2.5 `resources/js/pages/settings/mobile-token.tsx` — status card + empty state; destructive
      revoke behind a Radix `Dialog` confirmation (`DialogTrigger` → `<Form {...destroy.form()}>`
      inside `DialogContent`), matching `ForceDeleteDialog`/`DeleteSprintDialog`'s established
      trigger+confirm convention. No flash prop, no plaintext input, no copy button — the mobile
      token's plaintext never reaches this page (D-1).
- [x] 2.6 `resources/js/components/sidebar/sidebar-user-menu.tsx` — added a `Token móvil`
      `DropdownMenuItem` linking `mobileTokenShow()`, right after the existing `Token MCP` item.
- [x] 2.7 `npm run build` #2 — final manifest after the page/sidebar changes.
- [x] 2.8 Re-ran `MobileTokenTest` + `McpTokenTest` together against the fresh manifest: 11/11
      passed, 41 assertions. The rebuild did not break the manifest-hashing assertions in either
      file.
- [x] 2.9 RED — `tests/Browser/MobileTokenRevokeFlowTest.php` created: real form login → sidebar
      → `Token móvil` → open confirm dialog → confirm → empty state → dead token over HTTP.
      Confirmed failing for the *pre-existing environment defect* (see Phase 2 Issues Found),
      not a routing/logic gap — same blank-page symptom, same first assertion
      (`assertSee('Iniciar sesión')` at `/login`), as the 4 pre-existing Browser failures.
- [x] 2.10 (environment-red, not a code defect) — re-ran against 2.2–2.6 unchanged; fails
      identically to 2.9's initial run. Verified via the saved screenshot (confirmed blank/white).
      Not weakened, not skipped, not deleted, not marked passing.
- [x] 2.11 Regression — ran `tests/Browser/McpTokenFlowTest.php` unmodified (`git diff --stat`
      confirms zero diff). It fails identically to its Phase-1 baseline: same test, same
      `assertSee('Iniciar sesión')` message. No new regression from this batch.
- [x] 2.12 Full suite: `php -d extension=sockets.so vendor/bin/pest --compact` → **435 tests, 429
      passed, 6 failed**. The 6 failures are exactly the 5 pre-existing baseline failures plus the
      new `MobileTokenRevokeFlowTest` (environment-red). Every Feature test written in Phase 2
      passes.

## Phase 2 Files Changed

| File | Action | What Was Done |
|---|---|---|
| `routes/web.php` | Modified | Added `settings.mobile-token.show` (GET) + `.destroy` (DELETE) beside the `mcp-token` routes |
| `app/Http/Controllers/Settings/MobileTokenController.php` | Created | `show()` and `destroy()`, both scoped to `TokenName::Mobile` |
| `resources/js/pages/settings/mobile-token.tsx` | Created | Status card, empty state, destructive revoke behind a confirm dialog — no plaintext, no copy button |
| `resources/js/components/sidebar/sidebar-user-menu.tsx` | Modified | Added the `Token móvil` sidebar item |
| `tests/Feature/MobileTokenTest.php` | Created | 5 scenarios: guest redirect, empty state, name-scoped read, name-scoped destroy (mcp survives), revoked token dies over HTTP |
| `tests/Browser/MobileTokenRevokeFlowTest.php` | Created | Real-login browser E2E: login → sidebar → confirm dialog → revoke → empty state → dead token over HTTP (environment-red here, see Issues Found) |

## Phase 2 TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 2.1 | `tests/Feature/MobileTokenTest.php` | Feature | N/A (new file) | ✅ Written — 5/5 failed for the expected reason (no route/controller) | ✅ Passed (2.3: 5/5, 13 assertions) | ✅ 5 scenarios (guest redirect, empty read, scoped read, scoped destroy, dead-token-over-HTTP) | ➖ None needed |
| 2.2–2.3 | `tests/Feature/MobileTokenTest.php` | Feature | N/A (new route/controller) | (from 2.1) | ✅ Passed | ✅ Covered by 2.1's 5 scenarios | ➖ None needed |
| 2.5–2.6 | `tests/Feature/MobileTokenTest.php`, `tests/Browser/MobileTokenRevokeFlowTest.php` | Feature + Browser | ✅ 5/5 (`MobileTokenTest` re-confirmed green before/after both builds) | ✅ Written (2.9, browser layer) | ✅ Feature layer passed; Browser layer environment-red (not a logic failure — see Issues Found) | ✅ Feature: 5 scenarios; Browser: full flow scripted (login→sidebar→dialog→confirm→empty state→dead token) | ➖ None needed |
| 2.9–2.11 | `tests/Browser/MobileTokenRevokeFlowTest.php`, `tests/Browser/McpTokenFlowTest.php` | Browser | ✅ (McpTokenFlowTest baseline re-confirmed identical, unmodified) | ✅ Written | ⚠️ Environment-red (blank-page defect, pre-existing, not introduced by this batch) | N/A — blocked by environment before any assertion beyond the first | ➖ None needed |

### Phase 2 Test Summary
- **Total tests written**: 6 (5 in `MobileTokenTest`, 1 in `MobileTokenRevokeFlowTest`).
- **Total tests passing**: 5/5 Feature; 0/1 Browser (environment-red, documented, matches the
  exact failure mode of the 4 pre-existing Browser failures — not a code defect).
- **Layers used**: Feature (5), Browser (1, environment-blocked).
- **Approval tests**: None — no refactoring of existing production code in this phase (only new
  files plus additive, non-behavior-changing edits to `routes/web.php` and
  `sidebar-user-menu.tsx`).
- **Pure functions created**: 0 — this phase is routing/controller/React-page wiring; no
  extractable pure logic beyond what already exists in `TokenName`.
- **One real bug found and fixed during TDD**, not a pre-existing one: see Phase 2 Issues Found
  #2 (Sanctum's web-guard fallback swallowing the bearer-token check after `actingAs()`).

## Phase 2 Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `php -d extension=sockets.so vendor/bin/pest --compact --filter=MobileTokenTest` → `5/5 passed, 13 assertions`. `php -d extension=sockets.so vendor/bin/pest --compact --filter="MobileTokenTest\|McpTokenTest"` → `11/11 passed, 41 assertions` (post-rebuild manifest check, task 2.8). |
| Runtime harness command/scenario and exact result | `tests/Browser/MobileTokenRevokeFlowTest.php` is the real-browser runtime harness called for by `tasks.md`'s Work Units table (login → sidebar → revoke → dead-token HTTP check). It is scripted correctly and exercises the full stack, but fails in this sandbox at the very first assertion (`assertSee('Iniciar sesión')`) with an all-white screenshot — the identical, pre-existing headless-browser rendering defect documented in Phase 1 Issues Found #2, not a defect in this batch's code. `tests/Feature/MobileTokenTest.php`'s last scenario provides equivalent HTTP-level coverage of the revoke→401 cycle (real Laravel HTTP kernel, not mocked) as a fallback runtime proof. |
| Rollback boundary | Revert: `routes/web.php` (the two `settings/mobile-token` route lines), `app/Http/Controllers/Settings/MobileTokenController.php` (delete file), `resources/js/pages/settings/mobile-token.tsx` (delete file), `resources/js/components/sidebar/sidebar-user-menu.tsx` (the `Token móvil` item + its import), `tests/Feature/MobileTokenTest.php` (delete), `tests/Browser/MobileTokenRevokeFlowTest.php` (delete). Phase 1's API surface, ability boundary, and `McpTokenController` scoping are untouched by this batch and stay green on revert. `tests/Browser/McpTokenFlowTest.php` is verified byte-for-byte unmodified (`git diff --stat` = empty), so it is unaffected either way. |

## Phase 2 Deviations from Design

None. `routes/web.php`, `MobileTokenController`, `mobile-token.tsx`, and the sidebar item all
match design.md's File Changes table and decision D-1 (sibling page, no plaintext, no copy
button, explicit confirmation step) as written. The confirm-dialog implementation reuses the
codebase's existing `Dialog` + same-label trigger/confirm convention already established by
`ForceDeleteDialog`, `DeleteSprintDialog`, and `DeleteLabelDialog` rather than inventing a new
pattern.

## Phase 2 Issues Found

1. **Environment (same root cause as Phase 1 Issue #2, new manifestation): the new
   `tests/Browser/MobileTokenRevokeFlowTest.php` fails in this sandbox.** Confirmed via the saved
   screenshot (`tests/Browser/Screenshots/a_user_logs_in_through_the_form__opens_the_mobile_token_settings_from_the_sidebar__confirms__and_revokes_the_token.png`)
   that the page is entirely blank/white at `/login` — identical symptom to
   `McpTokenFlowTest`/`SmokeTest`/`HabitFlowTest`. This is the pre-existing headless-browser
   rendering defect, not a routing, controller, or React defect introduced by this batch. Per
   this batch's explicit instructions, the test was written correctly and left as-is (not
   weakened, skipped, deleted, or marked passing) and is reported here as expected-red-by-
   environment. Full suite comparison: 429→435 tests, 424→429 passed, 5→6 failed (the 5
   pre-existing failures unchanged, plus this one new expected failure).
2. **Real bug found and fixed via the TDD cycle (not a pre-existing issue): Sanctum's bearer
   guard silently defers to the `web` session guard after `actingAs()`.** While writing the
   "revoked mobile token fails over HTTP" scenario in `MobileTokenTest.php`, the test initially
   returned `200` instead of the expected `401` even though the token row was confirmed deleted
   from the database. Root cause, traced into `vendor/laravel/sanctum/src/Guard.php:32-38`:
   Sanctum's guard checks `config('sanctum.guard', 'web')` **first**, before ever inspecting the
   bearer token — if any guard in that list (default `['web']`) already has a resolved user, it
   returns that user via a `TransientToken` (which grants every ability) and never reaches the
   token lookup at all. `actingAs($user)` sets the `web` guard's in-memory user directly
   (`Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication::be()` calls
   `guard($driver)->setUser($user)` without touching the session), and that guard instance stays
   cached in the container for the rest of the test method — so the subsequent bearer-token
   request silently authenticated via the leftover web-guard state instead of actually checking
   the (now-deleted) token. Fix: `$this->app['auth']->forgetGuards();` before the post-revocation
   assertion, forcing a fresh `web` guard that correctly resolves to unauthenticated. This is the
   same defensive pattern Phase 1 already used in `ApiAuthTest.php` for a related-but-distinct
   guard-caching artifact (Phase 1 Issues Found #3), documented inline in the test with a comment
   explaining the mechanism. Testing-only artifact — production always serves each request from a
   fresh process, so this can never occur outside the test suite.

## Remaining Tasks

- [ ] Phase 3: 3.1–3.3 (`openapi/v1.json` + `ApiContractTest`) — not started, separate commit.
- [ ] Phase 4: 4.1–4.2 (operational rollout/rollback) — not started, post-merge/no-code.

## Workload / PR Boundary

- Mode: `size:exception` (recorded by the owner 2026-07-30) — single branch, 3 atomic commits.
- Current work unit: Unit 2 of 3 complete (web revoke surface for the mobile token + browser E2E).
  Unit 1 of 3 (API surface, ability boundary, `McpTokenController` scoping) was already committed
  at `7c64640` before this batch started.
- Boundary: starts from Phase 1's committed state (`7c64640` on `feat/api-token-auth`); ends with
  Phase 2's code + tests — routes, `MobileTokenController`, `mobile-token.tsx`, the sidebar item,
  `MobileTokenTest` (5/5 green), and `MobileTokenRevokeFlowTest` (environment-red, documented).
  **Not committed** — left in the working tree per instructions; the orchestrator/owner reviews
  and commits.
- Estimated review budget impact: ~334 changed/added lines (8 + 8 modified in `routes/web.php` and
  `sidebar-user-menu.tsx`, plus 47 + 141 + 80 + 50 new lines across the controller, page, and two
  test files), close to the ~240 forecast in `tasks.md`'s Work Units table, within the
  `size:exception` envelope for the total ~780-line change.

## Status

13/13 Phase 1 tasks complete (committed at `7c64640`). 12/12 Phase 2 tasks complete (uncommitted,
in the working tree). 25/28 total tasks across the three-phase change. Ready for owner review of
Commit 2/3, then Phase 3 apply (`openapi/v1.json` + `ApiContractTest`).
