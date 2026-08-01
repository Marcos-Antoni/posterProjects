# Tasks: Project Label Catalogue (`api-labels-comments`)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~340 |
| 400-line budget risk | Low (~0.85x) |
| Chained PRs recommended | No |
| Suggested split | Single PR, single atomic commit |
| Delivery strategy | ask-on-risk (default; risk is Low so no decision gate fires) |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | `GET /api/v1/projects/{project}/labels` end to end: resource, controller, route, OpenAPI, tests | PR 1 (only) | `php artisan test --compact --filter=ApiLabelsTest` | N/A — read-only JSON endpoint; the HTTP feature test over a real bearer token is the runtime proof, no manual harness adds signal | Revert deletes `LabelController.php` + `LabelResource.php` + `ApiLabelsTest.php` and restores `routes/api.php`/`openapi/v1.json`; no shared file is modified beyond those two additive edits |

## Phase 1: RED — Failing Spec (`tests/Feature/ApiLabelsTest.php`)

- [x] 1.1 Add `labelPayload()` helper (mirror `projectPayload()`, `ApiProjectsTest.php:30-41`) and `assertLabelsNotFound()` (mirror `assertBoardColumnsNotFound()`, `ApiBoardColumnsTest.php:33-38`).
- [x] 1.2 Test: shape exposes exactly 4 fields (`id`,`name`,`issues_count`,`updated_at`) via `toBe()` (spec "Complete, Unpaginated, Name-Ordered Catalogue").
- [x] 1.3 Test: ordering — seed `'backend'`,`'bug'`,`'frontend'`,`'urgent'` (lowercase, non-alphabetical insertion, never `LabelFactory`'s `fake()->word()`), assert ascending `name` order identically across two calls (D-4).
- [x] 1.4 Test: a zero-issue label is present and its `issues_count` is asserted `toBe(0)` STRICTLY — never `toBeEmpty()`/`toBeFalsy()`/`==` (D-3; these are all satisfied by `null` and would reopen the hole).
- [x] 1.5 Test: a project with no labels returns `200 {"data":[]}`, never `404`.
- [x] 1.6 Test: query-count guard — 1 label vs 5, `Model::preventLazyLoading()` in `try/finally`, `forgetGuards()` + counter reset before each request, `expect($manyQueries)->toBe($soloQueries)`, AND every `issues_count` value asserted per label including the zero-issue one (mirrors `ApiSprintsTest.php:225-276`).
- [x] 1.7 Test: 404 non-disclosure — unknown key, non-member project, archived project — byte-identical `{"message":"Recurso no encontrado."}`, body never contains `App\Models\Project` (D-6; the one applicable threat-matrix boundary: routing/identifier resolution).
- [x] 1.8 Test: no token → `401` + `WWW-Authenticate: Bearer`; `mcp`-only token → `403` + Spanish body.
- [x] 1.9 Run `php artisan test --compact --filter=ApiLabelsTest` — confirm every test fails (route not yet registered). RED confirmed.

## Phase 2: GREEN — Minimum Production Code

- [x] 2.1 Create `app/Http/Resources/LabelResource.php` — `@mixin Label`, allowlist `id`,`name`,`issues_count`,`updated_at` (`?->toIso8601String()`). No `project_id`, `created_at`, nested `issues`, `color`, or `can_edit`/`can_delete` (D-2).
- [x] 2.2 Create `app/Http/Controllers/Api/V1/LabelController.php` — `use ResolvesProjectByKey;`; `index()` returns `LabelResource::collection($resolvedProject->labels()->withCount('issues')->orderBy('labels.name')->get())`. Two statements, no FormRequest, no service, no tiebreaker (D-1, D-4 — `unique(['project_id','name'])` already makes `name` total).
- [x] 2.3 Add route in `routes/api.php` inside the `auth:sanctum`+`abilities:mobile` group, after the `sprints.index` line (`:22`): `GET projects/{project}/labels` → `LabelController::index`, name `api.v1.projects.labels.index`.
- [x] 2.4 Run `php artisan test --compact --filter=ApiLabelsTest` — confirm all tests pass. GREEN confirmed.

## Phase 3: OpenAPI Contract (D-5)

- [x] 3.1 Add `Labels` tag to `openapi/v1.json` `tags` array (after `Sprints`, `:32-34`), read-only-catalogue description mirroring the `Sprints` tag.
- [x] 3.2 Add path `/api/v1/projects/{project}/labels` operation: `operationId` `listProjectLabels`, `tags: ["Labels"]`, `security: [{"bearerAuth":[]}]`, `{project}` documented as a plain string — **never** `{project:key}`; responses `200`→`LabelCollectionResponse`, `401`→`UnauthenticatedResponse`, `403`→`ForbiddenResponse`, `404`→`NotFoundResponse`; no `422`.
- [x] 3.3 Add `Label` schema (4 required fields, `issues_count` documented as an eager aggregate) and `LabelCollectionResponse` (bare `{data:[...]}`, no `links`/`meta`), mirroring `BoardColumn`/`BoardColumnCollectionResponse` (`openapi/v1.json:901-921`).
- [x] 3.4 Run `php artisan test --compact --filter=ApiContractTest` — 10 documented operations, all declaring `bearerAuth`, set-equal against routes in both directions.

## Phase 4: Regression & Verification

- [x] 4.1 Run `vendor/bin/pint --dirty --format agent` and fix any reported style issues.
- [x] 4.2 Verify `git diff` is empty on `IssueResource.php`, `IssueDetailResource.php`, `Api/V1/IssueController.php` (proposal D-3 — no shipped endpoint changes shape).
- [x] 4.3 Run full suite `php artisan test --compact` — passed count grows by the new `ApiLabelsTest` cases, and failures stay at exactly the pre-existing 6 headless-browser cases (`AppearanceTest`, `HabitFlowTest` x2, `McpTokenFlowTest`, `SmokeTest`, `MobileTokenRevokeFlowTest`). No new failure is acceptable.
