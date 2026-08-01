# Design: QR Login Pass (`api-qr-login`)

## Technical Approach

A short-lived, single-use **pass** is minted for an authenticated browser session, rendered
client-side as a QR, and exchanged by the phone for the *same* `mobile` Sanctum token that
`POST /api/v1/login` issues today (`app/Http/Controllers/Api/V1/AuthController.php:20-29`).

Three properties carry the whole design and everything else is subordinate to them:

1. **The pass is stored only as a SHA-256 hash.** The DB never holds a redeemable secret.
2. **Consumption is one conditional `UPDATE` whose affected-row count is the authorization
   decision.** Not a read, then a check, then a write.
3. **Every failure is one byte-identical `422`.** There is no branch that could drift into an
   oracle, because there is only one branch.

The redemption path reuses `AuthController::login`'s exact three lines through a new trait, so a
QR-obtained session is indistinguishable from a credentials-obtained one *by construction*, not by
assertion.

---

## Architecture Decisions

### Decision 1: `qr_login_passes` stores `sha256(plaintext)`, not bcrypt/argon2, not plaintext

**Choice**: `hash('sha256', $plain)` → 64 hex chars in a `string(64)` unique column.

| Alternative | Why rejected |
|---|---|
| Plaintext column | A DB dump during the 60 s window is a live credential. Non-starter. |
| `bcrypt` / `argon2id` | **Fatal, and the reason is structural, not performance.** A per-row salted hash cannot be looked up by equality. It forces `SELECT candidates → verify each in PHP → UPDATE` — which is *exactly* the read-then-write shape Decision 2 exists to forbid. The hash choice and the atomicity mechanism are the same decision. |
| HMAC with `APP_KEY` | Marginal gain (an attacker with the DB usually has `.env`), and adds a key-rotation failure mode to a 60 s credential. |

**Rationale**: the secret is `random_bytes(32)` — 256 uniformly random bits, not a user-chosen
password. There is no dictionary and no rainbow table at that entropy, so a deliberately slow KDF
buys nothing and costs the property we actually need (equality lookup). This mirrors Sanctum's own
model: `personal_access_tokens.token` is `string(64)->unique()`, a SHA-256 hex digest
(`database/migrations/2026_07_23_041942_create_personal_access_tokens_table.php:18`).

`hash_equals` is deliberately **not** used: nothing is compared in PHP. The DB compares an index
key, and the value it compares is already a digest — a timing side-channel on the index probe
reveals nothing about the preimage.

### Decision 2: single-use is one conditional `UPDATE`, asserted on `affected === 1`

**Choice**:

```php
$affected = DB::table('qr_login_passes')
    ->where('token_hash', hash('sha256', $plain))
    ->whereNull('consumed_at')
    ->where('expires_at', '>', now())
    ->update([
        'consumed_at' => now(),
        'consumed_ip' => $request->ip(),
        'updated_at'  => now(),
    ]);

if ($affected !== 1) {
    $this->reject();           // the ONLY failure exit — see Decision 4
}

// Safe only because the row is already claimed:
$pass = QrLoginPass::where('token_hash', hash('sha256', $plain))->sole();
return $this->issueMobileToken($pass->user);
```

The `SELECT` comes strictly **after** the winning write. That ordering is the entire mechanism.

**Alternatives rejected**: `Cache::pull()` (a `get` followed by a `forget` — two statements, both
scanners read before either deletes); `Cache::lock()` (reaches the same guarantee with a lock-timeout
failure mode and more moving parts); a `SELECT ... FOR UPDATE` then `UPDATE` (correct, but two
round-trips and it re-teaches the read-then-write shape to the next reader of this file).

**Rationale**: `affected === 1` is not a check performed *on* the row, it is the row-level lock
result reported *by the engine*. It cannot be raced because there is nothing between the read and
the write to race with — they are the same statement.

### Decision 3: mint deletes only *unconsumed* passes, and refuses to mint over a consumed one

**Choice**: `->whereNull('consumed_at')->delete()`, plus a `lockForUpdate()` read of the latest row;
if that row is already consumed, mint **writes nothing** and returns the `consumed` state instead.

