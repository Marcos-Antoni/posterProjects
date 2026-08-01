# Verification Report: api-issues + api-board-sprints + api-labels-comments

**Mode**: Full artifact verification (proposal, specs, design, tasks, apply-progress present for all three changes) + runtime test evidence + direct source/git inspection.
**Scope**: `/api/v1` surface, three chained changes on `feat/api-labels-comments` (chain: `main` → `feat/api-token-auth` → `feat/api-projects` → `feat/api-issues` → `feat/api-board-sprints` → `feat/api-labels-comments`).
**Test command**: `php artisan test --compact`

## Completeness

| Change | Tasks | Status |
|---|---|---|
| api-issues | 37/39 checked | 2 unchecked: `1.19`, `2.18` — both are literal "run `git commit`" steps. Substance (code+tests) is complete and verified per apply-progress. **Contradiction found**: apply-progress claims "no commit was made," but `git log` shows commit `ded6460` on `feat/api-issues` containing exactly the files these tasks describe. See Issue W1. |
| api-board-sprints | 30/30 checked | Complete. Commit `eb7d8d0` on `feat/api-board-sprints`. |
| api-labels-comments | 18/18 checked | Complete. Commit `60c7e12` on `feat/api-labels-comments`. |

## Test Evidence (executed by this verify pass)

`php artisan test --compact` → `{"tests":487,"passed":481,"failed":6}`

The 6 failures are byte-identical to the documented pre-existing environment defect (headless browser renders blank pages): `AppearanceTest`, `HabitFlowTest` (×2), `McpTokenFlowTest`, `SmokeTest`, `MobileTokenRevokeFlowTest`. None touch `/api/v1`. This count matches exactly what `api-labels-comments/apply-progress.md` claims as the final state (487/481/6), independently reproduced today.

`vendor/bin/pint --test --format agent` → `{"result":"passed"}`.

`php artisan route:list --path=api` → 10 routes, matches `openapi/v1.json`'s 10 documented operations (see Check 9).

## The 10 Numbered Checks

### 1. `key` accessor N+1 — CONFIRMED
- `app/Models/Issue.php:87-92` — `key` accessor reads `$this->project->key`, `$appends = ['key']` (line 66), so every serialization touches `project`.
- `app/Http/Controllers/Api/V1/IssueController.php:68` — `$issues->getCollection()->each(fn (Issue $issue) => $issue->setRelation('project', $resolvedProject));` applied to every paginator row.
- `IssueController.php:103-105` — `setRelation` applied to the detail issue, `parent?->setRelation(...)`, and `children->each(...)`.
- `tests/Feature/ApiIssuesTest.php:270-318` — `Model::preventLazyLoading()` enabled inside a `try/finally` local to this test only (not global). Query count asserted `toBe()` equal for 1 vs 5 issues (line 308), **paired with** value assertions on `assignee.name` and `labels[0].name` for all 5 rows (lines 311-314) — a count-only pass would not catch a dropped eager load returning `null`.

### 2. `->select('issues.*')` on the board-column join — CONFIRMED
- `IssueController.php:55` — `->select('issues.*')` present on the join query, with an explanatory docblock (`:29-33`) about the shared `id`/`project_id`/`position` column names.
- `tests/Feature/ApiIssuesTest.php:125-137` — fixture places the issue via `$project->boardColumns()->orderBy('position')->skip(2)->first()` (the **third** column, index 2) at `'position' => 0`; asserts the issue's own `id`/`position` survive via `issuePayload()`. A first-column fixture would not expose the bug (id/position of a first column and a first-column-scoped issue can coincidentally be low integers); this fixture specifically targets the collision the design flagged.

### 3. `->withQueryString()` on the issues paginator — CONFIRMED
- `IssueController.php:66` — `->paginate($validated['per_page'] ?? 50)->withQueryString()`.
- `tests/Feature/ApiIssuesTest.php:178-210` — crosses a page boundary (`per_page=1` over 2 filtered issues) **with** `?sprint={id}` applied; asserts `links.next` contains both `sprint={id}` and `page=2` (lines 201-202), then actually follows `links.next` and asserts page 2 returns the correct, still-filtered second issue (lines 204-209) — not just presence of the query string, but a live follow-through.

