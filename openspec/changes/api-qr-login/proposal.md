# Proposal: QR Login Pass (`api-qr-login`)

## Intent

Today the only way for the phone to obtain a `mobile` session is to type the owner's email and
password into the Flutter app (`app/Http/Controllers/Api/V1/AuthController.php:20-29`). That is the
one place in the whole system where the *master* credential is typed into a non-browser surface, on a
device with a soft keyboard, autocorrect, and clipboard managers. This change adds a second path: the
already-authenticated browser displays a short-lived **pass**, the phone scans it, and the pass is
exchanged for exactly the same `mobile` token — WhatsApp-Web inverted.

**The QR is a live credential displayed on a screen.** Anyone who photographs it, shoulder-surfs it,
or sees it on a shared monitor can take the session. Every mitigation below is a requirement, not a
suggestion. The design principle is: *bound the exposure window, make redemption a one-shot race
nobody can tie, and make theft visible to the owner within seconds.*

## Scope

### In Scope

- `qr_login_passes` table + `QrLoginPass` model: 256-bit token stored **hashed**, 60 s TTL, single
  live pass per owner, atomic single-use consumption, `consumed_at` / `consumed_ip` audit fields.
- `POST /api/v1/qr-login` — unauthenticated redemption, throttled, returning the byte-identical
  `{"token": "..."}` shape of `POST /api/v1/login`.
- `POST settings/mobile-token/qr` (mint, web session only) and `GET settings/mobile-token/qr/status`
  (consumption poll) — plain JSON, **not** Inertia props (Decision 6).
- QR display card added to the **existing** `settings/mobile-token` page, behind an explicit
  "Mostrar código QR" click: SVG render, countdown, silent re-mint, consumed/expired states.
- `openapi/v1.json` +1 operation, and the `ApiContractTest` bearer-auth exemption list (Decision 9).
- `qrcode` npm dependency (approved).

### Out of Scope

- **The Flutter scanner** — separate change `mobile-qr-scan` (`mobile_scanner`).
- **A manual "cancelar el código" button.** The 60 s TTL and re-mint-revokes-previous already bound
  exposure to ≤60 s. A 4th endpoint for a ≤60 s improvement is not worth the surface; cheap follow-up.
- **A new nav entry or a new settings page.** Decision 5.
- **Notifying the owner out-of-band on redemption** (mail/push). No mailer is configured; the in-page
  consumed state is the detection surface. Question 6.
- **QR login for the `mcp` token or the web session itself.** `mobile` ability only.
- Deployment work.

## Capabilities

### New Capabilities

- `api-qr-login`: the pass lifecycle (entropy, hashing, TTL, one-live-pass-per-owner, atomic
  single-use), the redemption endpoint and its non-oracle failure contract, the redemption limiter,
  and the browser display + consumption-observability surface.

### Modified Capabilities

- `api-auth`: **Single Active Mobile Token** — the requirement currently reads "A successful login
  MUST revoke every prior token named `mobile`" (`openspec/specs/api-auth/spec.md:61-64`). It must
  generalize to *any* mobile-session issuance, credentials **or** pass redemption (Decision 4).
