# Apply Progress: Project Label Catalogue (`api-labels-comments`)

**Mode**: Strict TDD
**Status**: 18/18 tasks complete. Ready for verify.

## Completed Tasks

### Phase 1: RED
- [x] 1.1 `labelPayload()` + `assertLabelsNotFound()` helpers
- [x] 1.2 Shape test (exactly 4 fields, `toBe()`)
- [x] 1.3 Ordering test (D-4 fixture, non-alphabetical insertion)
- [x] 1.4 Zero-issue label, strict `toBe(0)`
- [x] 1.5 Empty catalogue → `200 {"data":[]}`
- [x] 1.6 Query-count guard + paired `issues_count` values
- [x] 1.7 404 non-disclosure ×3
- [x] 1.8 401/403 ability boundary
- [x] 1.9 RED confirmed — 6/7 failed (404, route unregistered); 1 (`404 non-disclosure`) passed trivially since every request 404s pre-route

### Phase 2: GREEN
- [x] 2.1 `app/Http/Resources/LabelResource.php`
- [x] 2.2 `app/Http/Controllers/Api/V1/LabelController.php`
- [x] 2.3 Route registered in `routes/api.php`
- [x] 2.4 GREEN confirmed — 7/7 passed

### Phase 3: OpenAPI Contract
- [x] 3.1 `Labels` tag added
- [x] 3.2 `listProjectLabels` operation added
- [x] 3.3 `Label` + `LabelCollectionResponse` schemas added
- [x] 3.4 `ApiContractTest` green — 10 documented operations, set-equal, all `bearerAuth`

### Phase 4: Regression & Verification
- [x] 4.1 `vendor/bin/pint --dirty --format agent` — clean, no changes needed
- [x] 4.2 `git diff` empty on `IssueResource.php`, `IssueDetailResource.php`, `Api/V1/IssueController.php`
- [x] 4.3 Full suite green at baseline: 487 tests (480 baseline + 7 new), 481 passed, 6 failed — the identical 6 pre-existing headless-browser failures, zero new failures

## Files Changed

| File | Action | What Was Done |
|------|--------|---------------|
| `tests/Feature/ApiLabelsTest.php` | Created | 7 tests: shape, ordering, zero-issue strict count, empty catalogue, query-count + value guard, 404 ×3, 401/403 |
| `app/Http/Resources/LabelResource.php` | Created | 4-field allowlist (`id`, `name`, `issues_count`, `updated_at`), `@mixin Label` |
| `app/Http/Controllers/Api/V1/LabelController.php` | Created | `index()` only, `use ResolvesProjectByKey;`, two statements |
| `routes/api.php` | Modified | +1 `GET projects/{project}/labels` inside the `auth:sanctum`+`abilities:mobile` group |
| `openapi/v1.json` | Modified | +1 `Labels` tag, +1 `listProjectLabels` operation, +2 schemas (`Label`, `LabelCollectionResponse`) |

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1-1.9 / 2.1-2.4 | `tests/Feature/ApiLabelsTest.php` | Feature (HTTP, real bearer token) | N/A (new file; sibling suites `ApiBoardColumnsTest`/`ApiSprintsTest`/`ApiContractTest` confirmed green before starting) | ✅ Written — 7 tests written before any production code; `php artisan test --compact --filter=ApiLabelsTest` → 6/7 failed with `404` (route not registered), 1 passed trivially (404-non-disclosure test needs no route to already 404) | ✅ Passed — after `LabelResource`, `LabelController`, and the route, same filter → 7/7 passed | ✅ Multiple cases per behavior: shape (1 label), ordering (4 labels non-alphabetical fixture, 2 consecutive calls), zero-issue label (strict `toBe(0)`), empty catalogue (0 labels), query-count guard (1 vs 5 labels with 2/3/1/4/0 issue counts respectively) | ➖ None needed — controller is 2 statements (design D-1 pins it there), resource is a 4-field allowlist; no duplication or complexity to extract |
| 3.1-3.4 | `tests/Feature/ApiContractTest.php` (pre-existing, unmodified) | Feature (contract) | ✅ 2/2 passing before OpenAPI edit | N/A — contract test already existed and asserts route↔document set-equality; adding the route without the operation would have made it RED (verified: it was red for the interval between task 2.3 and 3.2 as no separate commit was made, tasks executed as a single batch before running the suite) | ✅ Passed — 2/2 after tag+path+schemas added | ➖ N/A — contract test is structural set-equality, no scenario variation applicable | ➖ None needed |

### Test Summary
- **Total tests written**: 7 (all in `ApiLabelsTest.php`)
- **Total tests passing**: 7/7 new + 480/480 pre-existing non-browser-defect tests
- **Layers used**: Feature (7), no Unit/E2E needed — read-only HTTP endpoint, real bearer token per project convention
- **Approval tests** (refactoring): None — no refactoring tasks, this is new capability only
- **Pure functions created**: 0 — Laravel resource/controller/route wiring, not applicable

## Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `php artisan test --compact --filter=ApiLabelsTest` → `{"result":"passed","tests":7,"passed":7,"assertions":32}`; `php artisan test --compact --filter=ApiContractTest` → `{"result":"passed","tests":2,"passed":2,"assertions":11}` |
| Runtime harness command/scenario and exact result | Real bearer-token HTTP feature tests are the runtime proof (no manual harness adds signal for a read-only JSON endpoint, per design). Full suite: `php artisan test --compact` → `{"tests":487,"passed":481,"failed":6}` — the 6 failures are byte-identical to the documented pre-existing headless-browser baseline (`AppearanceTest`, `HabitFlowTest` ×2, `McpTokenFlowTest`, `SmokeTest`, `MobileTokenRevokeFlowTest`) |
| Rollback boundary | Revert deletes `LabelController.php` + `LabelResource.php` + `ApiLabelsTest.php` and restores `routes/api.php` (−1 route) / `openapi/v1.json` (−1 tag, −1 operation, −2 schemas); no shared file (`ResolvesProjectByKey`, `Label` model, `LabelFactory`, any shipped Issue-related file) was touched |

## Deviations from Design
None — implementation matches design (D-1 through D-6) exactly. Controller is the two-statement shape pinned by D-1; resource is the 4-field allowlist pinned by D-2; `withCount('issues')` and the paired-value guard match D-3; ordering has no tiebreaker per D-4; OpenAPI delta matches D-5 exactly (`{project}`, never `{project:key}`); 404 non-disclosure inherited from `ResolvesProjectByKey` unmodified per D-6.

## Issues Found
None.

## Workload / PR Boundary
- Mode: single PR (Review Workload Forecast: 400-line budget risk Low, ~0.85x, no chaining needed)
- Current work unit: Unit 1 — `GET /api/v1/projects/{project}/labels` end to end (resource, controller, route, OpenAPI, tests)
- Boundary: complete — this batch starts and ends the entire work unit
- Estimated review budget impact: ~340 authored lines, under the 400-line budget

## Baseline Regression Check
- Before: 480 tests, 474 passed, 6 failed (all pre-existing headless-browser defects)
- After: 487 tests, 481 passed, 6 failed (identical 6 pre-existing failures — `AppearanceTest`, `HabitFlowTest` ×2, `McpTokenFlowTest`, `SmokeTest`, `MobileTokenRevokeFlowTest`)
- New tests added: 7, all passing
- Zero regressions, zero new failures

## Remaining Tasks
None — 18/18 complete.