**Rationale**: this closes a race the naive "delete the owner's rows, then insert" would open. The
card silently re-mints at T-10 s. If a thief redeems at T-9.9 s, a blind re-mint would delete the
consumed row and the owner would **never see the theft banner** — destroying the exact signal
Decision 7 of the proposal exists to provide. Retaining consumed rows also preserves
`consumed_at`/`consumed_ip` as the audit trail (proposal D1c). Growth is one row per *successful* QR
login; a `Prunable` sweep is a follow-up, not a requirement.

### Decision 4: a single `reject()` with no discriminating branch

**Choice**: unknown, expired and consumed are literally the same code path — they are all
`$affected !== 1`. Malformed is caught one layer up by a `regex` rule on `QrLoginRequest` whose
message is the *same string*, so a FormRequest failure and a controller failure emit byte-identical
bodies.

**Alternatives rejected**: `404` for unknown + `410` for consumed. `410` confirms "this token existed
and was valid" to an attacker holding a photograph — a perfect oracle.

### Decision 5: no `@types/qrcode`; a hand-written 8-line ambient shim

**Choice**: `resources/js/types/qrcode.d.ts`, declaring only `toString`.

**Rationale**: `tsconfig.json:118` already includes `resources/js/**/*.d.ts` and
`resources/js/types/` is already the project's type home (`global.d.ts`, `vite-env.d.ts`), so no
`typeRoots`/`paths` edit is required. Declaring only the one function we call is a feature: an
un-reviewed second API surface cannot be reached by accident.

---

## Data Flow

### Flow A — two simultaneous scans (the load-bearing case)

Both phones present the *same* valid pass at the same instant.

```
   Phone A                        PostgreSQL                        Phone B
      │                               │                                │
      │ POST /api/v1/qr-login         │          POST /api/v1/qr-login │
      ├──────────────────────────────►│◄───────────────────────────────┤
      │                               │
      │ UPDATE qr_login_passes                                         │
      │   SET consumed_at = now()                                      │
      │  WHERE token_hash = H                                          │
      │    AND consumed_at IS NULL                                     │
      │    AND expires_at > now()                                      │
      ├──────────────────────────────►│                                │
      │                    ┌──────────┴──────────┐                     │
      │                    │ A takes the row's   │                     │
      │                    │ exclusive lock.     │◄────────────────────┤
      │                    │ Predicate TRUE      │  B blocks on the
      │                    │ → 1 row written     │  SAME row lock
      │                    └──────────┬──────────┘
      │◄──── affected = 1 ────────────┤
      │                    ┌──────────┴──────────┐
      │                    │ A commits.          │
      │                    │ Lock released.      │
      │                    │ B's predicate is    │
      │                    │ RE-EVALUATED against│
      │                    │ A's committed row:  │
      │                    │ consumed_at IS NULL │
      │                    │        → FALSE      │
      │                    └──────────┬──────────┘
      │                               ├──── affected = 0 ─────────────►│
      │                                                                │
  SELECT pass (already claimed)                                  422, uniform body
  revoke prior `mobile` token                             "El código QR no es válido o expiró."
  createToken('mobile', ['mobile'])
  200 {"token": "…"}
```

**Why exactly one wins, per engine.** PostgreSQL (the test connection, `phpunit.xml:29`) under READ
COMMITTED blocks B on the row, then re-checks B's `WHERE` against the *updated* tuple via EvalPlanQual;
the predicate is now false, so the row is skipped and `affected = 0`. MySQL/InnoDB takes the same
exclusive lock on the unique-index entry and re-reads the row after A commits. SQLite serialises
writers at the database level, so B's `UPDATE` runs strictly after A commits.

### Flow B — the trap this design exists to prevent

The obvious implementation is:

```php
$pass = QrLoginPass::where('token_hash', $h)->first();                       // READ
if (! $pass || $pass->consumed_at || $pass->expires_at->isPast()) { fail(); } // CHECK
$pass->update(['consumed_at' => now()]);                                      // WRITE
```

