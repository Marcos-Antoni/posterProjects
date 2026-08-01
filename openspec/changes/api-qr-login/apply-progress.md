# Apply Progress: QR Login Pass (`api-qr-login`)

**Mode**: Strict TDD (`openspec/config.yaml:18`)
**Status**: 40/40 tasks complete (47 checklist rows across Phase 0-4). Ready for verify.

> This batch resumed a prior session that had already implemented and left uncommitted
> Phases 0-3 (migration, model, factory, trait, `QrLoginController`, `MobileTokenQrController`,
> the frontend card, and the browser test) but had not marked `tasks.md`, run final
> verification, or written this artifact. This session verified every existing file against
> `design.md` line-by-line, ran the full verification suite fresh, found zero defects, and
> completed Phase 4. No production code was changed in this batch — verification only.

## TDD Cycle Evidence

| Task | RED | GREEN | REFACTOR |
|---|---|---|---|
| 1.5 `AuthController` → trait delegation | `ApiAuthTest` was green pre-refactor (baseline behavior) | `IssuesMobileToken` trait extracted, `AuthController::login` delegates; `ApiAuthTest` re-run green, byte-identical | Trait docblock cross-references the shared shape |
| 1.7 Valid redemption | Test written before `QrLoginController` existed → red (404/route missing) | `QrLoginController::redeem` + route + `IssuesMobileToken` → 200, `mobile`/`['mobile']`, `/api/v1/user` succeeds | — |
| 1.8 Atomicity shape (`DB::listen`) | Red before controller existed | Green once the single conditional `UPDATE` shipped, first statement `update`, exactly one `update`, zero preceding `select` | Reasoning duplicated into `QrLoginController` docblock (trap #2), not only the test |
| 1.9 Sequential double-redeem | Red (no controller) | 200 then 422, one `mobile` token after | — |
| 1.10 Pre-consumed row | Red (no controller) | `affected=0` → 422, consumed row untouched | — |
| 1.11 TTL via `travel()` | Red (no controller) | `expires_at` exactly 60s after `created_at`; 61s-old refused | — |
| 1.12 Uniform-failure dataset | Red (no controller) | 4 explicit `assertExactJson` cases (unknown/expired/consumed/malformed) share one body | — |
| 1.13 Revocation (`mcp` survives) | Red (no controller) | Prior `mobile` dies via trait's `where('name','mobile')` delete, `mcp` unaffected | — |
| 1.14 Throttle 11th/min | Red (no limiter) | `api-qr-redeem` limiter (10/min by IP) → 429 + `Retry-After` + Spanish body | — |
| 1.19-1.20 Contract exemption | `ApiContractTest` confirmed red before the whitelist edit (undocumented route) | 2-element whitelist `['POST api/v1/login', 'POST api/v1/qr-login']`, never a predicate | Test description renamed |
| 2.1 Guest mint gate | Red (no controller/route) | 302 + `assertDatabaseCount('qr_login_passes', 0)` | — |
| 2.2 Authenticated mint | Red (no controller) | 200, plaintext once, stored row holds only the sha256 hash | — |
| 2.3 Second mint invalidates first | Red (no controller) | Old unconsumed pass gone, new one live | — |
| 2.4 Mint-over-consumed race | Red (no controller) — its own test, not folded into 2.3 | `lockForUpdate()` + early return on consumed row, writes nothing | Docblock states the re-mint/consume race explicitly (trap #1) |
| 2.5 Status never leaks payload | Red (no controller) | `assertJsonMissingPath('payload')` across live/consumed/expired/none | — |
| 3.8 Browser: reveal + countdown | Red (no card mounted, no route) | Click reveals `<svg>` in `[data-testid="qr-code"]`, countdown copy, no JS errors | — |
| 3.9 Browser: consumption flips card | Red (no poll wiring) | Simulated PHP-side consumption flips the card within one poll cycle, QR removed | — |

## Work Unit Evidence (this batch — Phase 3 + Phase 4 verification)

| Evidence | Value |
|---|---|
| Focused test command and result | `php artisan test --compact --filter=QrLoginFlowTest` → included in the full run below; browser flow passes (reveal, countdown, consumption flip, zero JS errors) |
| Runtime harness command/scenario and result | `php artisan test --compact` (full suite, Pest v4 browser driver) → `506 passed, 506 total, 1671 assertions`, 0 failures |
| Rollback boundary | This batch made no code edits — only `tasks.md` checkbox updates and this file. The underlying Phase 3 code (already present) reverts as one unit: remove `qr-login-card.tsx`, its mount + docblock line in `mobile-token.tsx`, the `routes/web.php:44-47` comment, `qrcode`/`qrcode.d.ts`, and `QrLoginFlowTest.php` |

## Completed Tasks (all phases)

- [x] 0.1 Baseline confirmed 489/489 green before any change (prior session)
- [x] 1.1-1.21 API redemption core: migration, model, factory, `IssuesMobileToken` trait, `AuthController` delegation, `QrLoginRequest`, `QrLoginController`, `api-qr-redeem` limiter, route, OpenAPI operation + 2 schemas, `ApiContractTest` exemption, full RED→GREEN cycle
- [x] 2.1-2.10 Web mint/status surface: `MobileTokenQrTest`, `MobileTokenQrController` (store/status, `lockForUpdate`, consumed-row guard), `qr-login-mint` limiter, 2 web routes, `QrPassMint`/`QrPassStatus` types
- [x] 3.1-3.11 Frontend card + E2E: `qrcode` dependency, ambient shim, gated `qr-login-card.tsx`, `useHttp` mint (no `rememberKey`), SVG render + countdown, poll-driven re-mint, mount in `mobile-token.tsx`, `QrLoginFlowTest` (reveal + consumption-flip), `MobileTokenRevokeFlowTest` confirmed untouched and green, types:check/build/full-suite green
- [x] 4.1-4.4 Final verification: full suite green, Pint clean, no plaintext column confirmed, rollback order documented (design.md "Migration / Rollout" + here)

## Files Changed

| File | Action | What Was Done |
|------|--------|---------------|
| `database/migrations/2026_08_01_124440_create_qr_login_passes_table.php` | Created | `user_id` cascade, `token_hash` string(64) unique, `expires_at` indexed, `consumed_at`/`consumed_ip` nullable, no plaintext column |
| `app/Models/QrLoginPass.php` | Created | `immutable_datetime` casts, `user(): BelongsTo` |
| `app/Models/User.php` | Modified | Added `qrLoginPasses(): HasMany` |
| `database/factories/QrLoginPassFactory.php` | Created | Default live state + `expired()`/`consumed()` states |
| `app/Http/Controllers/Api/V1/Concerns/IssuesMobileToken.php` | Created | The 3 shared token-mint lines, lifted verbatim from `AuthController.php:24-28` |
| `app/Http/Controllers/Api/V1/AuthController.php` | Modified | `login()` delegates to the trait; behavior byte-identical |
| `app/Http/Requests/Api/V1/QrLoginRequest.php` | Created | Regex `^pposter_qr_v1:[A-Za-z0-9_-]{43}$`, uniform Spanish message per rule |
| `app/Http/Controllers/Api/V1/QrLoginController.php` | Created | Single conditional `UPDATE`, `sha256` lookup, single `reject()` exit, docblock explains the shape (trap #2) |
| `app/Providers/AppServiceProvider.php` | Modified | `api-qr-redeem` (10/min by IP) + `qr-login-mint` (20/min by user id, IP fallback) limiters |
| `routes/api.php` | Modified | Unauthenticated `POST qr-login` route, `throttle:api-qr-redeem` |
| `openapi/v1.json` | Modified | `redeemQrLoginPass` operation (tag `Auth`, no `security` key), `QrLoginRequest`/`QrLoginFailureResponse` schemas |
| `tests/Feature/ApiContractTest.php` | Modified | Exemption whitelist grown to 2 elements (`['POST api/v1/login', 'POST api/v1/qr-login']`), never a predicate; description renamed |
| `tests/Feature/ApiQrLoginTest.php` | Created | Redemption, atomicity-shape (`DB::listen`), double-redeem, pre-consumed, TTL, uniform-failure dataset, revocation, throttle |
| `app/Http/Controllers/Settings/MobileTokenQrController.php` | Created | `store` (`lockForUpdate`, consumed-row guard, delete-only-unconsumed) + `status` |
| `tests/Feature/Settings/MobileTokenQrTest.php` | Created | Guest gate, mint, second-mint invalidation, mint-over-consumed race, status never leaks payload |
| `resources/js/types/index.ts` | Modified | `QrPassMint`/`QrPassStatus` types |
| `package.json` | Modified | `qrcode` dependency only |
| `resources/js/types/qrcode.d.ts` | Created | 8-line ambient shim, `toString` only, no `@types/qrcode` |
| `resources/js/components/settings/qr-login-card.tsx` | Created | Gated click, `useHttp` mint (no `rememberKey`), SVG render, local countdown, poll-driven re-mint/terminal states |
| `resources/js/pages/settings/mobile-token.tsx` | Modified | Mounts `QrLoginCard` above the revoke card, docblock corrected |
| `routes/web.php` | Modified | +2 `settings/mobile-token/qr[/status]` routes in the `auth` group, comment corrected (a pass is not a token) |
| `tests/Browser/QrLoginFlowTest.php` | Created | Reveal → `<svg>` + countdown, simulated PHP-side consumption → card flips within one poll cycle |
| `tests/Browser/MobileTokenRevokeFlowTest.php` | **Unchanged — verified** | `git diff` empty; full suite includes it and it is green |

## Deviations from Design

None — implementation matches `design.md` line-by-line, verified file-by-file against every Decision (1-5), Flow (A/B/C), and Interface/Contract section during this batch's review.

## Issues Found

None. All 8 traps listed in the launch instructions were independently verified as correctly handled in the existing code:

1. Re-mint/consume race — `MobileTokenQrController::store` takes `lockForUpdate()`, returns `consumed` and writes nothing when the latest row is already consumed; delete is `whereNull('consumed_at')`-scoped.
2. Atomicity guard — `QrLoginController::redeem` docblock explicitly states the DO-NOT-refactor reasoning, cross-referencing the `DB::listen` shape test.
3. `sha256`, never bcrypt/argon2 — confirmed in `QrLoginController::redeem` and `MobileTokenQrController::store`.
4. `ApiContractTest.php:76` exemption is a literal 2-element array + `in_array(..., true)`, not a predicate.
5. Uniform failure — `ApiQrLoginTest`'s dataset test asserts identical `assertExactJson` for all 4 cases (unknown/expired/consumed/malformed).
6. `useHttp().post()`/`useHttp().get()` used with no `rememberKey` overload anywhere in `qr-login-card.tsx`.
7. QR card default state is a bare `<Button variant="outline">Mostrar código QR</Button>`; `git diff tests/Browser/MobileTokenRevokeFlowTest.php` is empty and the test passes in the full suite.
8. `QrLoginController::redeem` calls `$this->issueMobileToken()` from the shared `IssuesMobileToken` trait — the exact same 3 lines `AuthController::login` now also delegates to; the trait's delete is `where('name','mobile')`-scoped.

## Final Verification (this batch)

```
php artisan test --compact
{"tool":"pest","result":"passed","tests":506,"passed":506,"assertions":1671,"duration_ms":32584}
```

- `vendor/bin/pint --dirty --format agent` → `{"tool":"pint","result":"passed"}`
- `npm run types:check` (`tsc --noEmit`) → clean, no errors
- `npm run build` → succeeded, `mobile-token-CtzzPZkx.js` chunk built (29.55 kB)
- `git diff tests/Browser/MobileTokenRevokeFlowTest.php` → empty (0 lines)
- No plaintext column: `qr_login_passes` has only `token_hash` (string(64), sha256 hex); confirmed in migration and every test that inspects the stored row.

**No `git commit`, `git push`, or PR was created — implementation and verification only, per instructions.**

## Rollback Order (task 4.4)

Documented for reviewers in `design.md` "Migration / Rollout" and repeated here:

1. **Emergency stop**: delete the route from `routes/api.php` **and** its operation from
   `openapi/v1.json` together (two edits, not one — `ApiContractTest` goes red otherwise). Every
   outstanding pass becomes unredeemable instantly.
2. **Full revert**: revert the merge commit (route and document leave together, contract test
   re-greens by construction), **then** `php artisan migrate:rollback --step=1` — in that order.
   Reversing the migration first while the mint/status endpoints are still routed 500s them against
   a missing relation.
3. QR-obtained Sanctum tokens survive a revert and keep authenticating — that is correct, not a bug
   (redemption is indistinguishable from credentials login by construction). If the revert is a
   security response, revoke from `settings/mobile-token` **first**, then revert.

## Status

40/40 tasks complete. All 4 phases done. Full suite green (506/506, 0 failures). Pint, types:check,
and build all clean. Ready for `sdd-verify`.
