# Verify Report — api-qr-login

**Change**: api-qr-login
**Branch**: feat/qr-login @ d8bb8f6 (planning: eeb790f, 6decd1c)
**Mode**: Full artifacts (proposal, specs x2, design, tasks, apply-progress) — full verification performed.
**Verdict**: PASS

## Test Evidence

`php artisan test --compact` → `{"tool":"pest","result":"passed","tests":506,"passed":506,"assertions":1671,"duration_ms":33568}`. Corroborates the `apply-progress.md` claim of 506/506. Zero failures.

## Task Completion

`tasks.md`: 47 checklist lines, all `[x]`, zero `[ ]`. No unchecked tasks — consistent with `apply-progress.md`'s claim.

## 12-Point Property Verification

### 1. Atomic conditional UPDATE for single-use — CONFIRMED
`app/Http/Controllers/Api/V1/QrLoginController.php:43-52`. `DB::table('qr_login_passes')->where('token_hash', $hash)->whereNull('consumed_at')->where('expires_at', '>', now())->update([...])`, single statement, no preceding read of the row. Branches on `$affected !== 1` (line 55). The `sole()` read at line 61 happens strictly *after* the winning UPDATE, only to fetch `user_id` for token issuance — verified this cannot be exploited since the row is already claimed by that point.

### 2. `DB::listen` shape test — CONFIRMED
`tests/Feature/ApiQrLoginTest.php:52-65`. Asserts `$statements[0]` starts with `'update'` (line 63, which by construction proves zero preceding `select` — any select touching the table would itself be `statements[0]`) and that exactly one `update`-prefixed statement was issued (line 64). Matches the claimed shape exactly, not a weaker assertion.

### 3. Reasoning lives in the controller docblock — CONFIRMED
`app/Http/Controllers/Api/V1/QrLoginController.php:15-38`. Explicit "DO NOT refactor this into SELECT → check → UPDATE" warning, explains the race (two phones, second issuance kills the first's session), explains why `$affected` is the DB engine's row-lock result and not application logic, and names the `DB::listen` test by file as the regression guard. This is durable, in-repo documentation, not only spec text.

### 4. sha256, never bcrypt/argon2/Hash::make — CONFIRMED
`rg` for `sha256|bcrypt|Hash::make|argon` across the pass path found `hash('sha256', ...)` at three sites only: `QrLoginController.php:43`, `MobileTokenQrController.php:47`, `QrLoginPassFactory.php:24` (test data). No salted-hash call anywhere in the pass path. `token_hash` column is `string(64)` (migration), matching a sha256 hex digest.

### 5. Plaintext pass never persisted — CONFIRMED
Migration (`database/migrations/2026_08_01_124440_create_qr_login_passes_table.php`) defines only `token_hash` (64-char), no plaintext column. `QrLoginPass` model's `#[Fillable]` list (`app/Models/QrLoginPass.php:26`) has no plaintext field. Both write paths (`MobileTokenQrController.php:47`, migration seed via factory) write only the hash. `rg "QrLoginPass::"` outside tests found only the relation declaration and the post-consumption `sole()` read — no third write path exists. Plaintext leaves the mint response body once (`MobileTokenQrController.php:52-58`) and is never re-served (`status()` never returns `payload`, verified by `tests/Feature/Settings/MobileTokenQrTest.php`'s "status never returns the payload" test covering all 4 states).

### 6. Uniform failure asserted byte-identically across 4 cases — CONFIRMED
`tests/Feature/ApiQrLoginTest.php:117-138`. A single `->with([...])` dataset (unknown/expired/consumed/malformed) drives one test body that calls `$response->assertExactJson([...])` with the identical literal payload for all four cases — not four loosely-asserted separate tests.

