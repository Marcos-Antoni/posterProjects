# Apply Progress: Projects Read API (`api-projects`)

**Mode**: Strict TDD (RED → GREEN → REFACTOR)
**Status**: 12/12 tasks complete. Ready for `sdd-verify`.

## Completed Tasks

### Phase 1 — List Endpoint (`GET /api/v1/projects`)
- [x] 1.1 RED — list ordering + active-only scoping + empty membership
- [x] 1.2 RED — 401 bearer challenge + 403 mcp-only ability rejection
- [x] 1.3 RED — N+1 query-count guard + `issues_count` value check
- [x] 1.4 GREEN — `app/Http/Resources/ProjectResource.php` (7-field allowlist)
- [x] 1.5 GREEN — `app/Http/Controllers/Api/V1/ProjectController.php::index()`
- [x] 1.6 GREEN — `routes/api.php` list route
- [x] 1.7 GREEN — `openapi/v1.json`: `Projects` tag, `listProjects`, `Project`/`ProjectCollectionResponse` schemas, pagination forward-compat rule as description text
- [x] 1.8 — `ApiProjectsTest` + `ApiContractTest` green; `pint` clean

### Phase 2 — Show Endpoint (`GET /api/v1/projects/{project}`)
- [x] 2.1 RED — active project resolves `archived:false`; same key resolves `archived:true` after soft-delete
- [x] 2.2 RED — unknown/non-member/force-deleted key → identical generic 404, shared `assertProjectNotFound()` helper
- [x] 2.3 GREEN — `ProjectController::show()` — **shipped `withTrashed()`**, not the `withoutGlobalScope` fallback (see Implementer Traps below)
- [x] 2.4 GREEN — `routes/api.php` show route, plain `{project}`
- [x] 2.5 GREEN — `openapi/v1.json`: `getProject`, `ProjectResponse`/`NotFoundResponse` schemas
- [x] 2.6 — `ApiProjectsTest` + `ApiContractTest` green; `pint` clean

### Phase 3 — Full-Suite Regression
- [x] 3.1 — `php artisan test --compact`: 448 tests, 442 passed, 6 failed (all 6 pre-existing baseline, byte-identical to the named list). Zero new failures.
- [x] 3.2 — `npm run build` not required; no `resources/` files touched.

## Files Changed

| File | Action | What Was Done |
|---|---|---|
| `app/Http/Resources/ProjectResource.php` | Created | 7-field allowlist (`id, key, name, description, issues_count, updated_at, archived`), `@mixin Project` |
| `app/Http/Controllers/Api/V1/ProjectController.php` | Created | `index()`, `show()` per design D-1/D-2/D-4/D-5 |
| `routes/api.php` | Modified | +2 GETs (`api.v1.projects.index`, `api.v1.projects.show`) inside the existing `auth:sanctum`+`abilities:mobile` group |
| `openapi/v1.json` | Modified | +1 tag (`Projects`), +2 operations (`listProjects`, `getProject`), +4 schemas (`Project`, `ProjectResponse`, `ProjectCollectionResponse`, `NotFoundResponse`) |
| `tests/Feature/ApiProjectsTest.php` | Created | 10 tests — list ordering/scoping/empty, 401/403, N+1 guard, show active/archived, 404×3 non-disclosure |

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1 | `ApiProjectsTest.php` | Feature | ✅ 8/8 (`ApiContractTest`+`ApiAuthTest`+`McpServerTest`) | ✅ Written, confirmed 404 (route absent) | ✅ Passed | ✅ 3 cases (name-order, tie-break-by-id, empty-membership) | ➖ None needed |
| 1.2 | `ApiProjectsTest.php` | Feature | ✅ (same) | ✅ Written, confirmed 404 | ✅ Passed | ✅ 2 cases (401 no-token, 403 mcp-only) | ➖ None needed |
| 1.3 | `ApiProjectsTest.php` | Feature | ✅ (same) | ✅ Written, confirmed 404 | ✅ Passed | ✅ Verified with an injected regression (see Adversarial Verification) | ✅ Filtered counter to SELECT-only after discovering Sanctum `last_used_at` UPDATE noise |
| 1.4 | `ProjectResource.php` | — (exercised via Feature) | N/A (new) | — | ✅ Passed via 1.1 | — | ➖ None needed |
| 1.5 | `ProjectController.php::index` | — (exercised via Feature) | N/A (new) | — | ✅ Passed via 1.1-1.3 | — | ➖ None needed |
| 1.6 | `routes/api.php` | — | ✅ 8/8 (`ApiContractTest` safety net) | — | ✅ Passed | — | ➖ None needed |
| 1.7 | `openapi/v1.json` | Contract | ✅ 8/8 | ✅ Implicit (route undocumented → `ApiContractTest` red) | ✅ Passed | ➖ Single (contract shape is fixed) | ➖ None needed |
| 2.1 | `ApiProjectsTest.php` | Feature | ✅ 10/10 (Phase 1 suite) | ✅ Written, confirmed 404 (route absent) | ✅ Passed | ✅ 2 states (active, archived-after-delete) | ➖ None needed |
| 2.2 | `ApiProjectsTest.php` | Feature | ✅ (same) | ⚠️ Written but not independently RED — see note below | ✅ Passed | ✅ 3 cases (unknown/non-member/force-deleted) | ✅ Verified with an injected disclosure regression |
| 2.3 | `ProjectController.php::show` | — (exercised via Feature) | N/A (new) | — | ✅ Passed via 2.1-2.2 | — | ➖ None needed |
| 2.4 | `routes/api.php` | — | ✅ 10/10 | — | ✅ Passed | — | ➖ None needed |
| 2.5 | `openapi/v1.json` | Contract | ✅ 10/10 | ✅ Implicit (undocumented → red) | ✅ Passed | ➖ Single | ➖ None needed |

