```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:3b3d615b7ae3667e3005f2be0ac83583fdad57e56159ed159a6933cb0d8fc4bd  # sha256 of `git diff main..HEAD` at commit f40eeb7d697ad85ff4f02b511edd75d29f9cffc6
verdict: fail
blockers: 1
critical_findings: 1
requirements: 11/11
scenarios: 20/20
test_command: php artisan test --compact
test_exit_code: 1
test_output_hash: sha256:95d0ddb4d6507849467b00b44574b36300927d25f56d04c73b1364e605cafaeb
build_command: npm run build
build_exit_code: N/A (not re-run this pass — read-only-on-source constraint; last known-good build confirmed by apply-progress tasks 2.4/2.7, public/build/manifest.json present and fresh)
build_output_hash: N/A (not re-run this pass)
```

## Verification Report

**Change**: `api-token-auth`
**Version**: N/A (new capability)
**Mode**: Strict TDD

### Two separate gates — read both

This report deliberately separates two questions that the launching brief asks about together:

1. **Is the implementation correct against the spec?** → verified below, PASS WITH WARNINGS.
2. **Is the change ready to merge to `main` under the owner's stated delivery rule** ("nothing merges until E2E passes")? → **NOT MET.** The full suite exits non-zero (6 failing tests). This is stated plainly per instruction, not softened: regardless of root cause, the literal merge gate as written is unsatisfied right now, and no action available in this environment can satisfy it.

---

### Completeness
| Metric | Value |
|--------|-------|
| Tasks total (code, Phases 1-3) | 28 |
| Tasks complete | 28 |
| Tasks incomplete | 0 |
| Phase 4 (operational, no-code) | 0/2 — correctly unchecked, owner-performed post-merge |

