# Apply Progress: Board Columns And Sprints Read API (`api-board-sprints`)

**Mode**: Strict TDD (RED before GREEN, task-list exact order)
**Status**: All 30 tasks complete across 4 phases. Ready for `sdd-verify`.

## Completed Tasks

### Phase 1: Foundation — `ResolvesProjectByKey` Extraction
- [x] 1.1 Baseline `ApiIssuesTest` green (18/18) before any edit.
- [x] 1.2 Created `app/Http/Controllers/Api/V1/Concerns/ResolvesProjectByKey.php` — byte-for-byte body copy.
- [x] 1.3 `IssueController.php`: added `use ResolvesProjectByKey;`, deleted the private method + docblock, dropped the now-unused `App\Models\Project` import. Zero call-site edits.
- [x] 1.4 Hard gate: `ApiIssuesTest` green (18/18) AND `git diff -- tests/Feature/ApiIssuesTest.php` empty — **confirmed, 0 lines**.
- [x] 1.5 `vendor/bin/pint --dirty` clean on touched files.

### Phase 2: Board Columns Endpoint (Commit 1 scope)
- [x] 2.1–2.6 RED `tests/Feature/ApiBoardColumnsTest.php` — 6 tests: shape (`toBe()`, 4 fields, position-ordered), empty-column visibility, board-composition acceptance (`board-columns` + `GET /issues?sprint={id}` grouped by `board_column_id`), 404 non-disclosure ×3, 401/403, N+1 guard (3 vs 5 columns, explicit positions 3/4).
- [x] 2.7 GREEN `app/Http/Resources/BoardColumnResource.php` — 4-field allowlist, `@mixin BoardColumn`.
- [x] 2.8 GREEN `app/Http/Controllers/Api/V1/BoardColumnController.php` — `index`, `use ResolvesProjectByKey;`, no eager load.
- [x] 2.9 GREEN `routes/api.php` — `GET projects/{project}/board-columns`, name `api.v1.projects.board-columns.index`.
- [x] 2.10 GREEN `openapi/v1.json` — `Board Columns` tag, `listProjectBoardColumns`, schemas `BoardColumn` / `BoardColumnCollectionResponse`, no `422`.
- [x] 2.11 Verified: full suite green at baseline, `ApiContractTest` green both directions, Pint clean.

### Phase 3: Sprints Endpoint (Commit 2 scope)
- [x] 3.1–3.7 RED `tests/Feature/ApiSprintsTest.php` — 8 tests: shape (8 fields, `toDateString()` not ISO8601), state matrix (5 fixtures, both inclusive boundaries + single-day, frozen clock via `Carbon::setTestNow(today()->addHours(12))`, cleared in `afterEach`), ordering tiebreaker (`id DESC`), active-pick parity against `BoardController::resolveActiveSprint` (incl. two overlapping actives), `issues_count` value assertion incl. `0`, 404 ×3, 401/403, N+1 guard (1 vs 5 sprints, paired with count values).
- [x] 3.8 GREEN `app/Http/Resources/SprintResource.php` — 8-field allowlist, `@mixin Sprint`, `state` via `match(true)` ordered future → completed → active.
- [x] 3.9 GREEN `app/Http/Controllers/Api/V1/SprintController.php` — `index`, `use ResolvesProjectByKey;`, `withCount('issues')`, `orderByDesc('sprints.start_date')->orderByDesc('sprints.id')`.
- [x] 3.10 GREEN `routes/api.php` — `GET projects/{project}/sprints`, name `api.v1.projects.sprints.index`.
- [x] 3.11 GREEN `openapi/v1.json` — `Sprints` tag (restates the board-composition contract), `listProjectSprints`, schemas `Sprint` (`state` enum) / `SprintCollectionResponse`, no `422`.
- [x] 3.12 Verified: full suite exactly at baseline, `ApiContractTest` green both directions, Pint clean.

### Phase 4: Final Regression
- [x] 4.1 Full suite run **twice** for stability: both runs **480 tests, 474 passed, 6 failed** — the exact 6 pre-existing baseline failures, no more, no fewer.
- [x] 4.2 `git diff -- tests/Feature/ApiIssuesTest.php` still empty after both endpoints.

## Files Changed