```
   Phone A                                                          Phone B
      │ SELECT … → consumed_at = NULL                                    │
      │                          │ SELECT … → consumed_at = NULL         │
      │                          │◄──────────────────────────────────────┤
      │ check passes ✓           │ check passes ✓
      │ UPDATE                   │ UPDATE
      │ 200 {"token": "…"}       │ 200 {"token": "…"}   ← TWO SESSIONS
      ▼                          ▼
                        …and because a QR login revokes the prior `mobile`
                        token (Decision 6), B's issuance silently KILLS the
                        token A just received. The owner is logged out by
                        the thief, or vice-versa, with no error anywhere.
```

**State it plainly: this implementation passes every test we are able to write in Pest.** The
sequential double-redeem test passes (`200` then `422`). The pre-consumed-row test passes (`422`).
The expired test passes. Pest is single-threaded; the interleaving above is simply not reachable from
the test runner. A green suite is *not* evidence that this bug is absent.

Therefore the guard is a **shape** assertion, not a behaviour assertion:

```php
$statements = [];
DB::listen(function ($q) use (&$statements): void {
    if (str_contains($q->sql, 'qr_login_passes')) { $statements[] = $q->sql; }
});

$this->postJson('/api/v1/qr-login', ['token' => $plain])->assertOk();

expect($statements[0])->toStartWith('update');                                  // first touch is the claim
expect(collect($statements)->filter(fn ($s) => str_starts_with($s, 'update')))->toHaveCount(1);
```

If a later refactor reintroduces the read-then-write shape, `$statements[0]` becomes `select` and
this test goes red while every behavioural test stays green. The *reason* lives in the
`QrLoginController` docblock, not only in the spec — the test tells you it broke, the docblock tells
you why it exists.

### Flow C — mint / display / redeem / observe

```
Browser (settings/mobile-token)          Laravel                      Phone
        │                                   │                            │
        │ click "Mostrar código QR"          │                            │
        │ POST settings/mobile-token/qr ────►│ lockForUpdate latest row
        │      (web session, throttled)      │ consumed? → return consumed, write nothing
        │                                    │ else: delete unconsumed rows (D3)
        │                                    │       insert sha256(plain), expires_at = +60s
        │◄─ 200 {state:'live', payload, expires_at}
        │   payload lives in useState ONLY — never a prop, never re-fetchable
        │                                    │
        │ qrcode.toString(payload,{type:'svg'}) → <svg> in [data-testid="qr-code"]
        │                                    │            scan (camera, no network)
        │                                    │◄───────────────────────────┤
        │                                    │  POST /api/v1/qr-login {token}
        │                                    │  ── Flow A ──►  200 {"token": "…"}
        │ GET …/qr/status  every 3s ────────►│                            │
        │◄─ {state:'consumed', consumed_at, consumed_ip}
        │   poll stops, QR is removed, banner points at "Revocar token"
```

---

## Interfaces / Contracts

### Migration — `database/migrations/XXXX_XX_XX_XXXXXX_create_qr_login_passes_table.php`

```php
public function up(): void
{
    Schema::create('qr_login_passes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('token_hash', 64)->unique();   // sha256 hex; unique = the lookup key
        $table->timestamp('expires_at')->index();     // mirrors personal_access_tokens:21
        $table->timestamp('consumed_at')->nullable(); // NULL is the single-use predicate
        $table->string('consumed_ip', 45)->nullable();// IPv6 max textual length + headroom
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('qr_login_passes');
}
```

| Column / index | Why |
|---|---|
| `token_hash` **unique** | It is the `WHERE` key of the conditional `UPDATE`. Unique also turns an (impossible) digest collision into a DB error rather than a silent cross-account grant. |
| `expires_at` **indexed** | Part of the consume predicate and of any future prune sweep. Same shape as Sanctum's own `expires_at` index. |
| `user_id` cascade | Deleting the owner must not orphan a redeemable credential. FK carries its own index, which serves D3's `whereNull('consumed_at')->delete()`. |
| `consumed_at` nullable | `NULL` *is* the liveness flag. Not a boolean: the timestamp is simultaneously the flag and the audit record. |
| **No plaintext column** | Decision 1. Success criterion: the plaintext appears in no table. |

**Rollback order matters.** Revert the code first, then `php artisan migrate:rollback --step=1`.
Dropping the table while the mint/status endpoints are still routed 500s them against a missing
relation.

### `app/Models/QrLoginPass.php`

