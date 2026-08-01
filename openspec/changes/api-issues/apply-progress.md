# Apply Progress: Issues Read API (`api-issues`)

**Mode**: Strict TDD (RED → GREEN → REFACTOR), per `openspec/config.yaml testing.strict_tdd: true`.
**Status**: 37/39 tasks marked `[x]`. All 39 tasks' implementation and verification substance is complete. Ready for `sdd-verify`.

## Completed Tasks

- Phase 1 (Commit 1, list endpoint): 1.1–1.18 marked `[x]`. Task **1.19 left unchecked** — its content ("`IssueResource`, `IssueController::index`, list route, OpenAPI list delta, list tests" + "full suite green at baseline") is fully true and verified, but the task literally names a `git commit`, which was intentionally **not** run per the session instruction "Do NOT commit." Marking it `[x]` would misrepresent that a commit happened.
- Phase 2 (Commit 2, detail endpoint): 2.1–2.17 marked `[x]`. Task **2.18 left unchecked** for the identical reason as 1.19.
- Phase 3 (full suite verification): 3.1–3.2 marked `[x]` — done.

## Files Changed

| File | Action | What Was Done |
|---|---|---|
| `app/Http/Resources/IssueResource.php` | Created | 15-field list allowlist, `@mixin Issue`, `type`/`priority` as `->value`. |
| `app/Http/Resources/IssueDetailResource.php` | Created | Reuses `IssueResource::toArray()` for the 15 shared fields, adds `description`, `reporter`, `parent`, `children`, `comments` — field-for-field `IssueController::presentIssue`. |
| `app/Http/Controllers/Api/V1/IssueController.php` | Created | `index()` (board-ordered, paginated, sprint-filtered list) and `show()` (key-resolved detail), private `resolveProject()`. |
| `app/Http/Requests/Api/V1/ListProjectIssuesRequest.php` | Created | `per_page`/`page`/`sprint` validation with Spanish `messages()` — see Deviations. |
| `routes/api.php` | Modified | +2 GETs inside the existing `['auth:sanctum','abilities:mobile']` group: `api.v1.projects.issues.index`, `api.v1.projects.issues.show`. |
| `openapi/v1.json` | Modified | +1 tag (`Issues`), +2 operations (`listProjectIssues`, `getProjectIssue`), +11 schemas (`Issue`, `IssueDetail`, `IssueRef`, `IssueChildRef`, `IssueComment`, `LabelRef`, `UserRef`, `PaginationLinks`, `PaginationMeta`, `IssueResponse`, `IssueCollectionResponse`). |
| `tests/Feature/ApiIssuesTest.php` | Created | 18 tests: `issuePayload()`/`issueDetailPayload()`/`assertIssueNotFound()` helpers, list shape/order/tiebreaker/pagination/filter/auth/N+1, detail shape/comment-order/404×7. |

## TDD Cycle Evidence

| Task(s) | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 1.1–1.10 (list, 9 tests) | `tests/Feature/ApiIssuesTest.php` | Feature | N/A (new file) | ✅ Written — ran against no route, 8/9 failed on `404≠200/401`, the 9th (non-member) passed trivially because route-404 already matches the generic-404 expectation (flagged, not a false GREEN — exercised for real once the route exists) | ✅ 9/9 passed after `IssueResource` + `IssueController::index` + route | ✅ Multiple cases per test (shape, hydration trap, order, tiebreaker, pagination boundary, 6-way validation, auth, non-member, N+1×2) | ✅ Extracted `ListProjectIssuesRequest` for Spanish validation messages (see Deviations) |
| 1.15–1.17 (OpenAPI list) | `tests/Feature/ApiContractTest.php` (pre-existing, no edit) | Contract | ✅ 2/2 passing before this change | N/A — additive documentation, not a new test file | ✅ 2/2 passed after tag+operation+schemas added | ➖ N/A | ➖ None needed |
| 2.1–2.9 (detail, 9 tests) | `tests/Feature/ApiIssuesTest.php` | Feature | ✅ 9/9 (Phase 1 tests) still passing before adding these | ✅ Written — ran against no `show` route, 2/9 failed on `404≠200` (shape, comment-order); the 7 not-found cases passed trivially for the same route-404 reason as above, exercised for real once the route exists | ✅ 18/18 passed after `IssueDetailResource` + `IssueController::show` + route | ✅ 7 distinct 404-cause tests plus comment-tiebreaker triangulation | ➖ None needed — mirrored `IssueController::presentIssue` directly |
| 2.14–2.16 (OpenAPI detail) | `tests/Feature/ApiContractTest.php` | Contract | ✅ 2/2 passing before this change | N/A — additive documentation | ✅ 2/2 passed after operation+schemas added | ➖ N/A | ➖ None needed |

### Test Summary

- **Total tests written**: 18 (`tests/Feature/ApiIssuesTest.php`)
- **Total tests passing**: 18/18
- **Layers used**: Feature (18), Contract (0 new — reused existing `ApiContractTest.php`, 2 tests re-verified)
- **Approval tests** (refactoring): None — no refactoring tasks, only new code
- **Pure functions created**: 0 — this surface is entirely I/O (HTTP request → Eloquent query → JSON resource); the two Fake-It-avoidance boundaries are: (a) `issuePayload()`/`issueDetailPayload()` test helpers, which read expected values directly off the model/relations rather than hardcoding them, and (b) production resources/controller are query+mapping code, not computation, so extraction to a pure function would not reduce complexity

## Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `php artisan test --compact --filter=ApiIssuesTest` → `{"result":"passed","tests":18,"passed":18,"assertions":103}` |
| Runtime harness command/scenario and exact result | `php artisan test --compact` (full suite) → `{"result":"failed","tests":466,"passed":460,"assertions":1428,"failed":6}` — the 6 failures are byte-identical to the pre-existing baseline (`AppearanceTest`, `HabitFlowTest`×2, `McpTokenFlowTest`, `SmokeTest`, `MobileTokenRevokeFlowTest`); baseline was 448/442/6 before this change, now 466/460/6 (448+18 new tests, all 18 passing, 0 new failures) |
| Rollback boundary | Commit 1 (list): revert deletes `IssueResource`, `IssueController::index`, the list route, the `listProjectIssues` OpenAPI delta, and the list-half of `ApiIssuesTest.php` — nothing outside this file set. Commit 2 (detail): revert deletes `IssueDetailResource`, `IssueController::show`, the show route, the `getProjectIssue` OpenAPI delta, and the detail-half of `ApiIssuesTest.php`; shares only `routes/api.php`, `openapi/v1.json`, and `IssueController.php` with commit 1. Neither commit touches `app/Models/Issue.php`, `Project.php`, `IssuePolicy`, `routes/web.php`, or `bootstrap/app.php` |

## Deviations from Design

1. **Query-param validation moved to a dedicated `FormRequest`, not inline `$request->validate()`.** Design's prose said "validated by `$request->validate()`" and its data-flow diagram showed validation as a step inside the request lifecycle. Every existing validated endpoint in this codebase (`LoginRequest`, `StoreIssueRequest`, `UpdateIssueRequest`, etc.) uses a `FormRequest` subclass with Spanish `messages()`, and the repository-wide constraint is "user-facing messages SPANISH." Inline `$request->validate()` would have produced Laravel's default **English** validation strings (`app.locale` is `en`; Spanish copy here comes entirely from explicit `messages()` overrides, never a global lang file). I created `App\Http\Requests\Api\V1\ListProjectIssuesRequest` instead, mirroring `App\Http\Requests\Api\V1\LoginRequest`'s location and style. This also has a beneficial side effect: Laravel validates a type-hinted `FormRequest` during controller-method dependency injection, **before** the method body runs — so `$request->validated()` executing before `resolveProject()` automatically enforces the design's stated fail order (query-shape `422` before project-existence `404`) without any extra code, exactly matching the Data Flow diagram's ordering (`validate → firstOrFail → sprint exists`). No test, route, or wire-format changed; this is purely where the validation rules live.
2. **`IssueDetailResource` composes `IssueResource::toArray()` instead of repeating the 15 fields.** Design's Interfaces/Contracts section showed the full field list inline for illustration. I built `IssueDetailResource::toArray()` as `[...(new IssueResource($this->resource))->toArray($request), 'description' => ..., ...]` to keep the 15-field list defined in exactly one place. Wire output is byte-identical to writing it out twice — verified by the `toBe()` exact-shape assertions in both `ApiIssuesTest` tests (1.2/1.3 for the list, 2.1 for the detail).
3. **Authored line count is ~1,111, not the ~670 estimated in `design.md`/`tasks.md`.** New files total 737 lines (`IssueController.php` 118, `ListProjectIssuesRequest.php` 51, `IssueDetailResource.php` 61, `IssueResource.php` 53, `ApiIssuesTest.php` 454) plus 374 insertion lines across `openapi/v1.json` (371) and `routes/api.php` (3). The `size:exception` pre-authorization in the session instructions was general ("~670 lines... PRE-AUTHORIZED. Proceed.") rather than a hard ceiling, and the two-commit endpoint split from `design.md`'s Commit Boundaries table is preserved exactly as designed (list vs. detail, sharing only `routes/api.php`, `openapi/v1.json`, `IssueController.php`). Flagging the delta rather than silently absorbing it.

No other deviations. Every trap called out in the session brief was implemented as specified:

- `setRelation('project', $project)` on every list row, the detail issue, its parent, and each child.
- N+1 test pairs the query-count assertion with `assignee.name`/`labels[0].name` value assertions for all 5 issues, not just a count.
- `->select('issues.*')` on the board-column join; the hydration-trap fixture places its issue in the **third** default column (`Done`, position 2) at `position: 0`.
- `->withQueryString()` on the list paginator; the pagination test crosses a page boundary (`per_page=1` over 2 filtered issues) **with** `?sprint=` applied and asserts `links.next` retains it.
- `labels`/`children`/`comments` all carry an id tiebreaker in both the query `ORDER BY` and the test's expected-payload builders — the comment test explicitly forces two comments to share one `created_at` second.
- All seven 404 non-disclosure cases have a dedicated test (unknown project, non-member project, archived project, no-dash key, prefix-mismatch key, non-numeric/empty-suffix key ×2, unknown-number key).
- Archived project 404s its issues while `GET /api/v1/projects/{key}` on the same key still resolves with `archived: true` — asserted in one test to make the divergence explicit, matching `openspec/specs/issues/spec.md:69-95`.
- OpenAPI paths use literal `{issue}`, never `{issue:key}`.

## Issues Found

None — no pre-existing test broke, no design ambiguity blocked implementation.

## Status

**37/39 tasks marked `[x]`; all 39 tasks' substance complete** (the 2 unchecked, 1.19 and 2.18, are the two `git commit` steps — intentionally not executed per instruction, see Completed Tasks above). `php artisan test --compact` full suite: 466 total, 460 passed, 6 failed — the 6 failures are the pre-existing baseline (headless-browser defects), byte-identical to the session's stated baseline before this change. Every test this change added passes. `vendor/bin/pint --dirty --format agent` is clean. No commit was made, per instruction.

**Ready for `sdd-verify`.**
