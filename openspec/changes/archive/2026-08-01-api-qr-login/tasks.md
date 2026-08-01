# Tasks: QR Login Pass (`api-qr-login`)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~890 (proposal Delivery Forecast; `package-lock.json` excluded) |
| 400-line budget risk | High |
| Chained PRs recommended | No |
| Suggested split | Single PR, 3 atomic commits |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

Chaining is declined by design, not convenience: PR #1 would ship an unauthenticated,
token-issuing endpoint to `main` with no producer and no governing UI.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | API redemption core: table, model, trait, controller, route, contract | Commit 1 (PR 1) | `php artisan test --compact --filter=ApiQrLoginTest` | `php artisan test --compact --filter=ApiContractTest` | Remove route + openapi op together (contract test enforces the pairing) |
| 2 | Web mint/status surface | Commit 2 (PR 1) | `php artisan test --compact --filter=MobileTokenQrTest` | N/A — no browser dependency yet | Remove `MobileTokenQrController`, its 2 routes, its limiter |
| 3 | Frontend card + browser E2E | Commit 3 (PR 1) | `php artisan test --compact --filter=QrLoginFlowTest` | `npm run build && npm run types:check` | Revert `qr-login-card.tsx` mount; `mobile-token.tsx` reverts unmounted |

## Phase 0: Baseline

- [x] 0.1 Run `php artisan test --compact` — confirm 489/489 green before any change.

## Phase 1: API Redemption Core (Commit 1, ~430 lines)

- [x] 1.1 Create migration `qr_login_passes`: `user_id` cascade, `token_hash` string(64) unique, `expires_at` indexed, `consumed_at`/`consumed_ip` nullable. No plaintext column.
- [x] 1.2 Create `QrLoginPass` model — `immutable_datetime` casts; add `User::qrLoginPasses(): HasMany`.
- [x] 1.3 Create `QrLoginPassFactory` with `expired()` and `consumed()` states.
- [x] 1.4 Create `Api/V1/Concerns/IssuesMobileToken` trait, lifted verbatim from `AuthController.php:24-28`.
- [x] 1.5 Refactor `AuthController::login` to delegate to the trait; re-run `ApiAuthTest` — stays green, behavior byte-identical.
- [x] 1.6 Create `QrLoginRequest`: regex `^pposter_qr_v1:[A-Za-z0-9_-]{43}$`, uniform Spanish message.
- [x] 1.7 RED `tests/Feature/ApiQrLoginTest.php`: valid redemption → 200, token `name===mobile`, `abilities===['mobile']`, `GET /api/v1/user` succeeds. [spec: Redemption Indistinguishable]
- [x] 1.8 RED atomicity-shape test: `DB::listen` — first `qr_login_passes` statement is `update`, exactly one `update`, zero preceding `select`. Pest is single-threaded and cannot reach the real race; this is the guard that survives a read-then-write refactor. [spec: Atomic Single-Use Consumption]
- [x] 1.9 RED sequential double-redeem: 200 then 422; exactly one `mobile` token after.
- [x] 1.10 RED pre-consumed row (factory `consumed()`) → `affected=0` → 422.
- [x] 1.11 RED TTL via `travel()`: `expires_at` exactly 60s after `created_at`; a 61s-old pass is refused.
- [x] 1.12 RED uniform-failure dataset: unknown / expired / consumed / malformed each assert the **same** `assertExactJson` body — 4 explicit assertions, not one. [spec: Uniform Redemption Failure]
- [x] 1.13 RED revocation: QR redemption retires the prior `mobile` token, `mcp` survives (mirrors `ApiAuthTest`). [api-auth: Single Active Mobile Token]
- [x] 1.14 RED throttle: 11th redemption/IP/min → 429 + `Retry-After` + Spanish body.
- [x] 1.15 GREEN `Api/V1/QrLoginController`: conditional `UPDATE` (`whereNull('consumed_at')`, `expires_at > now()`), `sha256` lookup — not bcrypt/argon2, a salted hash forbids equality lookup and forces the read-then-write shape this design forbids. Single `reject()` exit, no discriminating branch. Docblock states *why* the shape matters, not only that it does.
- [x] 1.16 Add `api-qr-redeem` limiter (10/min by IP) in `AppServiceProvider::configureRateLimiting()`.
- [x] 1.17 Register `Route::post('qr-login', …)` unauthenticated, `throttle:api-qr-redeem`, in `routes/api.php`.
- [x] 1.18 Add `redeemQrLoginPass` operation (tag `Auth`, no `security` key) + 2 schemas to `openapi/v1.json`.
- [x] 1.19 Confirm `ApiContractTest` fails red (undocumented route / missing exemption) before editing it.
- [x] 1.20 Edit `ApiContractTest.php:76` exemption to the 2-element whitelist `['POST api/v1/login', 'POST api/v1/qr-login']` — **never** a predicate; rename the test description. [api-auth: Versioned OpenAPI Contract]
- [x] 1.21 GREEN: `php artisan test --compact --filter=ApiQrLoginTest|ApiContractTest|ApiAuthTest`; `vendor/bin/pint --dirty --format agent`.