```php
protected $fillable = ['user_id', 'token_hash', 'expires_at', 'consumed_at', 'consumed_ip'];

protected function casts(): array
{
    return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
}
```

`immutable_datetime`, not `datetime` — `AppServiceProvider.php:40` sets
`Date::use(CarbonImmutable::class)` app-wide. Plus `User::qrLoginPasses(): HasMany`.

### `app/Http/Controllers/Api/V1/Concerns/IssuesMobileToken.php`

Lifted verbatim from `AuthController.php:24-28`, following the `Concerns/ResolvesProjectByKey`
extraction pattern (`app/Http/Controllers/Api/V1/Concerns/ResolvesProjectByKey.php:8-19`):

```php
protected function issueMobileToken(User $user): JsonResponse
{
    $user->tokens()->where('name', TokenName::Mobile->value)->delete();

    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value]);

    return response()->json(['token' => $token->plainTextToken]);
}
```

`AuthController::login` collapses to `return $this->issueMobileToken($request->authenticate());` —
behaviour byte-identical, proven by the shipped `ApiAuthTest`.

**Orchestrator resolution 2 is satisfied structurally**: the delete is `where('name', 'mobile')`-scoped,
so an `mcp` token is never touched — the same scoping as `MobileTokenController::destroy`
(`app/Http/Controllers/Settings/MobileTokenController.php:43`). Indistinguishability is not asserted,
it is *the same function*.

### Payload grammar

`pposter_qr_v1:` + `rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')` → 43 chars → 57 total.

`QrLoginRequest`:

```php
'token' => ['required', 'string', 'regex:/^pposter_qr_v1:[A-Za-z0-9_-]{43}$/'],
```

with `messages()` returning the **same** Spanish string for every rule, so malformed input is
indistinguishable from every other failure.

### Uniform failure body

```php
private function reject(): never
{
    throw ValidationException::withMessages([
        'token' => 'El código QR no es válido o expiró.',
    ]);
}
```

```json
{
  "message": "El código QR no es válido o expiró.",
  "errors": { "token": ["El código QR no es válido o expiró."] }
}
```

Matches the existing `422` row of the JSON error contract (`openspec/specs/api-auth/spec.md:114-124`)
— **no change to the error contract is needed**.

**On the timing channel — yes, it is real, and here is the honest position.** Returning early on
"not found" versus "expired" would leak through wall-clock time as well as status. This design has no
such branch: unknown, expired and consumed all traverse the identical single `UPDATE` and the
identical throw, so the oracle that matters is closed in time as well as in content. The one residual
is that **malformed** rejects at the FormRequest layer without touching the database, and is therefore
measurably faster. **We accept it.** The only fact that channel discloses is "your string is not 43
base64url characters" — which the attacker already knows, because they typed it. It does not separate
unknown-from-expired-from-consumed. Constant-time padding is explicitly declined: it puts a sleep on
the hot path of a single-owner app, and `2^256` is not searchable at 10 requests/minute regardless.
Recorded here so a later reviewer reads the omission as a decision, not an oversight.

### Rate limiting — `AppServiceProvider::configureRateLimiting()` (`app/Providers/AppServiceProvider.php:64-74`)

```php
RateLimiter::for('api-qr-redeem', fn (Request $request): Limit => Limit::perMinute(10)
    ->by($request->ip())
    ->response($this->throttled(...)));

RateLimiter::for('qr-login-mint', fn (Request $request): Limit => Limit::perMinute(20)
    ->by((string) ($request->user()?->id ?? $request->ip()))
    ->response($this->throttled(...)));
```

**Keyed on IP for redemption, because IP is the only identity that exists before authentication.**
That is not a compromise, it is the complete set of options.

- **Deliberately NOT keyed on the presented token**: keying a limiter on the credential being guessed
  hands the attacker a fresh bucket per guess — i.e. no limit at all.
- **Deliberately NOT a global bucket**: in a single-owner app a shared limit is a self-DoS lever. Any
  attacker exhausts it and locks the real phone out for a minute, buying nothing against an
  unsearchable token space (proposal D9).
- Mirrors `api-login`'s *second* limit exactly (`AppServiceProvider.php:70-72`) and reuses
  `throttled()` (`:81-89`), so the `429` body and `Retry-After` header match the shipped contract with
  zero new code.