| File | Action | What Was Done |
|------|--------|---------------|
| `app/Http/Controllers/Api/V1/Concerns/ResolvesProjectByKey.php` | Created | Trait, one `protected` method, body copied verbatim from `IssueController:114-117` |
| `app/Http/Controllers/Api/V1/IssueController.php` | Modified | `use ResolvesProjectByKey;` added, private `resolveProject()` deleted, unused `Project` import dropped. Zero call-site edits (`:41`, `:88` untouched) |
| `app/Http/Controllers/Api/V1/BoardColumnController.php` | Created | `index` only, no eager load |
| `app/Http/Controllers/Api/V1/SprintController.php` | Created | `index` only, `withCount('issues')` |
| `app/Http/Resources/BoardColumnResource.php` | Created | 4-field allowlist |
| `app/Http/Resources/SprintResource.php` | Created | 8-field allowlist, computed `state` |
| `routes/api.php` | Modified | +2 GETs inside the existing `auth:sanctum,abilities:mobile` group |
| `openapi/v1.json` | Modified | +2 tags, +2 operations, +4 schemas (`BoardColumn`, `BoardColumnCollectionResponse`, `Sprint`, `SprintCollectionResponse`) |
| `tests/Feature/ApiBoardColumnsTest.php` | Created | 6 tests, all RED-then-GREEN |
| `tests/Feature/ApiSprintsTest.php` | Created | 8 tests, all RED-then-GREEN |
| `tests/Feature/ApiIssuesTest.php` | **Unchanged** | `git diff` confirmed empty — gate held |

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1–1.5 | `tests/Feature/ApiIssuesTest.php` (approval, unedited) | Feature | ✅ 18/18 baseline | N/A — refactor-only, approval-test style | ✅ 18/18 after extraction | N/A — behavior-identical extraction, no new behavior | ✅ Pint clean |
| 2.1–2.6 | `tests/Feature/ApiBoardColumnsTest.php` | Feature | N/A (new file) | ✅ Written — 6 tests, 5 failed 404-vs-expected-status + 1 vacuous-pass on unmatched route (confirmed reason before GREEN) | ✅ 6/6 passed after resource+controller+route | ✅ 6 distinct scenarios cover shape, empty-column, composition, 404×3, auth, N+1 | ✅ Clean, no dead code |
| 2.7–2.10 | (same) | Feature | — | — | — | — | — |
| 3.1–3.7 | `tests/Feature/ApiSprintsTest.php` | Feature | N/A (new file) | ✅ Written — 8 tests, 7 failed on missing route + 1 vacuous-pass on unmatched route | ✅ 8/8 passed after resource+controller+route | ✅ 8 distinct scenarios cover shape, state matrix (5 fixtures), tiebreaker, active-pick parity, count, 404×3, auth, N+1 | ✅ Clean |
| 3.8–3.11 | (same) | Feature | — | — | — | — | — |

## Test Summary

- **Total tests written**: 14 (6 `ApiBoardColumnsTest` + 8 `ApiSprintsTest`)
- **Total tests passing**: 14/14, plus the pre-existing 18/18 `ApiIssuesTest` held green throughout
- **Layers used**: Feature (14) — matches the project convention (`ApiIssuesTest`/`ApiProjectsTest` precedent, real bearer tokens over HTTP, never `actingAs`)
- **Approval tests** (refactoring): `ApiIssuesTest`'s 18 tests, run before and after the Phase 1 extraction — 0 edits, both runs green
- **Pure functions created**: `SprintResource::toArray()`'s `state` computation is a pure `match(true)` expression over the model's own date attributes and `today()`

## Work Unit Evidence

| Evidence | Unit 1 (board-columns) | Unit 2 (sprints) |
|---|---|---|
| Focused test command and exact result | `php artisan test --compact --filter=ApiBoardColumnsTest` → `passed, tests:6, passed:6` | `php artisan test --compact --filter=ApiSprintsTest` → `passed, tests:8, passed:8` |
| Runtime harness command/scenario and exact result | Real bearer-token HTTP via `apiBearerHeaders()` + `createMemberProject()`, exercising guard → ability → resolver → resource end to end; `ApiContractTest` green both directions | Same harness; additionally cross-checks `SprintResource::state` against `BoardController::resolveActiveSprint`'s live pick over an identical DB fixture |
| Rollback boundary | Revert commit 1 alone: deletes `BoardColumnController`, `BoardColumnResource`, `ApiBoardColumnsTest`, the board-columns route + OpenAPI operation, and the `ResolvesProjectByKey` concern (re-inlining `resolveProject()` into `IssueController`) | Revert commit 2 alone: deletes `SprintController`, `SprintResource`, `ApiSprintsTest`, the sprints route + OpenAPI operation. Shares only `routes/api.php`, `openapi/v1.json`, and the concern with unit 1 |