## Phase 2: Web Mint/Status Surface (Commit 2, ~180 lines)

- [x] 2.1 RED `tests/Feature/Settings/MobileTokenQrTest.php`: guest → 302, `assertDatabaseCount('qr_login_passes', 0)`.
- [x] 2.2 RED authenticated mint → 200 with plaintext payload + `expires_at`; stored row holds only the sha256 hash.
- [x] 2.3 RED second mint invalidates the first unconsumed pass.
- [x] 2.4 RED **mint over an already-consumed pass writes nothing and returns `consumed`** — its own test (factory `consumed()`), not folded into 2.3. Closes the re-mint/consume race: a blind delete-then-insert would destroy the just-consumed row and the theft banner with it.
- [x] 2.5 RED `status` never returns `payload` in any of `live`/`consumed`/`expired`/`none`.
- [x] 2.6 GREEN `Settings/MobileTokenQrController` (`store`, `status`): `lockForUpdate()` on the latest row before mint; delete only `whereNull('consumed_at')`.
- [x] 2.7 Add `qr-login-mint` limiter (20/min by user id, `?? $request->ip()` fallback).
- [x] 2.8 Add 2 routes to `routes/web.php` `auth` group (near `:48-49`).
- [x] 2.9 Add `QrPassMint`/`QrPassStatus` to `resources/js/types/index.ts`.
- [x] 2.10 GREEN: `php artisan test --compact --filter=MobileTokenQrTest`; `vendor/bin/pint --dirty --format agent`.

## Phase 3: Frontend Card + E2E (Commit 3, ~280 lines)

- [x] 3.1 Add `qrcode` to `package.json` — the **only** new dependency; no `@types/qrcode`.
- [x] 3.2 Create `resources/js/types/qrcode.d.ts` — 8-line ambient shim, `toString` only.
- [x] 3.3 Create `qr-login-card.tsx`: default state is a bare `<Button variant="outline">Mostrar código QR</Button>` — no mint/poll/import until clicked, so `MobileTokenRevokeFlowTest` sees an unchanged default page.
- [x] 3.4 Mint via `useHttp().post()` (`@inertiajs/react` v3, **first use in this repo** — budget review time for a first-time integration surprise) with **no `rememberKey`** overload — that path would let the plaintext be re-fetched.
- [x] 3.5 Render SVG (`qrcode.toString(payload,{type:'svg'})` → `dangerouslySetInnerHTML`) into `[data-testid="qr-code"]`; countdown from server `expires_at`.
- [x] 3.6 Poll `setInterval(3000)` via `useHttp().get()` — never Inertia `usePoll`, which re-renders from props and destroys the in-memory payload; stop on terminal state; re-mint only on a `live` poll response within 10s of expiry (poll-driven, not timer-driven).
- [x] 3.7 Mount card in `mobile-token.tsx` above the revoke card (`:62-135`); correct its docblock (`:26-33`) and `routes/web.php:44-47` comment — a pass is not a token.
- [x] 3.8 RED `tests/Browser/QrLoginFlowTest.php`: click → `<svg>` in `[data-testid="qr-code"]`, countdown copy, `assertNoJavascriptErrors()`.
- [x] 3.9 RED same test: simulate consumption from PHP (`QrLoginPass::first()->update(['consumed_at'=>now()])`) → card flips within one poll cycle, QR removed.
- [x] 3.10 Run `tests/Browser/MobileTokenRevokeFlowTest.php` UNTOUCHED and green — proves the gated-click default page never changed.
- [x] 3.11 GREEN: `npm run types:check` (passes with `qrcode` as the only new package), `npm run build`, `php artisan test --compact`.

## Phase 4: Final Verification

- [x] 4.1 Full suite `php artisan test --compact` — 489 baseline + new, 0 failures.
- [x] 4.2 `vendor/bin/pint --dirty --format agent` on every touched PHP file.
- [x] 4.3 Confirm no plaintext column exists anywhere; `token_hash` is 64-char sha256 hex only.
- [x] 4.4 Document rollback order for reviewers: revert code first, **then** `php artisan migrate:rollback --step=1` — the reverse 500s the still-live mint/status endpoints.