- The mint limiter's `?? $request->ip()` fallback is defensive: `user()` is never null inside the
  `auth` group, but a `null` key silently makes a limiter global.

### Web endpoints — `app/Http/Controllers/Settings/MobileTokenQrController.php`

Registered inside the `auth` group of `routes/web.php:27`, next to the existing pair at `:48-49`.
They do **not** start with `api/`, so `ApiContractTest` correctly ignores them
(`tests/Feature/ApiContractTest.php:15`).

| Route | Method | Middleware | Body |
|---|---|---|---|
| `settings/mobile-token/qr` | `store` | `auth`, `throttle:qr-login-mint` | `{state:'live', payload, expires_at}` **or** `{state:'consumed', consumed_at, consumed_ip}` |
| `settings/mobile-token/qr/status` | `status` | `auth` | `{state:'live'\|'consumed'\|'expired'\|'none', …}` — **never `payload`** |

```ts
// resources/js/types/index.ts
export type QrPassMint =
    | { state: 'live'; payload: string; expires_at: string }
    | { state: 'consumed'; consumed_at: string; consumed_ip: string | null };

export type QrPassStatus =
    | { state: 'live'; expires_at: string }              // note: no `payload`
    | { state: 'consumed'; consumed_at: string; consumed_ip: string | null }
    | { state: 'expired' }
    | { state: 'none' };
```

**The asymmetry between those two types *is* the security property**: the plaintext exists in exactly
one response, from exactly one endpoint, exactly once. Nothing can re-fetch it. Both are plain JSON,
never Inertia props — props are serialized into `window.history.state` and would survive
back-navigation (proposal D6). `expires_at` is server-anchored ISO-8601, so the countdown is not
`Date.now() + 60`; a skewed browser clock renders a wrong countdown but can never make a dead pass
redeemable or a live one unredeemable, because the server owns the `expires_at > now()` predicate.

---

## The Web Page

`resources/js/components/settings/qr-login-card.tsx` (new), mounted by
`resources/js/pages/settings/mobile-token.tsx` immediately **above** the revoke card (`:62-135`), so
the theft banner renders directly over the button it wants you to press (proposal D5).

**Default state is a single `<Button variant="outline">Mostrar código QR</Button>`.** No mint, no
poll, no `qrcode` import work until it is clicked. That is what keeps the shipped
`tests/Browser/MobileTokenRevokeFlowTest.php` green with zero edits — it never sees a changed default
page, and a drive-by visit or a prefetch never mints a credential.

```
 idle ──click──► minting ──{state:'live'}──► live ──poll: live & T-10s──► minting (QR stays up)
                    │                          │
                    │                          ├── poll: consumed ──► consumed  (terminal, poll stops)
                    └──{state:'consumed'}──────┤
                                               └── T ≤ 0, re-mint failed ─► expired (terminal)
```

**The re-mint is poll-driven, not timer-driven.** The card re-mints only in reaction to a `status`
response that is still `live` and within 10 s of expiry — so the very request that would reveal a
consumption is the one that gates the re-mint. The residual sub-second window is then closed
server-side by Decision 3: mint takes `lockForUpdate()` on the row a redemption would claim, so
either the consume wins the lock and mint reports `consumed` (banner shown, nothing written), or mint
wins and the racing consume finds `affected = 0` and gets the uniform 422 — the accepted "escaneá el
nuevo" friction from proposal D3. Both outcomes are safe; neither loses the signal.

**Plaintext handling — the load-bearing rule.** `qrcode` renders client-side, so the payload must
reach the browser once and never be re-fetched:

| Rule | Enforcement |
|---|---|
| Arrives once, in the mint response | `useHttp().post()` (`@inertiajs/react` v3), response held in `useState<string>` |
| Never a `rememberKey` | `useHttp` is called with **no** rememberKey — the `rememberKey` overload (`node_modules/@inertiajs/react/types/useHttp.d.ts:62`) is precisely the history-persistence path we must not take |
| Never an Inertia prop | Mint is plain JSON (D6). `MobileTokenController::show` is unchanged. |
| Never re-fetchable | `…/qr/status` returns state only. A refresh, a back-nav, or a devtools replay yields nothing; the user clicks the button again and mints a *new* pass. |
| Never in the DOM as text | `QRCode.toString(payload, {type:'svg'})` emits `<path d="…">` from a bit matrix. The string never becomes a text node or an attribute. |
| Dies with the component | Unmount drops the `useState`. No storage, no cookie, no URL. |