### 7. Re-mint does not destroy a consumed pass — CONFIRMED
`app/Http/Controllers/Settings/MobileTokenQrController.php:24-51`: `lockForUpdate()` on the latest row (line 30), early-return without any write when `consumed_at !== null` (lines 32-38), delete scoped to `whereNull('consumed_at')` only (line 40) — consumed rows are never touched by the delete. Dedicated, standalone test: `tests/Feature/Settings/MobileTokenQrTest.php` — "minting over an already-consumed pass writes nothing and reports the consumed state" (not merged into the "minting a second pass invalidates the first unconsumed pass" test, which covers the separate live-pass-replacement case).

### 8. QR token indistinguishable from credentials token; mcp-scoped revocation — CONFIRMED
`app/Http/Controllers/Api/V1/Concerns/IssuesMobileToken.php` is the single shared code path used by both `AuthController::login` (`AuthController.php:20`, `use IssuesMobileToken`) and `QrLoginController::redeem` (`QrLoginController.php:14`, `use IssuesMobileToken`, called at line 64). Revocation is `$user->tokens()->where('name', TokenName::Mobile->value)->delete()` (`IssuesMobileToken.php:22`) — `mcp`-named tokens are never touched. Confirmed by test `ApiQrLoginTest.php:159-176` ("a QR redemption retires the previous mobile token, not the mcp token"), which asserts the mcp token count stays 1 and the old mobile token 401s.

### 9. Redemption rate limiting exists, registered, applied, keyed correctly — CONFIRMED
Registered: `app/Providers/AppServiceProvider.php:83-85`, `RateLimiter::for('api-qr-redeem', ... Limit::perMinute(10)->by($request->ip()))`. Applied: `routes/api.php:20-22`, `->middleware('throttle:api-qr-redeem')` on the `qr-login` POST route. Keyed on **IP alone**, not on the presented token — confirmed correct per the trap in the prompt (keying on the token would give each guess its own fresh bucket). Verified by test `ApiQrLoginTest.php:178-187` (11th attempt in a minute → 429 with `Retry-After` header). A second limiter, `qr-login-mint` (20/min, keyed by user id with IP fallback), is separately registered and applied to the mint route (`routes/web.php:58-59`) — not required by the checklist item but present and correctly scoped.

### 10. ApiContractTest bearerAuth exemption is a literal 2-element list; OpenAPI matches exactly 11 routes — CONFIRMED
`tests/Feature/ApiContractTest.php:76`: `$unauthenticatedTokenIssuers = ['POST api/v1/login', 'POST api/v1/qr-login'];` — a literal array, not a predicate/regex; comment at line 74-75 explicitly states "a third unsecured endpoint still fails this assertion." Independently ran `php artisan route:list --path=api --json` and `openapi/v1.json`'s `paths` keys through a diff script: both produce the identical sorted 11-operation set (`GET api/v1/projects`, `GET api/v1/projects/{project}`, `GET .../board-columns`, `GET .../issues`, `GET .../issues/{issue}`, `GET .../labels`, `GET .../sprints`, `GET api/v1/user`, `POST api/v1/login`, `POST api/v1/logout`, `POST api/v1/qr-login`). Zero undocumented, zero orphaned.

