# Tasks: Board Columns And Sprints Read API (api-board-sprints)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~760 (~330 columns, ~430 sprints) |
| 400-line budget risk | High (~1.9x) |
| Chained PRs recommended | No |
| Suggested split | Single PR, two atomic commits, split by endpoint |
| Delivery strategy | single-pr-default (size:exception pre-authorized this session) |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Board columns endpoint + `ResolvesProjectByKey` extraction | Commit 1 (same PR) | `php artisan test --compact --filter=ApiBoardColumnsTest` | Real bearer-token HTTP via `apiBearerHeaders()` | Revert commit 1: deletes controller/resource/route/concern, re-inlines `resolveProject()` into `IssueController` |
| 2 | Sprints endpoint | Commit 2 (same PR) | `php artisan test --compact --filter=ApiSprintsTest` | Real bearer-token HTTP via `apiBearerHeaders()` | Revert commit 2 alone: shares only `routes/api.php`, `openapi/v1.json`, the concern with commit 1 |

## Phase 1: Foundation — `ResolvesProjectByKey` Extraction

- [x] 1.1 Baseline: `php artisan test --compact --filter=ApiIssuesTest` green before any edit.
- [x] 1.2 Create `app/Http/Controllers/Api/V1/Concerns/ResolvesProjectByKey.php` — trait, `protected function resolveProject(Request $request, string $projectKey): Project`, body copied byte-for-byte from `IssueController.php:114-117`.
- [x] 1.3 `IssueController.php`: add `use ResolvesProjectByKey;` + import; delete `:108-117`; drop now-unused `App\Models\Project` import. Zero edits at call sites `:41`, `:88`.
- [x] 1.4 **Hard gate**: `php artisan test --compact --filter=ApiIssuesTest` green AND `git diff -- tests/Feature/ApiIssuesTest.php` empty. This proves guard tests `:259`, `:374`, `:383`, and `:395` (the deliberately absent `withTrashed()`) are unchanged. If the diff is non-empty, stop — revert to duplicating the 5 lines per controller instead.
- [x] 1.5 `vendor/bin/pint --dirty` on touched files.

## Phase 2: Board Columns Endpoint (Commit 1)

- [x] 2.1 RED `tests/Feature/ApiBoardColumnsTest.php`: shape — exactly `id,name,position,updated_at`, `toBe()`, 3 columns ordered by `position`.
- [x] 2.2 RED: empty column stays present — seed issues only into a 4th column at explicit `position: 3` (never the factory default — D-7); all columns returned.
- [x] 2.3 RED: composition acceptance — `board-columns` + `GET /issues?sprint={id}` grouped by `board_column_id` reconstructs the board incl. the empty column, issues in position order.
- [x] 2.4 RED: 404 non-disclosure x3 — unknown key, non-member, archived project (no `withTrashed()`) — shared helper, body excludes `App\Models\Project`.
- [x] 2.5 RED: 401 no token + `WWW-Authenticate: Bearer`; 403 `mcp`-only token, Spanish body.
- [x] 2.6 RED: N+1 guard — `Model::preventLazyLoading()` in `try/finally`, `DB::listen` select counter, `forgetGuards()` + counter reset per request, 1 vs 5 columns (explicit positions 3,4 — never factory default), `toBe()` query-count equality.
- [x] 2.7 GREEN `app/Http/Resources/BoardColumnResource.php` — 4-field allowlist, `@mixin BoardColumn`.
- [x] 2.8 GREEN `app/Http/Controllers/Api/V1/BoardColumnController.php` — `index`, `use ResolvesProjectByKey;`, `$project->boardColumns()->get()`, no eager load.
- [x] 2.9 GREEN `routes/api.php` — `GET projects/{project}/board-columns` inside the `auth:sanctum,abilities:mobile` group, name `api.v1.projects.board-columns.index`.
- [x] 2.10 GREEN `openapi/v1.json` — `Board Columns` tag, `listProjectBoardColumns` op, `{project}` documented as a plain string (no binding suffix), `security:[{bearerAuth:[]}]`, responses 200/401/403/404 (no 422), schemas `BoardColumn`, `BoardColumnCollectionResponse`.
- [x] 2.11 Verify: `php artisan test --compact` green, `ApiContractTest` green both directions; `vendor/bin/pint --dirty`; commit scoped to this endpoint only (route + doc + code together — never split by layer).

## Phase 3: Sprints Endpoint (Commit 2)

- [x] 3.1 RED `tests/Feature/ApiSprintsTest.php`: shape — exactly 8 fields, `start_date`/`end_date` via `toDateString()` (not ISO8601), `toBe()`.
- [x] 3.2 RED: state matrix — 5 fixtures relative to `today()` (`addDays(7)`, `subDays(7)`, etc.), `Carbon::setTestNow(today()->addHours(12))` at top, `afterEach` clears it. Starts-today, ends-today, single-day (start==end==today) all `active`; starts-tomorrow `future`; ended-yesterday `completed`. Anchor is app-timezone `today()` (UTC, `config/app.php:68`), matching `BoardController.php:82-86` — never the UTC-6 habit rule; both boundaries inclusive.
- [x] 3.3 RED: ordering tiebreaker — two sprints sharing `start_date` return `id DESC`.
- [x] 3.4 RED: active-pick parity — run `BoardController::resolveActiveSprint` over the same fixture (incl. two overlapping active sprints), compare its pick to the first `state:"active"` element.
- [x] 3.5 RED: `issues_count` value assertion per sprint, incl. `0` for an empty sprint — sourced from `withCount('issues')`, never `->issues()->count()`.
- [x] 3.6 RED: 404 x3 (unknown/non-member/archived), 401/403 — mirrors Phase 2.
- [x] 3.7 RED: N+1 guard, 1 vs 5 sprints, paired with the `issues_count` value assertion (catches a dropped `withCount` returning `null` while query count stays flat).
- [x] 3.8 GREEN `app/Http/Resources/SprintResource.php` — 8-field allowlist, `@mixin Sprint`, `state` via `match(true)` ordered future -> completed -> active (D-4).
- [x] 3.9 GREEN `app/Http/Controllers/Api/V1/SprintController.php` — `index`, `use ResolvesProjectByKey;`, `$project->sprints()->withCount('issues')->orderByDesc('sprints.start_date')->orderByDesc('sprints.id')->get()`.
- [x] 3.10 GREEN `routes/api.php` — `GET projects/{project}/sprints`, name `api.v1.projects.sprints.index`.
- [x] 3.11 GREEN `openapi/v1.json` — `Sprints` tag (description restates the board-composition contract), `listProjectSprints` op, `{project}` plain string, schemas `Sprint` (`state` enum `future|active|completed`), `SprintCollectionResponse`.
- [x] 3.12 Verify: full `php artisan test --compact` — exactly 6 pre-existing browser failures (`AppearanceTest`, `HabitFlowTest` x2, `McpTokenFlowTest`, `SmokeTest`, `MobileTokenRevokeFlowTest`), zero new failures; `ApiContractTest` green both directions; `vendor/bin/pint --dirty`; commit.

## Phase 4: Final Regression

- [x] 4.1 Full suite `php artisan test --compact`: 466 tests, 460 passed / 6 failed baseline held exactly — no new failures, no fewer failures either (would signal a masked test).
- [x] 4.2 `git diff -- tests/Feature/ApiIssuesTest.php` still empty after both commits.