Rendering: `dangerouslySetInnerHTML` into a `[data-testid="qr-code"]` wrapper. Injection is bounded
twice over — the payload is server-generated from `random_bytes` and matches
`/^pposter_qr_v1:[A-Za-z0-9_-]{43}$/`, and `qrcode`'s SVG serializer emits geometry only, never the
input string.

Polling: `setInterval(3_000)` calling `useHttp().get(statusUrl)`, cleared on unmount and on any
terminal state. **Not** Inertia's `usePoll` — that issues a partial *visit*, which re-renders the page
from props and would destroy the payload held in component state.

Shim, `resources/js/types/qrcode.d.ts` (Decision 5):

```ts
declare module 'qrcode' {
    export function toString(
        text: string,
        options?: {
            type?: 'svg';
            margin?: number;
            errorCorrectionLevel?: 'L' | 'M' | 'Q' | 'H';
        },
    ): Promise<string>;
}
```

Ambient module declaration in a non-module file, so `isolatedModules` (`tsconfig.json:77`) is
satisfied and `npm run types:check` (`package.json:13`) passes with `qrcode` as the only new package.

Copy is Spanish/voseo, matching `mobile-token.tsx:54-59`: "Mostrar código QR", "El código expira en
{n} s", "Se inició sesión en un teléfono a las HH:MM desde {ip}", "El código expiró. Escaneá el
nuevo."

---

## The `ApiContractTest` Exemption

`tests/Feature/ApiContractTest.php:69-86` hard-codes one exemption:

```php
if ($key === 'POST api/v1/login') {
    continue;
}
```

The edit:

```php
// Unauthenticated token-issuing operations declare no security; every
// other operation must declare bearerAuth. See the `api-auth` spec.
$unauthenticatedTokenIssuers = ['POST api/v1/login', 'POST api/v1/qr-login'];

if (in_array($key, $unauthenticatedTokenIssuers, true)) {
    continue;
}
```

plus renaming the test from `…except login declares bearer auth` to
`…except the unauthenticated token issuers declares bearer auth`.

**This modifies a shipped, passing test. What protects it, strongest first:**

1. **It stays a whitelist, never a predicate.** The tempting weakening — "operations with no
   `security` key are fine" — would silently exempt every future endpoint someone forgets to secure.
   We name the two exempt operations literally, so a *third* unsecured operation still fails. The
   blast radius of this edit is exactly one array element.
2. **The other contract test is untouched** (`ApiContractTest.php:52-67`). Set-equality in both
   directions still holds, so no exemption can hide an orphaned document entry or an undocumented
   route.