### 4. `ResolvesProjectByKey` extraction did not change `api-issues` behavior — CONFIRMED
- `git log --oneline --follow -- tests/Feature/ApiIssuesTest.php` → one commit only: `ded6460` (`feat/api-issues` tip), which added the file.
- `git diff feat/api-issues..feat/api-board-sprints -- tests/Feature/ApiIssuesTest.php` → empty.
- `git diff feat/api-board-sprints..feat/api-labels-comments -- tests/Feature/ApiIssuesTest.php` → empty.
- `git show eb7d8d0 -- app/Http/Controllers/Api/V1/IssueController.php` → the trait-extraction commit deletes only the private `resolveProject()` method and its docblock, adds `use ResolvesProjectByKey;`, drops the unused `Project` import. Zero edits at the `index()`/`show()` call sites.
- `app/Http/Controllers/Api/V1/Concerns/ResolvesProjectByKey.php` — body is `return $request->user()->projects()->where('projects.key', $projectKey)->firstOrFail();`, byte-identical to the deleted method (only visibility changed `private`→`protected`, required for trait use, not a behavior change).

### 5. Sprint `state` uses `today()` in app timezone (UTC), not the habits UTC-6 rule — CONFIRMED
- `config/app.php:68` — `'timezone' => 'UTC'`.
- `app/Http/Resources/SprintResource.php:39,47-51` — `$today = today();` then `match(true)` ordered future → completed → active (inclusive both bounds).
- `app/Http/Controllers/BoardController.php:82-86` — `resolveActiveSprint` uses the identical `today()` anchor and `lte($today) && gte($today)` semantics; `SprintResource`'s `match` is logically identical (future-first, then completed, else active-by-elimination).
- `tests/Feature/ApiSprintsTest.php` — relative fixtures throughout (`today()->addDays(N)`, `->subDays(N)`); state-matrix test (lines 68-112) freezes `Carbon::setTestNow(today()->addHours(12))` and a file-level `afterEach(fn () => Carbon::setTestNow())` (line 48) clears it — not clock-flaky. Active-pick parity test (from line 135) runs `BoardController::resolveActiveSprint` over the identical fixture and compares its pick against the resource's first `state:"active"` element, including two overlapping actives.

### 6. `issues_count` always from `withCount`, never per-row `->count()` — CONFIRMED
- `app/Http/Controllers/Api/V1/ProjectController.php:29,50`, `SprintController.php:30`, `LabelController.php:29` — all use `->withCount('issues')`.
- `app/Http/Resources/ProjectResource.php:32`, `SprintResource.php:52`, `LabelResource.php:31` — all read `$this->issues_count` (the aggregate alias), never call `->issues()->count()`.
- `tests/Feature/ApiLabelsTest.php:82-94` — zero-issue label test asserts `expect($response->json('data.0.issues_count'))->toBe(0);` with an explicit comment (line 91) stating why `toBeEmpty()`/`toBeFalsy()`/`==` are rejected (all three are satisfied by `null`).