- `api-auth`: **Versioned OpenAPI Contract Document** (`spec.md:179-190`) — the shipped
  `ApiContractTest` also asserts that every documented operation *except* `POST api/v1/login`
  declares `bearerAuth` (`tests/Feature/ApiContractTest.php:76`). That exemption set is folklore, not
  spec text. Promote it: *unauthenticated token-issuing operations declare no security; every other
  operation declares `bearerAuth`.*

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | **A dedicated `qr_login_passes` table — not the cache**, despite `config/cache.php:18` making a TTL store available with zero new infrastructure. | Three reasons, and the first is decisive. **(a) Atomicity.** Single-use must survive two phones scanning the same frame. `Cache::pull()` is a `get` followed by a `forget` — two statements, so both scanners can read the value before either deletes it, and both get a session. A table gives it in one statement: `UPDATE … SET consumed_at = ? WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > ?`, then assert `affected === 1`. The loser sees 0 rows on MySQL (row lock, re-evaluated predicate), Postgres (READ COMMITTED re-check), and SQLite (serialized writes). A `Cache::lock()` dance over `cache_locks` reaches the same guarantee with more moving parts and a lock-timeout failure mode. **(b) Observability requires a post-consumption state.** A cache scheme deletes the key on redemption, so "consumed" and "expired" become indistinguishable — which destroys the only signal that tells the owner their code was stolen (Decision 7). **(c)** `consumed_at` / `consumed_ip` are an audit trail; a deleted cache key is not. Cost: one migration, one model, one factory (~120 lines). |
| 2 | **TTL = 60 seconds.** The card silently re-mints at T-10 s, so a displayed code is never within 10 s of expiring. | Usability floor: unlock + open app + aim is realistically 8-20 s from cold; 60 s is ~3× headroom, and the re-mint means the user never watches a code die. Security ceiling: a photograph of the screen is dead inside a minute — and dead *immediately* if the page re-mints or a legit scan lands first. **Rejected 300 s**: long enough to walk away from the monitor and still use the photo, which is exactly the attack. **Rejected 30 s**: a failed biometric plus a cold app start plausibly exceeds it, producing rescan churn that trains the owner to leave the code up longer. |
| 3 | **One live pass per owner: minting revokes the previous unconsumed pass** (delete the owner's rows, then insert). | Turns the refresh cycle itself into a mitigation — every earlier frame someone photographed is already dead, not merely expiring. Also bounds table growth to ~1 row/owner without a scheduler. **Accepted edge case**: a user framing the old code at the instant of re-mint gets one 422 and re-aims at the new code, ~1 s of friction; the Spanish copy says so ("El código expiró. Escaneá el nuevo."). Two live passes would remove that friction and double the exposure surface — a bad trade. |
| 4 | **A QR login revokes the existing mobile token**, reusing `AuthController::login`'s exact three lines via a new `Api/V1/Concerns/IssuesMobileToken` trait (the same extraction pattern as `Concerns/ResolvesProjectByKey`). | Two independent reasons. **(a) Spec invariant.** "At most one `mobile` token MAY exist per owner at a time" (`spec.md:61-64`) is a property of the capability, not of the login *method*; exempting QR would silently break an archived requirement. **(b) Security signal.** If someone else redeems your code, your phone is logged out — loud, immediate, and impossible to miss. The trait also guarantees the stated goal that a QR-obtained session is *indistinguishable* from a credentials-obtained one: same name, same `['mobile']` ability, same response body, one implementation. |
| 5 | **Extend `settings/mobile-token`; do not create a new page or nav entry.** | The mitigation for a stolen pass is revocation — and the revoke button already lives on that page (`resources/js/pages/settings/mobile-token.tsx:82-134`). Putting the consumed banner one element above the button it wants you to press is worth more than tidiness. **The page's stated identity survives**: its docblock and `routes/web.php:44-47` promise the *Sanctum token's plaintext never reaches a browser* — still true. What reaches the browser is a *pass*: a different credential class, single-use, 60 s, not a session. The docblock and the route comment must be updated to say exactly that. Gating the QR behind an explicit click also means the existing `MobileTokenRevokeFlowTest` sees an unchanged default page state, and a drive-by visit or a prefetch never mints a credential. |
| 6 | **Mint returns plain JSON, not an Inertia prop.** | Inertia serializes page props into `window.history.state`. A pass delivered as a prop would be written into browser session history and survive back-navigation — the exact leak class this change exists to avoid. A JSON response consumed by the v3 `useHttp` client stays in component memory and dies with the unmount. It also fits the 60 s refresh cycle better than a partial reload. |
| 7 | **The card polls `…/qr/status` every 3 s and stops on a terminal state.** On `consumed` it freezes, shows "Se inició sesión en un teléfono a las HH:MM" plus the redeeming IP, and points at the revoke button below. | This is the theft-detection surface and the reason Decision 1 rejects the cache. If the owner did not scan, they learn within ~3 s and the remedy is one click away on the same screen. Without it, a stolen pass is silent forever. Polling only runs while a pass is live, so the page is inert by default. |
| 8 | **The QR encodes a bare opaque string: `pposter_qr_v1:<43-char base64url>`.** No URL, no custom scheme. | A **URL is clickable**: a generic scanner offers "open", which lands the live token in browser history, the web-server access log, and possibly a `Referer` — and makes a lookalike-domain phish possible. A **custom scheme** (`posterprojects://`) is registrable by any other Android app (scheme hijacking) and still lands in the scanner app's history. A bare string is inert in every generic scanner: nothing to open, nothing to navigate. The versioned prefix lets the Flutter app reject foreign QR codes *locally*, before spending a redemption attempt against the limiter, and leaves room for a v2 payload. **Accepted trade-off**: no zero-install deep-link onboarding — irrelevant for a single-owner app where the client is installed first. Token itself: `random_bytes(32)` → 256 bits → base64url; the row stores only `hash('sha256', …)`, mirroring Sanctum's own model, so a DB dump during the 60 s window yields no usable pass. Total payload 57 chars → a small, fast-scanning QR. |
| 9 | **Redemption limiter `api-qr-redeem`: 10/min keyed by IP alone. Mint limiter `qr-login-mint`: 20/min keyed by user id.** Both reuse `AppServiceProvider::throttled()` for the Spanish 429 (`app/Providers/AppServiceProvider.php:81-89`). | The redemption limit mirrors the second `api-login` limit exactly (`AppServiceProvider.php:70-72`) — IP is the only key that exists before authentication. Its job is noise and single-host hammering, not brute force: 2^256 is not searchable at any rate. **A global (all-IP) limit is deliberately declined**: in a single-owner app it is a self-DoS lever — any attacker could exhaust the shared bucket and lock the real phone out for a minute, buying nothing against an unsearchable token space. The mint limit exists so a runaway React poll or an XSS'd session cannot spin the pass factory. |
| 10 | **Every redemption failure is the same `422`** — unknown token, expired, already consumed, malformed — with one Spanish message: `{"message": "El código QR no es válido o expiró.", "errors": {"token": [...]}}`. | Distinct codes are an oracle: `404` says "no such pass", `410` says "it existed and is gone" — the second confirms a valid token to an attacker holding a photograph. One indistinguishable response, matching the existing 422 row of the error contract (`spec.md:114-124`), so **no change to the JSON error contract is needed**. |
| 11 | **OpenAPI**: path `/api/v1/qr-login`, tag `Auth`, `operationId` `redeemQrLoginPass`, **no `security` key** — exactly like `loginMobile` (`openapi/v1.json:41-108`). | `ApiContractTest` asserts route↔document set-equality in both directions (`ApiContractTest.php:52-67`) so route and document must land in the same commit. **Implementer trap**: the second test hard-codes `POST api/v1/login` as the *only* bearer-auth exemption (`ApiContractTest.php:76`) — a new unauthenticated operation is red until that list grows. Takes the contract from 10 documented operations to 11. The two `settings/mobile-token/qr*` routes do **not** start with `api/`, so the contract test correctly ignores them. |

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `database/migrations/*_create_qr_login_passes_table.php` | New | `user_id` (cascade), `token_hash` unique, `expires_at` idx, `consumed_at`, `consumed_ip` |
| `app/Models/QrLoginPass.php`, `database/factories/QrLoginPassFactory.php` | New | Factory states: `expired()`, `consumed()` |
| `app/Http/Controllers/Api/V1/Concerns/IssuesMobileToken.php` | New | The three lines shared with `AuthController::login` |
| `app/Http/Controllers/Api/V1/AuthController.php` | **Modified** | `login()` delegates to the trait — behaviour byte-identical, covered by shipped tests |
| `app/Http/Controllers/Api/V1/QrLoginController.php`, `app/Http/Requests/Api/V1/QrLoginRequest.php` | New | Atomic consume + Spanish 422 |
| `app/Http/Controllers/Settings/MobileTokenQrController.php` | New | `store` (mint) + `status` (poll), both JSON |
| `resources/js/components/settings/qr-login-card.tsx` | New | SVG render, countdown, re-mint, consumed/expired states |
| `resources/js/pages/settings/mobile-token.tsx` | Modified | Mount the card; correct the docblock (`:26-33`) per Decision 5 |
| `routes/web.php`, `routes/api.php` | Modified | +2 web routes near `:48-49`; +1 api route near `:11-13` |
| `app/Providers/AppServiceProvider.php` | Modified | +2 named limiters in `configureRateLimiting()` (`:64-74`) |
| `openapi/v1.json`, `tests/Feature/ApiContractTest.php` | Modified | +1 operation, +2 schemas; exemption list at `:76` |
| `package.json` | Modified | `qrcode` only (+ a local `.d.ts` shim — Question 2) |
| `resources/js/components/sidebar/sidebar-user-menu.tsx` | **Unchanged — enforced** | Decision 5. No new nav entry |
| `app/Enums/TokenName.php`, `bootstrap/app.php`, `MobileTokenController` | **Unchanged** | No new token name, no new error render, revoke reused as-is |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| **A photographed / shoulder-surfed code is redeemed by someone else** | **Med — the defining risk** | Layered, not single-point: 60 s TTL (D2), re-mint revokes the previous frame (D3), single-use (D1), the owner's phone is logged out (D4), and the page announces the consumption within ~3 s next to the revoke button (D7) |
| **Two simultaneous scans both get a session** | Low, catastrophic | One conditional `UPDATE`, `affected === 1` (D1). **Honest limit: true concurrency is not reproducible in Pest.** Compensating tests: sequential double-redeem → 422, a pre-consumed row → 0 affected, and a `DB::listen` shape assertion that the consume path issues exactly one `update` and no preceding `select` of the row |
| **A read-then-write creeps back in during a later refactor** | Med | The `DB::listen` shape test above fails loudly. Record the reason in the controller docblock, not just the spec |
| **`ApiContractTest` bearer-auth exemption forgotten** | **High** | D11 names it; the test fails on the first run of commit 1 |
| **The mint endpoint is reachable without a web session** | Low, severe | It lives inside the `auth` group of `routes/web.php:27`, not in `routes/api.php`. Feature test: guest → 302 to login, and no row is written |
| **Redemption gives a token distinguishable from a credentials token** | Med | D4's shared trait plus a test asserting `name === 'mobile'`, `abilities === ['mobile']`, and that a QR-obtained token passes the identical `GET /api/v1/user` assertions |
| **The 3 s poll fights the browser suite** (JS errors, flake) | Med | Polling only starts after an explicit click, so the shipped `MobileTokenRevokeFlowTest` path never polls. The new browser test asserts `assertNoJavascriptErrors()` on the polling state |
| **`@types/qrcode` is a second, unapproved package** | **High** | `qrcode` ships no types and `npm run types:check` (`package.json:13`) will fail. Default: a 3-line local `.d.ts` shim, zero dependencies. Question 2 |
| **`dangerouslySetInnerHTML` for the SVG** | Low | The encoded payload is regex-validated `/^pposter_qr_v1:[A-Za-z0-9_-]{43}$/` before it reaches the encoder, so no attacker-controlled string can be rendered. Design may instead render the module matrix as JSX `<rect>`s (~25 lines, zero injection) |
| **Orphan `qr_login_passes` rows accumulate** | Low | D3 deletes the owner's prior rows on every mint; a `Prunable` daily sweep is belt-and-braces, and the table is bounded at ~1 row without it |

## Rollback Plan

This is an authentication surface, so there are two distinct rollbacks and they are not the same.

**Emergency stop (seconds, no deploy pipeline needed).** Delete `POST /api/v1/qr-login` from
`routes/api.php` — every outstanding pass instantly becomes unredeemable, whatever is on any screen
or in anyone's camera roll. **Coupled cost, stated honestly**: `ApiContractTest` goes red until the
matching `openapi/v1.json` operation is removed too, so the emergency stop is *two* edits, not one.
Existing QR-obtained sessions keep working — they are ordinary `mobile` tokens; if the incident is a
*stolen* pass, the remedy is the shipped "Revocar token" button, not this.

**Full revert.** Revert the merge commit — routes, both controllers, the trait, the card, the
limiters, the OpenAPI operation and the contract-test exemption all disappear together, so
`ApiContractTest` re-greens by construction (route and document leave in the same commit).
`AuthController::login` returns to its inline form; its behaviour never changed, and its shipped
tests are the proof. **Then** run `php artisan migrate:rollback --step=1`. **Order matters**: rolling
the migration back first would 500 the still-live mint and status endpoints against a missing table.

**Data.** No existing table is altered and nothing outside `qr_login_passes` is written. Sanctum
tokens minted through QR survive a revert and keep authenticating — correct, not a bug: by design
(D4) they are indistinguishable from credentials-minted ones. If the revert is a security response,
revoke the token from `settings/mobile-token` first, then revert.

## Dependencies

- **`qrcode` (npm, approved)** — client-side SVG generation. No PHP QR library: the pass is rendered
  in the browser, so it is never re-serialized server-side.
- Possibly `@types/qrcode` (devDependency, types-only, zero runtime) — **not approved**; default is a
  local `.d.ts` shim. Question 2.
- Builds on `api-token-auth` (merged): `TokenName`, the `api-login` limiter, the JSON error contract.
- Blocks `mobile-qr-scan` (`mobile_scanner`), which cannot be built until the payload grammar in
  Decision 8 and the 422 contract in Decision 10 are frozen.

## Delivery Forecast

Estimated **~890 authored changed lines** against the 400-line review budget (~2.2×). The
`package-lock.json` delta is generated and excluded from the authored count.

- **Decision needed before apply: No** — `size:exception` is pre-authorized for this change.
- **Chained PRs recommended: No.**
- **400-line budget risk: High.**

The split that a chain would produce is *worse* here, and that is the reason to decline it: PR #1
would merge an **unauthenticated, token-issuing endpoint into `main` with no producer and no
governing UI**. Never ship a redemption oracle ahead of the surface that mints and observes it. Ships
as **one PR, three commits**, each green:

| # | Scope | ~Lines | Green at end |
|---|---|---|---|
| 1 | Migration, `QrLoginPass` + factory, `IssuesMobileToken` trait + `AuthController` delegation, `QrLoginRequest`, `Api/V1/QrLoginController`, `api-qr-redeem` limiter, route, OpenAPI operation + schemas, `ApiContractTest` exemption, `tests/Feature/ApiQrLoginTest.php` | ~430 | `php artisan test --compact` |
| 2 | `Settings/MobileTokenQrController` (mint + status), `qr-login-mint` limiter, 2 web routes, `tests/Feature/Settings/MobileTokenQrTest.php` | ~180 | `php artisan test --compact` |
| 3 | `qrcode` + shim, `qr-login-card.tsx`, `mobile-token.tsx` integration + docblock correction, `tests/Browser/QrLoginFlowTest.php` | ~280 | `php artisan test --compact`, `npm run build` |

Commit 1 cannot be split by layer: `ApiContractTest` asserts set-equality both ways, so a route
without its operation is red and an operation without its route is red. Strict TDD: RED before code,
inside each commit. Run `vendor/bin/pint --dirty --format agent` before every PHP commit.

## E2E Acceptance

Browser E2E **is** proposed and is viable — the suite works (the stale `public/hot` that was breaking
it is gone). `tests/Browser/QrLoginFlowTest.php`, following `MobileTokenRevokeFlowTest.php`:

1. Real form login → sidebar → "Token móvil" (`MobileTokenRevokeFlowTest.php:19-33`).
2. Click "Mostrar código QR" → assert an `<svg>` inside `[data-testid="qr-code"]`, assert the
   countdown copy, `assertNoJavascriptErrors()`.
3. **Simulate consumption from PHP** — `QrLoginPass::first()->update(['consumed_at' => now()])` —
   then assert the card flips to "Se inició sesión en un teléfono" within a poll cycle and the QR is
   gone. The full HTTP redemption path is covered by the feature test; this proves the observability
   requirement (D7) end to end.
4. **Rejected alternative**: exposing the pass plaintext in a `data-` attribute so the browser test
   could redeem it for real. Never put a live credential in the DOM as text — the whole point of D8.

Known gotchas to reuse: click the dialog's confirm **by selector**, never by shared button text
(`MobileTokenRevokeFlowTest.php:42-47`); `flushSession()` + `forgetGuards()` before asserting a
bearer token is dead (`:58-59`).

## Success Criteria

- [ ] A valid pass returns `200 {"token": "..."}` and the stored token is `name === 'mobile'` with
      `abilities === ['mobile']` — asserted with the same expectations as the credentials login.
- [ ] Redeeming the **same** pass twice returns `200` then `422`, and only **one** `mobile` token
      exists afterwards.
- [ ] Consuming a pass is a **single** conditional `UPDATE` with no preceding row `select`
      (`DB::listen` shape assertion), and a pre-consumed row yields 0 affected rows.
- [ ] Unknown, expired, consumed and malformed tokens all return the **identical** 422 body — no
      response distinguishes them.
- [ ] A pass older than 60 s is refused; `expires_at` is exactly 60 s after `created_at`.
- [ ] Minting a second pass makes the first unredeemable (D3).
- [ ] A successful QR login revokes the prior `mobile` token and leaves an `mcp` token untouched.
- [ ] `token_hash` in the DB is a 64-char SHA-256 and the plaintext appears in **no** table.
- [ ] The 11th redemption from one IP in a minute returns `429` with `Retry-After` and the Spanish
      throttle body.
- [ ] A guest hitting `POST settings/mobile-token/qr` is redirected to login and **no row is written**.
- [ ] `ApiContractTest` passes with 11 documented operations; `qr-login` declares no `security`, every
      other operation still declares `bearerAuth`.
- [ ] The browser test renders the QR, shows the countdown, flips to the consumed state, and reports
      no JavaScript errors.
- [ ] `php artisan test --compact` green (489 + new), `npm run build` green.

## Proposal question round

Interactive shaping was unavailable (`auto` mode, owner asked not to be stopped). These are recorded
for correction before `sdd-spec`; each has a stated default so the pipeline is not blocked.

1. **Is 60 s the right TTL?** It is the single number that trades photograph-exposure against a
   fumbling unlock. **Assumed: 60 s, re-mint at T-10 s** (D2).
2. **`@types/qrcode` or a local `.d.ts` shim?** Only `qrcode` was approved, and `npm run types:check`
   will fail without types. **Assumed: local 3-line shim, no second package.**
3. **Should the QR live on `settings/mobile-token` or its own page?** Extending it puts the theft
   signal next to the revoke button but softens that page's "no plaintext ever" identity (which
   still holds for the *token* — a pass is not a token). **Assumed: extend it** (D5).
4. **Should a QR login kick the existing phone off?** It is the archived single-active-token
   invariant and doubles as a theft alarm, but it means logging in on a second device silently kills
   the first. **Assumed: yes, revoke** (D4).
5. **Is a manual "cancelar el código" button wanted?** The TTL already bounds exposure to ≤60 s.
   **Assumed: out of scope, cheap follow-up.**
6. **Should redemption notify the owner out of band?** The in-page banner only works if the browser
   tab is still open — a thief could scan after the owner walks away. No mailer is configured today.
   **Assumed: no; in-page observability only.** If out-of-band alerting is wanted, it is its own
   change (mail config + notification).