3. **The exemption stops being folklore.** The `api-auth` delta promotes it to spec text
   (*"unauthenticated token-issuing operations declare no security; every other operation declares
   `bearerAuth`"*), so the list has a written owner and a reviewer sees the spec delta and the test
   edit in the same commit.
4. **Strict TDD makes the edit observable, not assumed.** Commit 1 lands the route and the OpenAPI
   operation *first*, watches this exact assertion go red, and only then grows the list. A red run is
   the proof the assertion was live before it was touched — an edit to a test that was already
   passing vacuously would be invisible.

---

## File Changes

| File | Action | Description |
|---|---|---|
| `database/migrations/*_create_qr_login_passes_table.php` | Create | Table + rollback per Interfaces |
| `app/Models/QrLoginPass.php` | Create | Casts, `user()` belongsTo |
| `database/factories/QrLoginPassFactory.php` | Create | States `expired()`, `consumed()` |
| `app/Models/User.php` | Modify | `qrLoginPasses(): HasMany` |
| `app/Http/Controllers/Api/V1/Concerns/IssuesMobileToken.php` | Create | The three shared lines |
| `app/Http/Controllers/Api/V1/AuthController.php` | Modify | `login()` delegates to the trait |
| `app/Http/Requests/Api/V1/QrLoginRequest.php` | Create | Regex rule + uniform Spanish message |
| `app/Http/Controllers/Api/V1/QrLoginController.php` | Create | Atomic consume, `reject()`, docblock explaining the shape |
| `app/Http/Controllers/Settings/MobileTokenQrController.php` | Create | `store` (mint, `lockForUpdate`) + `status` |
| `app/Providers/AppServiceProvider.php` | Modify | +2 limiters in `configureRateLimiting()` (`:64-74`) |
| `routes/api.php` | Modify | +1 unauthenticated route near `:11-13` |
| `routes/web.php` | Modify | +2 routes near `:48-49`; correct the `:44-47` comment (a *pass* is not a token) |
| `openapi/v1.json` | Modify | +1 operation `redeemQrLoginPass`, tag `Auth`, **no `security` key**; +2 schemas |
| `tests/Feature/ApiContractTest.php` | Modify | Exemption list at `:76` |
| `resources/js/types/qrcode.d.ts` | Create | 8-line ambient shim |
| `resources/js/types/index.ts` | Modify | `QrPassMint`, `QrPassStatus` |
| `resources/js/components/settings/qr-login-card.tsx` | Create | Gated mint, SVG, countdown, poll, terminal states |
| `resources/js/pages/settings/mobile-token.tsx` | Modify | Mount the card; correct the docblock (`:26-33`) |
| `package.json` | Modify | `qrcode` **only** |
| `tests/Feature/ApiQrLoginTest.php` | Create | Redemption, uniformity, atomicity shape, throttle |
| `tests/Feature/Settings/MobileTokenQrTest.php` | Create | Mint auth gate, D3 replacement, status states |
| `tests/Browser/QrLoginFlowTest.php` | Create | Render, countdown, consumed flip, no JS errors |
| `resources/js/components/sidebar/sidebar-user-menu.tsx` | **Unchanged — enforced** | No new nav entry (proposal D5) |
| `app/Enums/TokenName.php`, `bootstrap/app.php`, `MobileTokenController.php` | **Unchanged** | No new token name, no new error render, revoke reused as-is |

---

## Testing Strategy

Strict TDD (`openspec/config.yaml:18`): RED before code, inside each commit.

| Layer | What to test | Approach |
|---|---|---|
| Feature (API) | Valid pass → `200`, token `name === 'mobile'`, `abilities === ['mobile']` | Same expectations as the shipped credentials-login test — copied, not paraphrased |
| Feature (API) | **Atomicity shape**: first `qr_login_passes` statement is an `update`, exactly one `update`, zero preceding `select` | `DB::listen` (Flow B). The only guard that survives a refactor |
| Feature (API) | Pre-consumed row → `affected = 0` → `422`; double redeem → `200` then `422`; exactly one `mobile` token after | Factory state `consumed()` |
| Feature (API) | **Uniformity**: unknown / expired / consumed / malformed → `assertExactJson` against one shared body | Parametrised `dataset`, one expectation for four inputs |
| Feature (API) | Revocation semantics: prior `mobile` dies, `mcp` survives | Mirrors the shipped single-active-token test |
| Feature (API) | `expires_at` is exactly 60 s after `created_at`; a 61 s-old pass is refused | `travel()` |
| Feature (API) | 11th redemption from one IP in a minute → `429` + `Retry-After` + Spanish body | Mirrors the shipped `api-login` throttle test |
| Feature (web) | Guest → `302` to login **and no row written** | `assertDatabaseCount('qr_login_passes', 0)` |
| Feature (web) | Mint replaces an unconsumed pass (D3); mint over a *consumed* pass writes nothing and returns `consumed` (D3 race) | Two tests; the second is the one that would silently regress |
| Feature (web) | `status` never returns `payload` in any state | `assertJsonMissingPath('payload')` |
| Contract | 11 documented operations; `qr-login` has no `security`; every other has `bearerAuth` | Existing `ApiContractTest`, exemption grown |
| Browser | Click → `<svg>` in `[data-testid="qr-code"]`, countdown copy, `assertNoJavascriptErrors()` | Follows `MobileTokenRevokeFlowTest.php:19-33` |
| Browser | `QrLoginPass::first()->update(['consumed_at' => now()])` from PHP → card flips within one poll cycle, QR gone | Proves the observability requirement E2E without ever putting a live credential in the DOM |
| Static | `npm run types:check` green with the shim and no `@types/qrcode` | `package.json:13` |

**Known browser gotchas to reuse**: click the dialog's confirm **by selector**, never by shared button
text (`MobileTokenRevokeFlowTest.php:42-47`); `flushSession()` + `forgetGuards()` before asserting a
bearer token is dead (`:58-59`).

**Explicitly out of reach**: true concurrency is not reproducible in Pest. The `DB::listen` shape
assertion is the compensating control, and Flow B is the written justification for why a behavioural
test would not have caught it.

---

## Threat Matrix

`N/A` — this change introduces no shell command, subprocess, VCS/PR automation,
executable-file-classification, or process-integration boundary. Every row of
`references/threat-matrix.md` (documentation-like paths, git repository selection, commit state, push
state, PR commands) is `N/A: this change adds HTTP routes and a database table only`. The
adversarial surface here is the credential lifecycle, and it is covered above by Flow A/B, the
uniform-failure contract, and the limiter — each with a named RED test in the table above.

---

## Migration / Rollout

One forward migration, no data backfill, no feature flag, no existing table altered.

**Commit boundaries — ~890 authored lines, one PR, three commits, each green.**
`size:exception` is pre-authorized (proposal Delivery Forecast). Chaining is declined for a stated
reason, not for convenience: a chain's PR #1 would merge an **unauthenticated, token-issuing endpoint
into `main` with no producer and no governing UI**. Never ship a redemption oracle ahead of the
surface that mints and observes it.

| # | Scope | ~Lines | Why it is atomic | Green at end |
|---|---|---|---|---|
| 1 | Migration, model + factory, `IssuesMobileToken` + `AuthController` delegation, `QrLoginRequest`, `Api/V1/QrLoginController`, `api-qr-redeem` limiter, `routes/api.php`, `openapi/v1.json` operation + schemas, `ApiContractTest` exemption, `ApiQrLoginTest` | ~430 | **Cannot be split by layer.** `ApiContractTest` asserts route↔document set-equality in *both* directions (`:52-67`): a route without its operation is red, an operation without its route is red, and a new unauthenticated operation is red until the exemption grows. Route + document + exemption are one commit by construction, not by preference. | `php artisan test --compact` |
| 2 | `Settings\MobileTokenQrController` (store + status), `qr-login-mint` limiter, 2 web routes, `MobileTokenQrTest` | ~180 | The web routes do not start with `api/`, so the contract test ignores them; the slice is independently green. | `php artisan test --compact` |
| 3 | `qrcode` + shim, `qr-login-card.tsx`, `mobile-token.tsx` mount + docblock (`:26-33`), `routes/web.php:44-47` comment, `QrLoginFlowTest` | ~280 | UI only; requires both endpoints to exist. | `php artisan test --compact`, `npm run build`, `npm run types:check` |

`vendor/bin/pint --dirty --format agent` before every PHP commit.

**Rollback.** Emergency stop: delete the route from `routes/api.php` **and** its operation from
`openapi/v1.json` — two edits, not one, or `ApiContractTest` goes red. Every outstanding pass becomes
unredeemable instantly. Full revert: revert the merge commit (route and document leave together, so
the contract test re-greens by construction), **then** `php artisan migrate:rollback --step=1` — in
that order. QR-obtained Sanctum tokens survive a revert and keep authenticating; that is correct, not
a bug (Decision 6 makes them indistinguishable from credentials-minted ones). If the revert is a
security response, revoke from `settings/mobile-token` *first*, then revert.

---

## Open Questions

- [ ] None blocking. The proposal's six questions carry stated defaults, and the two orchestrator
      resolutions (no `@types/qrcode`; QR login revokes `mobile` and never `mcp`) are designed in
      above at Decision 5 and `IssuesMobileToken` respectively.
- [ ] Deferred, non-blocking: a `Prunable` sweep for consumed rows. Decision 3 retains them as the
      audit trail, so growth is one row per successful QR login — irrelevant at single-owner scale,
      and a cheap follow-up if it ever is not.