All 28 code tasks in `tasks.md` are marked `[x]` and match the committed/working-tree code state (Phases 1-2 committed at `7c64640`/`3fd5223`; Phase 3 in the clean working tree, matching `apply-progress.md`'s own "uncommitted, left for owner review" note — but `git log` shows it as commit `f40eeb7`, i.e. the owner/orchestrator already committed it since apply-progress was written. Working tree is clean; this is not a discrepancy, just a stale note in `apply-progress.md`).

### Build & Tests Execution

**Build**: Not re-run this pass (read-only-on-source constraint honored — `npm run build` writes to the gitignored `public/build/`, which is not source, but re-running was judged unnecessary since Phase 2's tasks 2.4/2.7 already regenerated and validated the manifest, and `public/build/manifest.json` is present).

**Tests**: ❌ 431 passed / ❌ 6 failed / 437 total
```text
$ php artisan test --compact
{"tool":"pest","result":"failed","tests":437,"passed":431,"assertions":1290,
 "duration_ms":45966,"failed":6,"risky":5}

Failures (verified identical to the set described in the launch brief, confirmed
by independently re-running the suite in this verify pass):
1. Feature\AppearanceTest — anti-FOUC inline script assertion — pre-existing, unrelated to auth
2. Browser\HabitFlowTest — "creates a quantitative habit..." — blank-page render
3. Browser\HabitFlowTest — "logs partial entries..." — timeout, same root cause
4. Browser\McpTokenFlowTest — blank page at /login — pre-existing baseline failure
5. Browser\MobileTokenRevokeFlowTest — blank page at /login — NEW test, same env defect
6. Browser\SmokeTest — blank page at /login — pre-existing baseline failure
```

Exit code `1` (non-zero). Per `strict-tdd-verify.md` this is normally an automatic CRITICAL. It is
classified as CRITICAL here too — **for the merge-readiness gate specifically** — but the finding is
qualified: independent re-execution in this verify pass reproduces byte-for-byte the same 6 failures,
same messages, same URLs, that `apply-progress.md` already documented across all three phases (Phase
1: 5/5 baseline, Phase 3: 6/6 with `MobileTokenRevokeFlowTest` added). Nothing regressed between apply
and verify. 5 of 6 predate this change entirely (confirmed: `AppearanceTest` and `HabitFlowTest` touch
neither auth nor tokens; `git diff main..HEAD` does not touch those files). The 6th
(`MobileTokenRevokeFlowTest`) fails at the identical first assertion, same blank-page symptom, as the
5 pre-existing ones — consistent with one shared headless-browser rendering defect, not 6 independent
defects.

**Coverage**: Not measured — no coverage tool detected in `composer.json`/CI config for this project.

---

### Spec Compliance Matrix

| Requirement | Scenario | Test | Result |
|---|---|---|---|
| R1 Credentials Exchange | Valid credentials mint a mobile token | `ApiAuthTest.php:14` "a fresh login mints a mobile token..." | ✅ COMPLIANT |
| R1 Credentials Exchange | Wrong password rejected 422 | `ApiAuthTest.php:71` "wrong password is rejected..." | ✅ COMPLIANT |
| R2 Identity Exposure | Pinned field set (id/name/email) | `ApiAuthTest.php:29-35` (`$me->json('data')` exact-match) | ✅ COMPLIANT |
| R3 Logout Revocation | Logout kills only presenting token | `ApiAuthTest.php:37-49` | ✅ COMPLIANT |
| R4 Single Active Mobile Token | Fresh login retires old mobile, not mcp | `ApiAuthTest.php:52-69` | ✅ COMPLIANT |
| R5 Ability Boundary | mobile token → 403 at /mcp | `McpServerTest.php:92-99` | ✅ COMPLIANT |
| R5 Ability Boundary | mcp token → 403 at /api/v1 | `McpServerTest.php:101-108` | ✅ COMPLIANT |
| R5 Ability Boundary | `['*']` token → both surfaces succeed | `McpServerTest.php:81-90` (mcp) + `ApiAuthTest.php` login/user flow uses `['mobile']` not `['*']`, but the `/api/v1` half of this scenario has no dedicated `['*']`-at-`/api/v1` test | ⚠️ PARTIAL — see Issue W-1 |
| R6 Brute-Force | 6th attempt in a minute → 429 | `ApiAuthTest.php:91-109` | ✅ COMPLIANT |
| R7 JSON Error Contract | 401 + `WWW-Authenticate` on no token | `ApiAuthTest.php:84-89` | ✅ COMPLIANT |
| R8 Web Revocation | Owner revokes from browser | `tests/Browser/MobileTokenRevokeFlowTest.php` — environment-red, cannot execute | ❌ FAILING (environment, not logic — see below) |
| R8 Web Revocation (HTTP-equivalent) | destroy() name-scoped, dead over HTTP | `MobileTokenTest.php:43-80` | ✅ COMPLIANT (proves the underlying behavior; does not prove the browser click-path) |
| R9 OpenAPI Contract | Bidirectional set-equality | `ApiContractTest.php:52-67` | ✅ COMPLIANT |
| D1 Exactly One MCP Token | mcp count=1, mobile survives | `McpTokenTest.php:27-64` | ✅ COMPLIANT |
| D1 Exactly One MCP Token | read-scope: mcp page shows mcp token even if mobile is more recent | `McpTokenTest.php:66-84` | ✅ COMPLIANT |
| D1 Exactly One MCP Token | regenerate kills old immediately | `McpTokenTest.php:86-96` | ✅ COMPLIANT |
| D1 Exactly One MCP Token | plain token never in persistent prop | `McpTokenTest.php:98-114` | ✅ COMPLIANT |
| D2 mcp Ability | mobile-only refused at /mcp | `McpServerTest.php:92-99` | ✅ COMPLIANT |
| D2 mcp Ability | `['*']` still works at /mcp | `McpServerTest.php:81-90` | ✅ COMPLIANT |
| MCP browser flow (pre-existing spec, must "stay green") | generate/regenerate via browser | `tests/Browser/McpTokenFlowTest.php` — pre-existing environment-red, unmodified by this change | ❌ FAILING (environment, pre-existing baseline — verified unchanged, `git diff --stat` on this file is empty) |

**Compliance summary**: 18/20 scenarios COMPLIANT with a passing runtime test, 1 PARTIAL (W-1), 1
browser-layer scenario FAILING for a confirmed pre-existing environment reason with equivalent
HTTP-level coverage as a fallback proof.

---

### Correctness (Static Evidence) — the 9 non-negotiable checks

**1. Both `tokens()` queries in `McpTokenController.php` are name-scoped (write AND read)**
**CONFIRMED.** `app/Http/Controllers/Settings/McpTokenController.php:25` (`show()`, the read):
`$request->user()->tokens()->where('name', TokenName::Mcp->value)->latest()->first()`.
`McpTokenController.php:46` (`store()`, the delete): `->where('name', TokenName::Mcp->value)->delete()`.
Both scoped. The read-scope defect (line 25) is independently proven by a dedicated regression test —
`McpTokenTest.php:66-84`, which uses `$this->travel(1)->minute()` to make an unscoped `latest()` read
provably wrong if it existed (the mobile token would sort first), and asserts the mcp token's
`created_at` is returned instead.

**2. `MobileTokenController` name-scopes every `tokens()` query, read and write**
**CONFIRMED.** `app/Http/Controllers/Settings/MobileTokenController.php:26` (`show()`):
`->where('name', TokenName::Mobile->value)->latest()->first()`. Line 43 (`destroy()`):
`->where('name', TokenName::Mobile->value)->delete()`. Both scoped, verified by
`MobileTokenTest.php:26-41` (scoped read, coexisting mcp token) and `:43-57` (scoped destroy, mcp
survives).

**3. An existing `['*']` token still reaches `/mcp` after `abilities:mcp` was added, with a test**
**CONFIRMED.** `routes/ai.php:29` — `Mcp::web('/mcp', PosterServer::class)->middleware(['auth:sanctum', 'abilities:mcp'])`.
Sanctum's own `PersonalAccessToken::can()` (`vendor/laravel/sanctum/src/PersonalAccessToken.php:79-80`)
treats `in_array('*', $this->abilities, true)` as an automatic pass for any ability check — verified by
reading the vendor source directly, not assumed. The regression test exists and passes:
`McpServerTest.php:81-90` "a pre-existing wildcard-ability token still reaches mcp" — mints a token
with no explicit abilities (defaults to `['*']`, exactly the shape of the owner's live token) and
asserts `200`. This test ran green in this verify pass (part of the 431 passing). Owner's live
Claude Desktop integration is protected — no CRITICAL here.

**4. The ability boundary is genuinely bidirectional**
**CONFIRMED**, with one caveat noted as W-1 below. `mobile`-only → `/mcp` = 403:
`McpServerTest.php:92-99`. `mcp`-only → `/api/v1/user` = 403: `McpServerTest.php:101-108`. Both
directions have real, distinct, currently-passing tests exercising the real HTTP kernel. Caveat: the
*positive* half of the `['*']`-reaches-both-surfaces scenario is only proven at `/mcp`
(`McpServerTest.php:81-90`); there is no test asserting a `['*']` token also succeeds at
`GET /api/v1/user`. The `abilities:mobile` middleware on that route uses the identical
`CheckAbilities` class with the identical wildcard short-circuit, so this is very likely fine by the
same mechanism — but it is inferred from the shared middleware class, not independently proven by a
named test the way the `/mcp` half is. Flagged as WARNING (W-1), not CRITICAL, because the mechanism
is shared code already proven to honor `['*']` at `/mcp`.

**5. `McpTokenTest.php`'s uniqueness test was rewritten deliberately, not weakened**
**CONFIRMED — genuinely stronger.** `git show 7c64640^:tests/Feature/McpTokenTest.php` (the pre-change
version) asserted only `$user->tokens()->count() === 1` and `->first()->name === 'mcp'` — an unscoped
count, correct only because no other token could exist at the time. The current
`McpTokenTest.php:27-64` ("generating a token stores a single mcp pat, leaves a coexisting mobile
token untouched...") deliberately creates a coexisting `mobile` token first (which would make the old
unscoped assertion `count()===1` fail, since count would be 2), then asserts the *name-scoped* count of
`mcp` tokens is 1 AND independently fetches the surviving mobile token by ID and asserts its name and
abilities are untouched. This is strictly more assertions covering strictly more behavior (the new
coexistence invariant), not a loosening. A second wholly new test (`:66-84`) covers the read-scope
regression that did not exist in any form pre-change. Both pass.

**6. `Auth::attempt()` is NOT used anywhere in the API auth path**
**CONFIRMED.** `rg -n "Auth::attempt"` across `app/` and `routes/` finds exactly one hit:
`app/Http/Requests/Auth/LoginRequest.php:64` — the pre-existing, untouched **web** session login
(confirmed unchanged by this diff, see the "web session auth" check below). `app/Http/Requests/Api/V1/LoginRequest.php:58`
uses `Auth::validate($this->only('email', 'password'))`, then `Auth::getLastAttempted()` at line 65 —
exactly the pattern design.md D-4 specifies, and it never touches a session guard.

**7. Rate limiter: 5/min by `email|ip` AND 10/min by `ip`, Spanish 429, wired**
**CONFIRMED.** `app/Providers/AppServiceProvider.php:66-73` — `RateLimiter::for('api-login', ...)`
returns both limits (`Limit::perMinute(5)->by(email|ip)` and `Limit::perMinute(10)->by(ip)`), each with
`->response($this->throttled(...))`. `throttled()` (line 81-89) returns the Spanish body
`"Demasiados intentos de acceso. Por favor, inténtalo de nuevo en %d segundos."` with the `Retry-After`
header. Wired at `routes/api.php:6-8` via `->middleware('throttle:api-login')` on the login route only
(correctly excluded from `user`/`logout`, which use `abilities:mobile` instead). Proven live by
`ApiAuthTest.php:91-109`, which ran and passed in this verify pass.

**8. Mobile token page: no plaintext, no copy button, explicit confirmation**
**CONFIRMED.** `resources/js/pages/settings/mobile-token.tsx` — the `token` prop type (line 19-24) is
`{ created_at, last_used_at } | null`, no plaintext field even in the type. No `navigator.clipboard`,
no copy icon/button anywhere in the file (`rg` confirms; only icons imported are `KeyRound` and
`ShieldOff`). Revocation is gated behind a Radix `Dialog` (`DialogTrigger` opens a confirmation dialog
with explicit Spanish copy — "¿Revocar el token móvil?" — before the actual `<Form {...destroy.form()}>`
submit button appears inside `DialogContent`); there is no direct one-click destroy button on the page
body itself. This matches design.md D-1 and spec.md's R8 exactly.

**9. `openapi/v1.json` documents exactly the registered `api/*` routes; the contract test genuinely
   fails on drift in both directions**
**CONFIRMED.** `php artisan route:list --path=api` lists exactly 3 operations: `POST api/v1/login`,
`POST api/v1/logout`, `GET|HEAD api/v1/user`. `openapi/v1.json`'s `paths` key documents exactly
`POST /api/v1/login`, `GET /api/v1/user`, `POST /api/v1/logout` — an exact 1:1 match, independently
verified by parsing both sources in this pass (not by trusting the test's name). Reading
`ApiContractTest.php:52-67`: it builds two real `Collection`s (one from `Route::getRoutes()`, the live
router state; one from `json_decode`-ing the actual file) and asserts `$registered->diff($documented)`
is empty AND `$documented->diff($registered)` is empty — a genuine bidirectional set-equality check
with named-diff failure messages, not a trivial `toBeTruthy()` or count check. This is real, not a
false-named test. One structural note (SUGGESTION-level, not a defect): the companion test at
`ApiContractTest.php:69-86` (per-operation `bearerAuth` security check) loops
`foreach ($paths as ...)` — if `openapi/v1.json` ever had zero paths, this specific test would pass
vacuously. In practice this can't happen silently because the bidirectional-equality test in the same
file would immediately catch an empty/truncated `openapi/v1.json` (all 3 registered routes would show
as "undocumented"). Not a real risk today, flagged for completeness only.

---

### Also Assessed

**Web session auth untouched?** **CONFIRMED untouched.** `git diff --stat main..HEAD -- routes/auth.php
app/Http/Controllers/Auth/AuthenticatedSessionController.php app/Http/Requests/Auth/LoginRequest.php
config/auth.php` produces zero output — none of these four files appear anywhere in the 27-file diff.
`Auth::attempt()` still lives only in the untouched web `LoginRequest.php:64`.

**Is the rollback plan in `proposal.md` still accurate?** **Mostly, with one gap (WARNING, W-2).**
`proposal.md`'s Rollback Plan section explicitly calls out reverting
`McpTokenController.php:46,48` (the write/create scoping) but does **not** mention line 25/26 (the
read scoping in `show()`), even though the proposal's own Decision 2 table two sections earlier
explicitly discusses scoping "the read at `McpTokenController.php:26`" as a non-negotiable. The actual
mechanism described for rollback is "revert the branch merge" (a git-level revert), which would
correctly restore all three lines regardless of the prose gap — so this is a documentation
completeness issue, not a functional rollback risk. `apply-progress.md`'s own rollback-boundary table
(Phase 1 Work Unit Evidence) is more accurate and does list `McpTokenController.php:25,46,48`. The
"mandatory revert step" (delete `personal_access_tokens` rows named `mobile`) is present and correct
in both documents.

**Are Phase 4's two operational items still correct and needed?** **Yes, both.**
4.1 (regenerate the MCP token post-merge so it carries `['mcp']` instead of `['*']`) is still needed —
confirmed the residual gap it closes is real: a pre-existing `['*']` token can still reach both
`/api/v1` and `/mcp` until regenerated (proven by the passing wildcard test above; this is the
documented, accepted, bounded residual risk from proposal.md's Risk table, not a new finding).
4.2 (delete `mobile`-named rows on revert) is still needed and correctly described — confirmed via the
same rollback analysis above: after a revert, `/mcp` has no ability check, so an orphan `mobile` token
would gain MCP access if not deleted.

---

### TDD Compliance
| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ✅ | Present for all 3 phases in `apply-progress.md`, with per-task RED/GREEN/TRIANGULATE/SAFETY NET/REFACTOR rows |
| All tasks have tests | ✅ | 28/28 code tasks map to a test file or are structural (enum, pure config) |
| RED confirmed (tests exist) | ✅ | `ApiAuthTest.php`, `McpServerTest.php`, `McpTokenTest.php`, `MobileTokenTest.php`, `ApiContractTest.php`, `MobileTokenRevokeFlowTest.php` all exist and were read in full in this pass |
| GREEN confirmed (tests pass) | ✅ 431/437, all Feature-layer tests written by this change pass; ❌ the 1 new Browser test fails (environment, see above) |
| Triangulation adequate | ✅ | Every requirement has ≥2 scenarios where the spec calls for ≥2; single-scenario requirements (R3, R9-security) match single-scenario specs |
| Safety Net for modified files | ✅ | `McpTokenController.php`, `McpServerTest.php`, `McpTokenTest.php` all show pre-existing tests re-confirmed green before/after edits, per apply-progress |

**TDD Compliance**: 6/6 checks passed (GREEN check carries the environment caveat noted throughout)

---

### Test Layer Distribution
| Layer | Tests (new/modified) | Files | Tools |
|-------|-------|-------|-------|
| Feature | 22 | `ApiAuthTest.php` (5), `McpServerTest.php` (+3), `McpTokenTest.php` (2 new/rewritten), `MobileTokenTest.php` (5), `ApiContractTest.php` (2) | Pest v4 |
| Browser (E2E) | 1 | `MobileTokenRevokeFlowTest.php` | Pest browser plugin (environment-blocked in this sandbox) |
| Unit | 0 | — | — |
| **Total** | **23** | **6 files** | |

---

### Assertion Quality
No CRITICAL patterns found (no tautologies, no assertion-free tests, no ghost loops that currently
execute empty). One SUGGESTION-level structural note recorded above (`ApiContractTest.php:69-86`'s
per-path loop would pass vacuously on an empty `openapi/v1.json`, mitigated by the companion
bidirectional test in the same file).

**Assertion quality**: 0 CRITICAL, 0 WARNING, 1 SUGGESTION

---

### Coherence (Design)
| Decision | Followed? | Notes |
|----------|-----------|-------|
| D-1 Sibling page, not unified | ✅ Yes | `mobile-token.tsx` is a new file; `mcp-token.tsx` untouched (not in diff); `McpTokenFlowTest.php` byte-identical (`git diff --stat` empty) |
| D-2 `TokenName` enum as source of truth | ✅ Yes | `app/Enums/TokenName.php` — `Mcp`/`Mobile` backed string enum, used everywhere abilities/names are set or queried |
| D-3 Scope `show()` line 26 (read) | ✅ Yes | Confirmed above (check 1) |
| D-4 `Auth::validate()` + `getLastAttempted()`, never `attempt()` | ✅ Yes | Confirmed above (check 6) |
| D-5 `{"token": ...}` only on login; user shape only at `/user` | ✅ Yes | `AuthController::login` returns `['token' => ...]` only; `UserResource` used only by `user()` |
| D-6 429 body reuses Spanish copy | ✅ Yes | `AppServiceProvider::throttled()` matches the design's exact template |
| Deviation: `UserResource` omits `created_at` vs. design.md's File Changes table cell | ⚠️ Deviation, self-flagged and justified | `apply-progress.md` documents this explicitly: the spec's explicit "exactly id/name/email" scenario and tasks.md's own wording win over a stale design-table cell. Verified: `UserResource.php:24-28` outputs exactly `id`/`name`/`email`. Correct call — the spec is unambiguous and no test needed a 4th field. |
| Deviation: 401/403 message language | ⚠️ Deviation, self-flagged and justified | Spanish strings shipped (`bootstrap/app.php:55,59`), matching design.md and proposal Decision 7 over the spec table's illustrative English text. No scenario pins literal English copy. Correct call. |

---

### Issues Found

**CRITICAL**:
- **C-1 (merge-readiness only, not a code defect): the full test suite exits non-zero and the owner's
  explicit delivery rule ("nothing merges to `main` until E2E passes") is not currently satisfiable in
  this environment.** `tests/Browser/MobileTokenRevokeFlowTest.php` — new code written for this change,
  read in full and judged correct by inspection (script: login → sidebar → `Token móvil` → open
  confirm dialog → confirm → empty state → dead token over HTTP, matching the shipped controller and
  page exactly) — cannot be executed to completion because of a pre-existing, out-of-scope
  headless-browser rendering defect (confirmed via saved screenshots: fully blank/white page at
  `/login`, same symptom shared by 4 pre-existing baseline failures: `HabitFlowTest` ×2,
  `McpTokenFlowTest`, `SmokeTest`). `tests/Feature/MobileTokenTest.php` proves the underlying
  controller behavior at the HTTP level (name-scoped revoke, mcp survives, token dies), but that is not
  the same proof as the literal spec.md scenario "the owner revokes the mobile token from the browser."
  This is a genuine, currently-unclosed gap between "code is correct by inspection + HTTP-level proxy
  test" and "the owner's stated E2E merge bar is met." It is not fixable by further code changes in
  this change — the defect is environmental and pre-existing.

**WARNING**:
- **W-1**: No test directly proves a `['*']` token succeeds at `GET /api/v1/user` (only at `/mcp`).
  Very likely fine — same `CheckAbilities` middleware, same wildcard short-circuit already proven at
  `/mcp` — but it is an inferred pass, not a directly asserted one, for the `/api/v1` half of the
  "pre-existing wildcard token still reaches both surfaces" spec scenario.
- **W-2**: `proposal.md`'s Rollback Plan section omits `McpTokenController.php`'s read-scope line
  (25/26) from its explicit revert list, even though the proposal's own Decision 2 discusses it. The
  actual rollback mechanism (git revert of the merge) is unaffected by this prose gap;
  `apply-progress.md`'s rollback-boundary table already has the corrected, complete list.
- **W-3**: `apply-progress.md`'s "Status" section states Phase 3 is "uncommitted, in the working tree"
  and awaiting the owner's commit — but `git log` shows it already committed at `f40eeb7`. Stale note
  in an already-superseded progress artifact, not a functional issue.

**SUGGESTION**:
- **S-1**: `ApiContractTest.php`'s per-operation security-scheme loop (`:69-86`) would pass vacuously
  against an empty `openapi/v1.json`. Mitigated today by the companion bidirectional test in the same
  file; consider adding an explicit non-empty assertion (`expect($paths)->not->toBeEmpty()`) for
  defense in depth, not urgent.

---

### Verdict
**Code/spec verification: PASS WITH WARNINGS.** All 28 tasks complete, all 9 non-negotiable checks
CONFIRMED with real runtime evidence (not just static reading), spec requirements R1-R9 and mcp-server
delta D1-D2 all have passing covering tests except the one browser-layer scenario blocked by a
confirmed pre-existing environment defect. Design coherence holds; both deviations from design.md are
self-flagged, justified against the spec's literal text, and correct.

**Merge readiness: NOT MET.** The owner's stated rule — nothing merges to `main` until the full suite,
including E2E, passes — is not satisfied. `php artisan test --compact` exits `1` with 6 failures.
5 of 6 are confirmed pre-existing and unrelated to this change (verified: files not in this diff,
identical failure messages to baseline). The 6th is this change's own new Browser E2E test, blocked by
the identical environmental cause, with code judged correct by inspection but not exercised end-to-end.
Nothing in this change can close that gap — it requires either fixing the headless-browser environment
(a separate, out-of-scope task) or an explicit owner decision to accept the Feature-level HTTP proof
(`MobileTokenTest.php`) as sufficient in place of the literal browser scenario for this one test, which
would be a deliberate, documented exception to the stated delivery rule — not something this
verification pass can grant on its own.