### Test Summary
- **Total tests written**: 10
- **Total tests passing**: 10
- **Layers used**: Feature (10), Contract (0 new — reused `ApiContractTest`)
- **Approval tests** (refactoring): None — no refactoring tasks
- **Pure functions created**: 0 (Laravel resource/controller layer, not applicable)

## Adversarial Verification (beyond standard RED/GREEN)

Two production-code regressions were injected and confirmed caught, to prove the guard tests exercise real behavior rather than trivially passing:

1. **N+1 guard (1.3)**: replaced `withCount('issues')` with a per-row `$projects->each(fn ($p) => $p->loadCount('issues'))`. Query count for 5 projects jumped from 4 to 8 (asserted equal to the 1-project baseline of 4); test correctly failed. Reverted, re-confirmed green.
2. **Non-disclosure guard (2.2)**: changed `show()` to a global `Project::withTrashed()->where('key', ...)->firstOrFail()` + `Gate::authorize('view', $found)`, reintroducing the rejected D-1 option. The non-member test correctly failed with `403` instead of `404`. Reverted to the owner-scoped query, re-confirmed green.

## Note on Task 2.2's RED status

`tasks.md` specifies task 2.2 as RED-first. Three of its four sub-cases (unknown key, non-member key, force-deleted key) already returned the byte-identical generic `404 {"message":"Recurso no encontrado."}` body *before* the show route existed, because Laravel's own unmatched-route `api/*` 404 handler (`bootstrap/app.php:80-82`, committed at `f8aa467`) coincidentally emits the same body as the per-key non-disclosure 404. Only the active/archived-resolution test (2.1) produced a genuine RED failure (404 received, 200 expected) proving the endpoint didn't exist yet. This is a property of the design, not a test defect — see design.md's D-1: "The three `no row` causes... are the same branch." To compensate for the reduced RED signal on 2.2, I ran the adversarial verification above (injected disclosure regression) to prove these three tests have real teeth once the endpoint exists.

## Implementer Traps — Outcomes