## Deviations from Design

None — implementation matches design. Two self-corrections during implementation, both caught before they became deviations:
1. Initially added the `Sprints` OpenAPI tag/path/schemas during the Phase 2 (board-columns-only) work before the sprints route existed. Caught immediately and reverted before running `ApiContractTest`, keeping the design's "route + doc + code together, split by endpoint" commit boundary intact. Re-added correctly in Phase 3.
2. The N+1 guard fixture size for board-columns reads "1 vs 5" in the design/tasks prose, but `createMemberProject()` always seeds 3 default columns (0/1/2) via `Project::createWithDefaultColumns`. Interpreted as design intended: baseline = the 3 default columns, then add exactly 2 more at explicit positions 3 and 4 to reach 5 — matching the tasks.md wording "(explicit positions 3,4 — never factory default)" precisely, and preserving the query-count-identical assertion's intent (structure count is independent of row count).

## Issues Found

None.

## Traps From The Brief — How Each Was Handled

1. **`ResolvesProjectByKey` extraction**: gate is mechanical and passed — `ApiIssuesTest` 18/18 green, `git diff` on the test file is 0 lines. The `withTrashed()`-free body was copied verbatim (not retyped), preserving the exact `firstOrFail()` semantics guarded by `ApiIssuesTest.php:395`.
2. **`BoardColumnFactory` random `position` default**: never used. Every new fixture column across both test files passes an explicit `position` (3, 4, ...). The shared factory itself is untouched.
3–4. **Sprint `state` clock flake / anchor**: state-matrix test freezes `Carbon::setTestNow(today()->addHours(12))`, clears in a file-level `afterEach`; anchor is bare `today()` (UTC, `config/app.php:68`), matching `BoardController.php:82-86` exactly — no UTC-6 habit-day logic borrowed.
5. **Counts from `withCount`, never per-row `->count()`**: `SprintController` uses `withCount('issues')`; the N+1 test pairs the query-count-identical assertion with per-row `issues_count` value assertions (2 per sprint, including the dedicated zero-count test), so a dropped aggregate returning `null` would fail even if the query count stayed flat.
6. **404 non-disclosure**: both endpoints' 404 tests assert byte-identical `{"message": "Recurso no encontrado."}` and assert the response body excludes `App\Models\Project`, covering unknown key, non-member, and archived-project cases — consistent with `api-issues`.
7. **OpenAPI paths strip the binding field**: `{project}` documented as a plain `{"type": "string"}` parameter in both new operations, never a route-model-binding suffix. Route + doc + code shipped together per endpoint (verified by re-running `ApiContractTest` after each phase, not just at the end).

## Verification Snapshot

- `php artisan test --compact --filter=ApiIssuesTest` (post-extraction): `passed, tests:18, passed:18`
- `git diff -- tests/Feature/ApiIssuesTest.php`: 0 lines
- `php artisan test --compact --filter=ApiBoardColumnsTest`: `passed, tests:6, passed:6`
- `php artisan test --compact --filter=ApiSprintsTest`: `passed, tests:8, passed:8`
- `php artisan test --compact --filter=ApiContractTest` (after Phase 2 and again after Phase 3): `passed, tests:2, passed:2` both times
- `php artisan test --compact` (full suite, run twice): `failed, tests:480, passed:474, failed:6` both times — the 6 are `AppearanceTest`, `HabitFlowTest` ×2, `McpTokenFlowTest`, `SmokeTest`, `MobileTokenRevokeFlowTest`, all pre-existing and out of scope
- `vendor/bin/pint --dirty --format agent`: `passed` (also ran explicitly against every new file, not just `--dirty`-detected ones, since new files are untracked)

## Workload / PR Boundary

- Mode: **`size:exception`** (pre-authorized this session, ~760 estimated lines against the 400-line budget)
- Current work unit: both — implemented as one PR, two atomic, endpoint-scoped commits (not yet created — commits were explicitly withheld per instructions)
- Boundary: Commit 1 = `ResolvesProjectByKey` extraction + board-columns endpoint (route + resource + controller + OpenAPI + tests). Commit 2 = sprints endpoint (route + resource + controller + OpenAPI + tests). Each commit leaves `php artisan test --compact` green at baseline in isolation once staged.
- Estimated review budget impact: High (~1.9× the 400-line default), exception already accepted; no further action needed from `sdd-apply`

## Status

30/30 tasks complete. **No commits made** (explicitly withheld per instructions — implement and verify only). Ready for `sdd-verify`.
