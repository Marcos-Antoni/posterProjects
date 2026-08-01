# Tasks: Issues Read API (`api-issues`)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~670 (design commit-boundary estimate) |
| 400-line budget risk | High |
| Chained PRs recommended | No (size:exception pre-authorized) |
| Suggested split | Single PR, two atomic commits |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 (Commit 1) | List endpoint: `IssueResource`, `IssueController::index` + `resolveProject()`, list route, OpenAPI `listProjectIssues` + schemas, list/order/hydration/pagination/sprint/422/401/403/N+1 tests | Single PR, commit 1 | `php artisan test --compact --filter=ApiIssuesTest` | `php artisan test --compact` (full suite, must stay at 442/448) | Revert commit 1: deletes `IssueController::index`, `IssueResource`, list route, OpenAPI list operation — nothing outside this file set touches revert |
| 2 (Commit 2) | Detail endpoint: `IssueDetailResource`, `IssueController::show`, show route, OpenAPI `getProjectIssue` + schemas, detail/comment-order/404×7 tests | Single PR, commit 2 | `php artisan test --compact --filter=ApiIssuesTest` | `php artisan test --compact` (full suite, must stay at 442/448) | Revert commit 2 only: shares `routes/api.php`, `openapi/v1.json`, `IssueController.php` with commit 1 but adds no new files that commit 1 depends on |

## Phase 1: List Endpoint Foundation (Commit 1, req'ts: List/Sprint/Shape/Auth/N+1/OpenAPI)

- [x] 1.1 RED: `tests/Feature/ApiIssuesTest.php` — add `issuePayload()` helper (mirrors `projectPayload()`, `ApiProjectsTest.php:30-41`) and `assertIssueNotFound()` helper asserting 404, generic body, no `App\Models\Issue`/`App\Models\Project` substring.
- [x] 1.2 RED: test — list returns exactly the 15 D-4 fields via `toBe()` on `data` (spec: List And Detail Resource Shapes Are Pinned).
- [x] 1.3 RED: test — join-hydration trap: issue placed in the **third** default column at `position: 0`; asserts its own `id`/`position` survive (design D-3). Must use the third column, not the first.
- [x] 1.4 RED: test — board order across three columns is identical across two consecutive calls (spec: Order is stable across consecutive calls and a page boundary).
- [x] 1.5 RED: test — tiebreaker: two issues sharing `(board_column_id, position)` from different sprints come back ordered by `issues.id`.
- [x] 1.6 RED: test — pagination: `links`/`meta` present, `meta.last_page` correct, page 2 disjoint from page 1, `links.next` retains `?sprint=` across the page boundary with `per_page=1` over 2 filtered issues (proves `->withQueryString()`, not just single-page presence).
- [x] 1.7 RED: test — `?sprint={id}` filters to that sprint; `?sprint=backlog` returns only `sprint_id IS NULL`; foreign sprint id → 404; `?sprint=abc` → 422; `?per_page=101` → 422; `?page=abc` → 422 (six requests).
- [x] 1.8 RED: test — no token → 401 + `WWW-Authenticate: Bearer`; `['mcp']`-only token → 403 Spanish body.
- [x] 1.9 RED: test — a non-member's project issues → 404 generic body, never 403.
- [x] 1.10 RED: test — N+1 guard: `Model::preventLazyLoading()` enabled **inside this test only**; query count identical for 1 issue vs 5 (each with a label and assignee), **paired with value assertions** on `labels` names and `assignee.name` (a dropped eager load returns `null` silently and must not pass on count alone).
- [x] 1.11 GREEN: create `app/Http/Resources/IssueResource.php` — 15-field allowlist per D-4, `@mixin Issue`, `type`/`priority` as `->value`, `due_date` as `?->toDateString()`.
- [x] 1.12 GREEN: create `app/Http/Controllers/Api/V1/IssueController.php` with private `resolveProject()` (owner-scoped `firstOrFail()`, **no** `withTrashed()` — design D-2) and `index()`: validate `per_page`/`page`/`sprint`, resolve optional sprint (`404` if foreign), join `board_columns` with `->select('issues.*')`, order by `board_columns.position, issues.position, issues.id`, `->with(['assignee:id,name','labels' => ordered by name/id])`, `->paginate()->withQueryString()`, `setRelation('project', $project)` per row. Query-param validation moved to a dedicated `App\Http\Requests\Api\V1\ListProjectIssuesRequest` (Spanish `messages()`) instead of inline `$request->validate()` — see Deviations.
- [x] 1.13 GREEN: add list route in `routes/api.php` inside the existing `['auth:sanctum','abilities:mobile']` group — `api.v1.projects.issues.index`.
- [x] 1.14 GREEN: run `php artisan test --compact --filter=ApiIssuesTest` until 1.2–1.10 pass.
- [x] 1.15 OpenAPI: add `Issues` tag; `listProjectIssues` operation at `/api/v1/projects/{project}/issues` (never `{issue:key}`), `security: [{"bearerAuth": []}]`, responses `200 IssueCollectionResponse, 401, 403, 404, 422`.
- [x] 1.16 OpenAPI: add schemas `Issue`, `LabelRef`, `UserRef`, `PaginationLinks`, `PaginationMeta`, `IssueCollectionResponse`.
- [x] 1.17 Verify: `php artisan test --compact --filter=ApiContractTest` passes (route↔document set-equality for the new list operation).
- [x] 1.18 `vendor/bin/pint --dirty --format agent` before committing.
- [x] 1.19 Commit 1 (~370 lines): `IssueResource`, `IssueController::index`, list route, OpenAPI list delta, list tests. `php artisan test --compact` green at the pre-existing baseline (442/448, only the 6 known browser failures). — Implementation complete and verified; commit intentionally deferred per session instructions (no `git commit`). **Archive-time reconciliation (2026-08-01)**: checkbox was stale, not incomplete — `git log` confirms commit `ded6460` on `feat/api-issues` contains exactly this file set, independently verified by the combined verify-report (`openspec/changes/api-labels-comments/verify-report.md`, Issue W1). Checked to reflect actual completion state; the substantive work was never in question.

