# Tasks: Projects Read API (`api-projects`)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~380 (~210 + ~170) |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR, 2 atomic commits (one per endpoint) |
| Delivery strategy | single-pr-default |
| Chain strategy | size-exception |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

`bootstrap/app.php` needs no work — 403/404 renders already committed at `f8aa467`
(`McpServerTest.php:111` asserts the 403 body). No headroom left; if commit 2 overruns, get an
explicit size:exception rather than folding it into commit 1.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | List endpoint green end-to-end | PR 1, commit 1/2 | `php artisan test --compact --filter=ApiProjectsTest --filter=ApiContractTest` | N/A, no browser surface | Revert commit 1: list route, `index()`, `ProjectResource`, `listProjects` + schemas |
| 2 | Show endpoint green end-to-end | PR 1, commit 2/2 | same as Unit 1 | N/A, same | Revert commit 2 only: show route, `getProject`, `NotFoundResponse` |

## Phase 1: List Endpoint — `GET /api/v1/projects` (Commit 1, ~210 lines)

- [x] 1.1 RED — `tests/Feature/ApiProjectsTest.php` (new): list `200 {"data":[...]}` ordered by `name` then `id`, active-only (archived absent); empty membership → `200 {"data":[]}`.
- [x] 1.2 RED — same file: no token → `401` + `WWW-Authenticate: Bearer`; `['mcp']`-only → `403` Spanish body (mirrors `ApiAuthTest.php:84-89`, `McpServerTest.php:101-112`).
- [x] 1.3 RED — same file: N+1 guard — `DB::listen` counter, identical query count for 1 vs 5 projects; `issues_count === 3` value check. Query-count assertion, not convention (D-4).
- [x] 1.4 GREEN — `app/Http/Resources/ProjectResource.php` (new): 7-field allowlist (`id, key, name, description, issues_count, updated_at, archived`), `@mixin Project`, `updated_at` via `->toIso8601String()`.
- [x] 1.5 GREEN — `app/Http/Controllers/Api/V1/ProjectController.php` (new): `index()` — `Gate::authorize('viewAny', Project::class)`, `$request->user()->projects()->withCount('issues')->orderBy('projects.name')->orderBy('projects.id')->get()`. Both `orderBy` columns MUST be table-qualified — `project_members` has its own `id`/timestamps.
- [x] 1.6 GREEN — `routes/api.php`: `Route::get('projects', [ProjectController::class, 'index'])->name('api.v1.projects.index')` inside the `auth:sanctum`+`abilities:mobile` group.
- [x] 1.7 GREEN — `openapi/v1.json`: `Projects` tag; `listProjects` at `/api/v1/projects`, `security: [{"bearerAuth": []}]`, `200 ProjectCollectionResponse`/`401`/`403`; schemas `Project`, `ProjectCollectionResponse`. Ship the pagination forward-compat rule (D-5) as description text: absent `meta` ⇒ complete set; present ⇒ last page is `meta.current_page === meta.last_page`.
- [x] 1.8 Run `php artisan test --compact --filter=ApiProjectsTest --filter=ApiContractTest` green; `vendor/bin/pint --dirty --format agent`; commit. (Ran as two separate `--filter` invocations: `php artisan test`'s `--filter` flag does not OR across repeated occurrences, only the last wins. Both green. Not committed — orchestrator handles commits per session instructions.)

## Phase 2: Show Endpoint — `GET /api/v1/projects/{project}` (Commit 2, ~170 lines)

- [x] 2.1 RED — same file: active project → `200 archived:false`; after `$project->delete()`, same key → `200 archived:true`. Proves `withTrashed()` forwards through `Relation::__call`.
- [x] 2.2 RED — same file: unknown key, non-member key, force-deleted key — one shared assertion helper — all byte-identical `404 {"message":"Recurso no encontrado."}`, no `App\Models\Project` substring. Non-disclosure needs all three, not one.
- [x] 2.3 GREEN — `ProjectController::show()`: `$request->user()->projects()->withTrashed()->withCount('issues')->where('projects.key', $project)->firstOrFail()`. **Fallback if 2.1 stays red**: `->withoutGlobalScope(SoftDeletingScope::class)` instead of `withTrashed()`. **Shipped**: `withTrashed()` — 2.1 went RED for the right reason (404 vs expected 200) and turned GREEN once the route was registered; the fallback was not needed.
- [x] 2.4 GREEN — `routes/api.php`: `Route::get('projects/{project}', [ProjectController::class, 'show'])->name('api.v1.projects.show')` — plain `{project}`, never `{project:key}` (`ApiContractTest.php:52-67` compares against `$route->uri()`).
- [x] 2.5 GREEN — `openapi/v1.json`: `getProject` at `/api/v1/projects/{project}` (exact string), `security: [{"bearerAuth": []}]`, `200 ProjectResponse`/`401`/`403`/`404 NotFoundResponse`; schemas `ProjectResponse`, `NotFoundResponse` (`{message}`); reuse `UnauthenticatedResponse`/`ForbiddenResponse`.
- [x] 2.6 Run `php artisan test --compact --filter=ApiProjectsTest --filter=ApiContractTest` green; `vendor/bin/pint --dirty --format agent`; commit. (Ran as two separate `--filter` invocations — see 1.8 note. Both green. Not committed — orchestrator handles commits.)

## Phase 3: Full-Suite Regression

- [x] 3.1 Run `php artisan test --compact` full suite. Confirm the 432 previously-passing tests still pass, the same 6 pre-existing failures remain (`AppearanceTest`, `HabitFlowTest` ×2, `McpTokenFlowTest`, `SmokeTest`, `MobileTokenRevokeFlowTest`), every new test passes. **Result**: 448 tests, 442 passed, 6 failed — the same 6 named above, byte-identical to baseline. All 10 new `ApiProjectsTest` tests pass (432+10=442, 438+10=448).
- [x] 3.2 Confirm `npm run build` is not required — no front-end surface touched. Confirmed: no files under `resources/` were touched by this change.