### 7. Label ordering fixtures use lowercase, non-alphabetically-inserted names — CONFIRMED
- `tests/Feature/ApiLabelsTest.php:53-80` — labels created in insertion order `frontend, urgent, backend, bug` (all lowercase, non-alphabetical), asserts the response is ascending-`name`-ordered `backend, bug, frontend, urgent`, identically across two consecutive calls. This proves `ORDER BY` — not insertion order and not an accidental collation pass (the code comment at lines 58-61 explicitly calls out SQLite's BINARY collation risk with mixed-case/alphabetical fixtures).

### 8. 404 non-disclosure consistent across all three changes, all cases asserted — CONFIRMED for board-sprints and labels; PARTIAL for issues' list endpoint
- `api-board-sprints`: `ApiBoardColumnsTest.php:106-122` and `ApiSprintsTest.php:193-209` each assert all three cases (unknown key, non-member, archived) in one dedicated test per endpoint. Matches the change's own spec requirement ("All three not-found cases are indistinguishable").
- `api-labels-comments`: `ApiLabelsTest.php:173-189` asserts all three cases in one test. Matches its spec requirement identically.
- `api-issues`: the **detail** endpoint tests all three plus 4 more malformed-key variants (`ApiIssuesTest.php:374-453`: unknown project key, non-member, archived, no-dash, prefix-mismatch, non-numeric/empty suffix ×2, unknown number). The **list** endpoint only has a dedicated test for the non-member case (`ApiIssuesTest.php:259-268`) — there is no dedicated "unknown project key on the list endpoint" or "archived project on the list endpoint" test.
  - This is **not a functional gap**: both `index()` and `show()` call the identical `resolveProject()` (now `ResolvesProjectByKey::resolveProject()`), a single `firstOrFail()` query, so the detail-endpoint tests exercise the exact same code path used by the list endpoint.
  - It is also consistent with `api-issues`' own spec.md (`Requirement: Not Found Non-Disclosure`), whose only named list-endpoint scenario is "A non-member's project issues are not found" — the spec does not itself demand a separate "unknown project key" scenario for the list endpoint.
  - Flagging as **WARNING** rather than a pass, because the orchestrator's instruction was stricter than the spec text ("Confirm each change asserts all its cases, not one") and, read literally, `api-issues`' list endpoint does not.

### 9. `openapi/v1.json` documents exactly the 10 registered routes — CONFIRMED
- `php artisan route:list --path=api` → 10 routes (`login`, `logout`, `user`, `projects.index`, `projects.show`, `projects.issues.index`, `projects.issues.show`, `projects.board-columns.index`, `projects.sprints.index`, `projects.labels.index`).
- `openapi/v1.json` `paths` → 10 documented operations, set-equal to the above (verified manually with a Python script over the raw JSON, and independently by `tests/Feature/ApiContractTest.php`, which performs a genuine bidirectional `Collection::diff` — not a count — and passed in today's full suite run).
- `rg "\{project:key\}|\{issue:key\}" openapi/v1.json routes/api.php` → zero matches. No route uses route-model-binding key suffixes anywhere.

### 10. Nothing outside `/api/v1` regressed — CONFIRMED
- `git diff main..HEAD -- routes/auth.php app/Http/Controllers/Auth/ app/Http/Requests/Auth/ config/auth.php` → empty (zero bytes changed).
- `git diff main..HEAD -- app/Http/Controllers/ProjectController.php app/Http/Controllers/IssueController.php app/Http/Controllers/BoardController.php app/Http/Controllers/SprintController.php app/Http/Controllers/LabelController.php` (the **web** controllers, not `Api\V1\*`) → empty.
- The wider `main..HEAD` diff does touch `routes/web.php` (+7, adds the mobile-token settings screen route — unrelated to session auth), `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/Settings/McpTokenController.php`, `routes/ai.php` — all of these belong to the **already-merged, separately-verified** `api-token-auth` change (it has its own `openspec/changes/api-token-auth/verify-report.md`), not to the three changes under today's verification.
- Scoped strictly to the three changes under verification (`git diff feat/api-projects..HEAD`, i.e. everything api-issues + api-board-sprints + api-labels-comments added): 32 files changed, **5008 insertions, 0 deletions** — 100% additive, entirely confined to `app/Http/{Controllers,Resources,Requests}/Api/V1/*`, `routes/api.php` (+9 lines), `openapi/v1.json`, `tests/Feature/Api{Issues,BoardColumns,Sprints,Labels}Test.php`, and `openspec/`.

## Also Assessed

**Rollback plans.** Each change's `proposal.md` "Rollback Plan" section ("revert the branch merge") is **accurate** and matches reality: each change landed as exactly one commit (`ded6460`, `eb7d8d0`, `60c7e12`), confirming a single-commit revert is sufficient and complete. However, the finer-grained rollback boundaries described in `tasks.md`/`apply-progress.md` — "revert commit 1 (list) alone," "revert commit 2 (detail) alone," "revert commit 1 (board-columns) alone," "revert commit 2 (sprints) alone" — describe a two-commit split per change that does **not** exist in the actual git history; both endpoint pairs were squashed into one commit each. A partial, single-endpoint revert is not the one-line `git revert` these documents imply. **WARNING.**

**Documented-vs-reachable endpoints.** None found — set-equal, see Check 9.

**Change scope vs. name.** `api-labels-comments` ships only a labels endpoint; a comments endpoint was explicitly declined. This is transparently self-disclosed in `openspec/changes/api-labels-comments/proposal.md` (Decision 2: "Decline the paginated comments endpoint" — comments are already embedded read-only via `api-issues`' detail response). Not a defect, informational only.

## Issue W1 — apply-progress commit claims contradicted by git history

All three `apply-progress.md` files state no commit was made ("No commit was made," "No commits made... implement and verify only," commit steps left unchecked "intentionally... per session instruction"). Actual `git log` on the current branch shows one commit per change (`ded6460`, `eb7d8d0`, `60c7e12`), each containing exactly the file set the corresponding apply-progress describes. This means either the artifacts are stale relative to a later commit step, or a commit happened outside the documented apply flow. The code content itself matches the artifact's own file-by-file description — this is a documentation-accuracy issue, not a functional one — but it does mean `api-issues/tasks.md` items `1.19`/`2.18` are unchecked for a reason (the commit "wasn't run") that is factually false at verify time. **CRITICAL per the strict "unchecked tasks always CRITICAL" rule; WARNING per the "cleanup task" carve-out in the Decision Gates table** — reported as WARNING here because (a) the substantive work these tasks describe is complete and independently verified end-to-end via tests 1-10 above, (b) the checkbox items are administrative (`git commit`), not functional, and (c) the actual commits exist and match the claimed scope exactly.

## Verdict

**PASS WITH WARNINGS**

No CRITICAL functional defect found across any of the 10 required checks or the general regression scan. Findings 1-7, 9, 10 are fully CONFIRMED with runtime-test-backed evidence. Finding 8 is confirmed for two of three changes with one explicit, narrow, non-functional gap in `api-issues`' list-endpoint test coverage (functionally covered by the shared resolver + detail-endpoint tests, but not directly asserted on the list endpoint itself).

### Warnings (3)
1. **W1** — `apply-progress.md` files (all three changes) claim no commit was made; git history contradicts this (`ded6460`, `eb7d8d0`, `60c7e12` exist and match the claimed scope exactly). `api-issues/tasks.md` items 1.19/2.18 remain unchecked for a stale reason.
2. **W2** — Rollback plans in `tasks.md`/`apply-progress.md` describe two-commit, per-endpoint-revertible boundaries for `api-issues` and `api-board-sprints` that do not match the actual single-commit-per-change git history. The change-level `proposal.md` rollback plans ("revert the branch merge") remain accurate.
3. **W3** — `api-issues`' list endpoint (`GET .../issues`) has a dedicated 404 test only for the non-member case, not for unknown-project-key or archived-project cases directly (both are exercised only via the detail endpoint, which shares the identical resolver).

### Suggestions (0)
None beyond the above.

## Merge Readiness

The owner's stated bar is **"merge to main only after E2E passes."** As stated: **not met.** `tests/Browser/*` currently has all of `HabitFlowTest` (×2), `McpTokenFlowTest`, `SmokeTest`, `MobileTokenRevokeFlowTest` red, plus `AppearanceTest` (a Feature test asserting on rendered HTML) — 6 failures total, all attributable to the documented pre-existing headless-browser-renders-blank-pages environment defect, none touching `/api/v1`, and none introduced or worsened by any of these three changes (same 6, byte-identical failure messages, present before this chain started per each change's own baseline comparison).

On their own merits, the three `/api/v1` changes verified here are clean: 0 CRITICAL findings, full suite is green except for the pre-existing 6, `ApiContractTest` (the automated route↔doc equality guard) passes, Pint is clean, and no code outside `/api/v1` was touched by these three changes specifically. Whether the environment defect is acceptable to merge past is a policy call belonging to the owner — this report states plainly that the literal E2E-passes bar is currently red, and that redness pre-dates and is orthogonal to this three-change unit.