## Phase 2: Detail Endpoint (Commit 2, req'ts: Key Resolution/Not-Found/Archived/Shape/OpenAPI)

- [x] 2.1 RED: test — detail returns the 20-field shape (15 + `description`,`reporter`,`parent`,`children`,`comments`) via `toBe()` on the whole `data` object.
- [x] 2.2 RED: test — comment order stable when two comments share `created_at` to the second (asserts `comments.id` tiebreaker, design D-5).
- [x] 2.3 RED: test — case 1 unknown project key → 404 (`assertIssueNotFound`).
- [x] 2.4 RED: test — case 2 non-member project key → 404.
- [x] 2.5 RED: test — case 3 **archived project** → 404, even though `GET /api/v1/projects/{key}` on the same key resolves with `archived: true` (spec: Archived Project Issues Are Not Found — this is intended behavior per `openspec/specs/issues/spec.md:69-95`, not an oversight; assert both responses in one test to make the divergence explicit).
- [x] 2.6 RED: test — case 4 malformed key with no dash → 404.
- [x] 2.7 RED: test — case 5 prefix mismatch (`OTHER-1` requested under `/projects/PROJ/`) → 404 — the case nesting exists to catch.
- [x] 2.8 RED: test — case 6 non-numeric/empty suffix → 404.
- [x] 2.9 RED: test — case 7 unknown number in this project → 404.
- [x] 2.10 GREEN: create `app/Http/Resources/IssueDetailResource.php` — 20-field allowlist per D-4, field-for-field `IssueController::presentIssue` (`app/Http/Controllers/IssueController.php:130-176`). Reuses `IssueResource::toArray()` directly for the shared 15 fields instead of duplicating them (DRY; identical wire shape).
- [x] 2.11 GREEN: add `IssueController::show()` — resolve project via `resolveProject()`, resolve issue via `Issue::resolveByKey($project, $issue)` then `abort_if(null, 404)`, `->load()` labels/assignee/reporter/parent/children/comments with the D-5 orderings (`labels.name,labels.id`; `issues.position,issues.id` for children; `comments.created_at,comments.id`), `setRelation('project', $project)` on the issue, its parent, and each child.
- [x] 2.12 GREEN: add show route in `routes/api.php` — `api.v1.projects.issues.show`.
- [x] 2.13 GREEN: run `php artisan test --compact --filter=ApiIssuesTest` until 2.1–2.9 pass.
- [x] 2.14 OpenAPI: add `getProjectIssue` operation at `/api/v1/projects/{project}/issues/{issue}` (literal `{issue}`, not `{issue:key}` — `RouteUri::parse` strips the binding field and `ApiContractTest` compares against `$route->uri()`), `security: [{"bearerAuth": []}]`, responses `200 IssueResponse, 401, 403, 404`.
- [x] 2.15 OpenAPI: add schemas `IssueDetail`, `IssueRef`, `IssueChildRef`, `IssueComment`, `IssueResponse`.
- [x] 2.16 Verify: `php artisan test --compact --filter=ApiContractTest` passes (both operations, both directions).
- [x] 2.17 `vendor/bin/pint --dirty --format agent` before committing.
- [x] 2.18 Commit 2 (~300 lines): `IssueDetailResource`, `IssueController::show`, show route, OpenAPI detail delta, detail tests. — Implementation complete and verified; commit intentionally deferred per session instructions (no `git commit`). **Archive-time reconciliation (2026-08-01)**: same basis as 1.19 — commit `ded6460` contains this file set too (both endpoints landed in one commit, not two, per the verify-report's own finding).

## Phase 3: Full Suite Verification

- [x] 3.1 Run `php artisan test --compact` (full suite). MUST end at exactly the pre-existing baseline: 448 total, 442 passed, 6 failed (the known headless-browser defects: `AppearanceTest`, `HabitFlowTest` ×2, `McpTokenFlowTest`, `SmokeTest`, `MobileTokenRevokeFlowTest`). Any other failure or a changed count is a regression — stop and fix before proceeding. Actual: 466 total (448 + 18 new `ApiIssuesTest` tests), 460 passed, 6 failed — the identical 6 baseline browser tests, byte-identical failure messages. No regression.
- [x] 3.2 Confirm no `npm run build` needed (no front-end surface touched per design/proposal) — skip. Confirmed: no `resources/js` files touched.