1. **Table-qualified ordering**: shipped `orderBy('projects.name')->orderBy('projects.id')`, both qualified. Verified the ambiguous-column risk is real by inspection of `project_members`' own `id`/timestamps columns; did not need to reproduce the SQL error to confirm since the qualified form was written from the start per design.
2. **`withTrashed()` on `BelongsToMany`**: **shipped `withTrashed()`** (the reasoned approach, not the `withoutGlobalScope(SoftDeletingScope::class)` fallback). Task 2.1's RED test passed cleanly on GREEN — `Relation::__call` does forward through to the underlying Eloquent builder as design.md predicted. No fallback needed.
3. **OpenAPI path**: documented as `/api/v1/projects/{project}` (not `{project:key}`); `ApiContractTest` passed on first run after the schema addition, confirming `$route->uri()` and the documented path match literally.
4. **Commit independence**: Phase 1 was verified fully green (`ApiProjectsTest` + `ApiContractTest`) before starting Phase 2. Both phases pass independently; no actual `git commit` was made per session instructions — the orchestrator handles commits. The diff remains cleanly splittable along the two endpoint boundaries.
5. **Non-disclosure**: all three cases (unknown, non-member, force-deleted) asserted in one shared helper `assertProjectNotFound()`; adversarially verified (see above).
6. **N+1 guard**: implemented as a real query-count assertion via `DB::listen`, not a code-review convention; adversarially verified (see above). Discovered and fixed one piece of test noise: Sanctum's `last_used_at` touch on the bearer token is dirty-checked and only issues an `UPDATE` when the value changes to-the-second, so raw total-query-count comparison was flaky. Fixed by filtering the counter to `SELECT` statements only (see Deviations).
7. **Pagination forward-compat rule**: shipped as `description` text on the `listProjects` operation in `openapi/v1.json`, not as a code comment.

## Deviations from Design

1. **N+1 test counts SELECTs only, not all queries.** `design.md`'s D-4 describes a raw `DB::listen` counter without specifying a statement-type filter. During implementation, comparing raw total query counts between the two measured requests was flaky: Sanctum's `updateLastUsedAt()` (`vendor/laravel/sanctum/src/Guard.php:162-172`) skips its `UPDATE` when the token's `last_used_at` value doesn't change to-the-second (Eloquent's dirty-check short-circuits `save()`), so identical requests made within the same wall-clock second produced different total query counts for reasons unrelated to N+1. Filtered the counter to `SELECT` statements only — this still catches the N+1 regression (verified by injection, see Adversarial Verification) while removing the auth-layer timing noise. Also added `$this->app['auth']->forgetGuards()` before each measured call, mirroring the existing pattern documented in `ApiAuthTest.php:40-45`, so both calls pay identical auth-resolution query cost.
2. **`ProjectController::show()` was implemented before its route was registered (Phase 1, ahead of task 2.3's sequencing).** I wrote both controller methods in one file for cohesion when creating `ProjectController.php` at task 1.5. This means task 2.1/2.2's RED state was driven entirely by the missing route registration (task 2.4), not by missing controller code — the controller code was already correct and dormant. RED was still genuine (404 vs expected 200/404-with-specific-body), and GREEN was still driven by a real change (adding the route). Noted here per the "don't silently deviate" rule; does not affect correctness or the adversarial verification above.
3. **Test file ordering/tie-break test rewritten mid-flight.** The first draft of the "two projects sharing a name" test compared `pluck('id')` on both calls without an `assertOk()` gate first; at RED it passed trivially (both calls 404'd, both empty-collection comparisons matched). Caught via self-review before moving to GREEN, rewrote to assert `assertOk()` and a concrete non-empty expected ID sequence before comparing. Documented here as a real quality gate that fired during implementation, not a design deviation.

## Issues Found

None — the design held up exactly as documented, including the two most load-bearing claims (D-1's `withTrashed()` forwarding through `Relation::__call`, and the `{project}` vs `{project:key}` OpenAPI path match).

## Workload / PR Boundary

- Mode: **size:exception** (pre-authorized by the owner for this session)
- Suggested work units: Unit 1 (list, PR 1 commit 1/2), Unit 2 (show, PR 1 commit 2/2) — both implemented and independently verified green, in that order
- Actual authored diff: `routes/api.php` (+3), `openapi/v1.json` (+169), `ProjectController.php` (56 new), `ProjectResource.php` (37 new), `ApiProjectsTest.php` (211 new) = **~476 lines**, above the ~380 forecast but covered by the granted `size:exception`. The overrun vs. forecast is almost entirely the adversarial-verification-informed N+1 test (comments + SELECT-filtering) and the 4-case non-disclosure test block, both of which the design explicitly called for as non-negotiable (D-4, spec.md "Not Found And Non-Disclosure").
- Rollback boundary: unchanged from design — reverting is a full revert of this branch's diff; `bootstrap/app.php` untouched, so the `f8aa467` error contract survives a revert.
- No `git commit` was executed — orchestrator handles commits. The diff is structured so it can still be split into the two designed commits without reordering hunks.