### 11. `tests/Browser/MobileTokenRevokeFlowTest.php` unmodified by this change — CONFIRMED, with a correction to the literal instruction
`git diff main..HEAD -- tests/Browser/MobileTokenRevokeFlowTest.php` is **NOT** empty (69 lines) — but this is because `main` (6a77bc1) predates several unrelated prior features already merged onto `feat/qr-login` before this SDD change started (commit `3fd5223` created the file, `9093962` modified it — both ancestors of `c7c23a4`, the commit *before* this change's planning began). The correctly-scoped check is the diff across this change's own commits: `git diff c7c23a4..d8bb8f6 -- tests/Browser/MobileTokenRevokeFlowTest.php` → empty, and the file does not appear in `git diff --stat c7c23a4..d8bb8f6`'s changed-file list at all. The file is unmodified **by the api-qr-login change**. The browser test itself was also read end-to-end and confirmed it never interacts with the QR card.

### 12. QR card does not auto-open — CONFIRMED
`resources/js/components/settings/qr-login-card.tsx`: initial `useState<CardState>('idle')` (line 39), the `idle` branch (lines 152-165) renders only a bare button with an `onClick={mint}` handler — no `useEffect` with an empty/mount-only dependency array calls `mint()`. Both `useEffect` hooks in the file (poll timer, countdown timer) are gated on `state !== 'live'` and return early, so nothing fires until the user's first click. Docblock at lines 27-31 states this explicitly and names `MobileTokenRevokeFlowTest` as the reason. `MobileTokenRevokeFlowTest.php`'s flow (login → sidebar → mobile-token page → revoke) never clicks the QR card and asserts `assertNoJavascriptErrors()` at each step — consistent with the card staying inert.

## Also Assessed

**Migration rollback ordering**: `database/migrations/2026_08_01_124440_create_qr_login_passes_table.php`'s `down()` is a plain `Schema::dropIfExists('qr_login_passes')` — structurally trivial and safe to roll back (child table, no other table's FK depends on it). The documented ordering ("revert code, then `migrate:rollback --step=1`", `design.md:267`, `tasks.md:90`) is still accurate against current code: `MobileTokenQrController::store`/`status` and `QrLoginController::redeem` all query `qr_login_passes` directly; dropping the table before removing/disabling those routes would 500 the still-registered endpoints. Full `migrate:fresh`+`migrate:rollback` cycle was not executed live (sandbox denied reading `.env.testing` / no sqlite driver available in this shell for a scratch DB), so this is a structural read, not an executed rollback — noted as residual verification gap, low risk given the migration's simplicity.

**OpenAPI vs. reachable routes**: exact match, see check 10. Nothing documented-but-unreachable or reachable-but-undocumented for `api/*`. `settings/mobile-token/qr` (mint) and `.../qr/status` are web routes, correctly out of `openapi/v1.json`'s scope (which only covers `api/*`), consistent with how `settings/mobile-token` itself is handled.

**Secret/token exposure**: no `Log::`/`logger()` calls anywhere in the pass path (`QrLoginController`, `MobileTokenQrController`, `QrLoginPass` model, `QrLoginRequest`). `QrLoginRequest`'s validation messages (`token.required/string/regex`) never echo the submitted value. `MobileTokenController::show`'s Inertia props expose only `created_at`/`last_used_at` metadata, never a token value; the QR plaintext is delivered exclusively via a plain JSON response body (`MobileTokenQrController::store`), never via `Inertia::render` props — consistent with the design intent that plaintext must not survive in `window.history.state`. No error-tracking/Sentry-style integration found in `bootstrap/app.php`/`config/` that would independently capture request bodies. No findings.

## Issues

None CRITICAL. None WARNING. One SUGGESTION (non-blocking):

- **SUGGESTION**: the migration rollback ordering claim (also-assess item above) was verified by structural code reading, not by an executed `migrate:fresh` → `migrate:rollback` cycle, because this sandbox could not read `.env.testing` / lacked a usable sqlite driver in this shell session. Recommend a maintainer run the actual rollback cycle once in a normal dev shell before treating it as execution-proven; the migration itself is simple enough (`dropIfExists` only, no other table's FK references it) that this is low risk.

## Final Verdict: PASS

All 12 requested properties CONFIRMED with file:line evidence. Test suite 506/506 green (independently re-run, matches claim). Task completion 47/47 checked, matches code state. No evidence of a half-finished edit from the interrupted-session handoff — every trap named in the task list (atomicity docblock, sha256-not-bcrypt, 2-element contract whitelist, uniform-failure dataset, re-mint-vs-consumed race, mcp-token isolation, IP-keyed rate limit, gated QR click, browser-test isolation) was independently re-verified against actual code and passed.
