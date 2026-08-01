# Proposal: Issues Read API (`api-issues`)

## Intent

`api-projects` gave `posterMobile` a project list and a project detail screen. Tapping a project
currently leads nowhere: `/api/v1` exposes five operations and none of them returns an issue
(`routes/api.php:7-16`). This change ships the issue read surface — the board/backlog list and the
issue detail — and pins the `Issue` wire shape that the Flutter client will depend on for its whole
life. It is change 3 of 9; `api-board-sprints`, `api-labels-comments`, and `api-issues-write` all
nest under the URL and inherit the resource conventions decided here.

## Scope decision: **read-only, again**

Writes (create, update, move) are deferred to a follow-up **`api-issues-write`**, which MUST land
after `api-board-sprints`. Reasons, in order of weight:

1. **Dependency, not budget.** `POST` and `PATCH` on an issue require a `board_column_id`, and
   optionally a `sprint_id` (`StoreIssueRequest` via `IssueController::store`,
   `app/Http/Controllers/IssueController.php:25-41`). A mobile client has **no endpoint that
   enumerates board columns or sprints** — that is `api-board-sprints`. Shipping issue writes now
   would mean the phone posting an id it cannot discover. The move endpoint is worse: it needs a
   target column *and* a position inside a `(board_column_id, sprint_id)` scope
   (`Issue::reorderScope`, `app/Models/Issue.php:193-220`).
2. **Budget.** Reads alone forecast ~670 lines. Writes add three operations, hierarchy validation
   (`openspec/specs/issues/spec.md:42-67`), cross-project column/sprint rejection, gap-closing on
   move, ~250 lines of hand-authored OpenAPI, and the 35 web scenarios' API equivalents → ~1300 in
   one PR.
3. **Risk class.** Reads risk disclosure and N+1. Writes risk ordering corruption under concurrency.
   Mixing them in one review buries both.

Reads are complete on their own: the client can render a project's issue list and open any issue.

### In Scope

- `GET /api/v1/projects/{project}/issues` — the project's issues, board-ordered, paginated, with an
  optional `sprint` filter.
- `GET /api/v1/projects/{project}/issues/{issue}` — one issue by its human key (`PROJ-123`).
- `App\Http\Resources\IssueResource` (list shape) and `IssueDetailResource` (detail shape).
- Labels, assignee, reporter, parent, children, and comments as **embedded** data (Decision 4).
- `openapi/v1.json` delta: 2 operations, 1 tag, ~9 schemas.

### Out of Scope

- Create / update / move (`api-issues-write`, gated on `api-board-sprints`).
- Board columns and sprints as first-class resources (`api-board-sprints`).
- Label and comment **mutation** endpoints and the project label catalogue
  (`api-labels-comments`). That change MUST NOT add a redundant issue-comment *read* endpoint —
  Decision 4 already serves reads.
- A cross-project "my issues" feed. Deferred until a screen needs it.
- Full-text search on `title`. Deferred.
- Anything Flutter.

## Capabilities

### New Capabilities

- `api-issues`: the `/api/v1/projects/{project}/issues` read surface — its key resolution, its two
  pinned resource shapes, its board ordering, its pagination envelope, its `sprint` filter, and its
  non-disclosure guarantees.

### Modified Capabilities

- **None.** `issues` (`openspec/specs/issues/spec.md`) describes web behavior and is unchanged: this
  change mirrors it over HTTP without altering a single web requirement. `api-projects` and
  `api-auth` are likewise untouched — the error contract (`bootstrap/app.php`) already ships the
  401/403/404 bodies this change consumes.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | **URLs use the human key**: `GET /api/v1/projects/{project}/issues/{issue}` where `{issue}` is `PROJ-123`. Resolution is `Issue::resolveByKey($project, $issueKey)` (`app/Models/Issue.php:255-274`) inside the controller — **never** route-model binding. | `key` is an un-persisted accessor (`app/Models/Issue.php:66,87-92`) built from `$this->project->key` and `$this->number`; `where('key', ...)` is not a query that exists. The web already resolves this way (`IssueController::resolveIssue`, `app/Http/Controllers/IssueController.php:99-113`), the helper already returns `null` rather than throwing on every malformed input, and issue numbers are **never reused or renumbered** — there is no issue-delete path anywhere in the app (no `destroy` on `IssueController`, no `DeleteIssue` MCP tool; `Issue::closeGapInScope` renumbers `position`, not `number`). The key is therefore a permanent identifier, safe to deep-link and cache. |
| 2 | **Nested under the project**, not flat with `?project=`. | `Issue::resolveByKey` *requires* a `Project` to validate the prefix against; nesting supplies it from the URL for free. The two mobile screens (board, backlog) are both project-scoped — a flat route would carry a mandatory query param, which is a nested route with worse ergonomics. Nesting also inherits `api-projects`' owner-scoped `firstOrFail()` non-disclosure by construction (Decision 6). `routes/web.php:70-91` nests identically. |
| 3 | **Two resources, not one with conditionals.** `IssueResource` (list): `id`, `key`, `number`, `title`, `type`, `priority`, `story_points`, `due_date`, `board_column_id`, `sprint_id`, `parent_id`, `position`, `assignee`, `labels`, `updated_at`. `IssueDetailResource` (show): those 15 **plus** `description`, `reporter`, `parent`, `children`, `comments`. | A board card needs chips and an avatar; it does not need a 4 KB description ×50 rows on a phone connection. Conditional fields inside one resource would break the `toBe()` exact-shape assertions that `ApiProjectsTest.php:68-71` established. `type` and `priority` are pinned to `->value`, not the raw enum, so the wire format cannot drift if the enum gains an interface. **Deliberately absent from both**: `project_id` (the URL carries it), `created_at` (no client use; additive later), `reporter` on the list (constant in a single-owner app). The detail shape mirrors `IssueController::presentIssue` (`app/Http/Controllers/IssueController.php:130-176`) so the phone and the web modal show the same issue. |
| 4 | **Labels and comments are embedded**, never fetched separately. `labels` = `[{id, name}]` on both shapes; `comments` = `[{id, body, created_at, author:{id,name}}]` ordered by `created_at` ascending, on detail only. `assignee`/`reporter` = `{id, name}` or `null`. `parent` = `{id, key, title}`. `children` = `[{id, key, title, type, board_column_id}]`. | `Label` has exactly `id`, `project_id`, `name` (`app/Models/Label.php:14-20`) — there is no colour to expose. The web loads precisely `labels:id,name`, `assignee:id,name`, `reporter:id,name` (`IssueController.php:101-108`); matching it keeps one representation across surfaces. Embedding is what makes the detail screen a single round trip. This does **not** conflict with `api-labels-comments`, which owns *mutation* and the project-level label catalogue. |
| 5 | **Ordering: true board order.** `JOIN board_columns ON board_columns.id = issues.board_column_id`, `ORDER BY board_columns.position, issues.position, issues.id`, with `->select('issues.*')`. | Deterministic and page-stable, which plain `board_column_id` ordering is not: `boardColumns()` is itself ordered by `position` (`app/Models/Project.php:60-63`), and column position is what the board renders left-to-right. The `issues.id` tiebreaker is **mandatory, not decorative** — `position` is unique only within a `(board_column_id, sprint_id)` scope (`Issue::scopedToColumnAndSprint`, `app/Models/Issue.php:279-288`), so two issues in the same column from different sprints legitimately share `position: 0`. **Implementer trap:** without `->select('issues.*')` the joined `board_columns.id` and `board_columns.position` overwrite the issue's own `id` and `position` during hydration. |
| 6 | **Pagination: yes, a real Laravel paginator.** `?page`, `?per_page` (default 50, max 100). Response is `{data, links, meta}`. | This is the API's first unbounded collection — a project holds hundreds of issues where it holds tens of projects. Shipping it uncapped would send a multi-hundred-row payload to a phone. Shipping it *capped without* `meta` would **violate** the client rule already published in `openspec/changes/api-projects/design.md:100-105` ("if `meta` is absent, `data` is the complete set"). That rule was written to anticipate exactly this: `data` stays a top-level array and only `links`/`meta` are added, so a client built against `api-projects` keeps working. `->paginate()` costs one extra `COUNT` query — constant, not an N+1. |
| 7 | **One filter: `?sprint=`**, accepting an integer sprint id or the literal `backlog` (`sprint_id IS NULL`). Absent = every issue in the project. A sprint id not in `$project->sprints()` → `404`. A malformed value → `422`. | The board screen filters by the viewed sprint and the backlog screen is `sprint_id IS NULL` — the same split the web makes (`BacklogController`, `routes/web.php:70`). Without it the phone downloads the whole project and filters locally, which is the anti-pattern pagination exists to prevent. `404` on a foreign sprint id keeps Decision 8's non-disclosure whole; `422` on `?sprint=abc` is a client bug, not a hidden resource, and reuses the existing `ValidationErrorResponse` schema (`openapi/v1.json`). Assignee/type/priority filters are deferred — no screen asks for them yet. |
| 8 | **404 non-disclosure covers seven cases**, all byte-identical `{"message": "Recurso no encontrado."}`: unknown project key; non-member project key; **archived (soft-deleted) project**; malformed issue key with no dash; non-numeric or empty suffix; prefix mismatch (`OTHER-1` under `/projects/PROJ/`); unknown number in this project. | Two converging paths, neither of which *can* branch on the reason: the owner-scoped `firstOrFail()` from `ProjectController::show` (`app/Http/Controllers/Api/V1/ProjectController.php:47-52`), then `abort_if(Issue::resolveByKey(...) === null, 404)`. Note the deliberate **divergence** from `api-projects`: `show` there uses `withTrashed()`, this change does **not** — the web spec already requires 404 for an archived project's issue deep link (`openspec/specs/issues/spec.md:69-95`). Case 6 is why nesting matters: a flat route could not detect a prefix mismatch. |
| 9 | **N+1 guard: `setRelation('project', $project)` plus two eager loads plus a query-count pin.** List: `->with(['assignee:id,name', 'labels:id,name'])` and `setRelation('project', $project)` on every row. Detail: `->load(['labels:id,name','assignee:id,name','reporter:id,name','parent','children','comments.author:id,name'])` plus `setRelation('project', $project)` on the issue **and** its parent **and** each child. | The `key` accessor reads `$this->project->key`, so **merely serializing an issue touches `project`** — one lazy query per row, per child, per parent. Because the query root is `$project->issues()`, every row provably belongs to the already-resolved `$project`, so `setRelation` is exact and costs **zero** queries (eager-loading `project` would cost one). This is the established fix (`IssueController.php:115-128`). `Model::preventLazyLoading` is not enabled globally (`AppServiceProvider::configureDefaults`), so the guard is a test: enable it *inside* the N+1 test — unlike `api-projects`' `issues()->count()` blind spot, it **does** catch this one — and assert an identical query count for 1 issue vs 5, paired with value assertions (labels present, assignee correct) to catch a dropped eager load that silently returns `null`. |
| 10 | **OpenAPI paths are `/api/v1/projects/{project}/issues` and `/api/v1/projects/{project}/issues/{issue}`.** | `RouteUri::parse` strips the binding field from the stored URI and `ApiContractTest` compares documented paths against `$route->uri()` in both directions (`tests/Feature/ApiContractTest.php:14-21,52-67`). Decisions 1-2 use plain string parameters with no binding, so route and document match literally — but the trap survives if anyone "improves" the route to `{issue:key}`, which cannot work anyway (Decision 1). Both operations declare `security: [{"bearerAuth": []}]` (`ApiContractTest.php:69-86`). Tag: `Issues`. |

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `routes/api.php` | Modified | +2 GETs inside the existing `['auth:sanctum','abilities:mobile']` group (lines 11-16); names `api.v1.projects.issues.index` / `.show` |
| `app/Http/Controllers/Api/V1/IssueController.php` | New | `index`, `show`, plus a private owner-scoped project resolver |
| `app/Http/Resources/IssueResource.php` | New | 15-field list shape, `@mixin Issue` |
| `app/Http/Resources/IssueDetailResource.php` | New | 20-field detail shape |
| `openapi/v1.json` | Modified | +1 tag, +2 operations, ~9 schemas (`Issue`, `IssueDetail`, `IssueRef`, `IssueChildRef`, `IssueComment`, `LabelRef`, `UserRef`, `PaginationLinks`, `PaginationMeta`) |
| `tests/Feature/ApiIssuesTest.php` | New | list shape, ordering, pagination, sprint filter, 404 ×7, 401/403, N+1 ×2, detail shape |
| `app/Models/Issue.php`, `Project.php`, `IssuePolicy`, `routes/web.php` | **Unchanged** | `resolveByKey` and the relations are reused as-is |
| `bootstrap/app.php` | **Unchanged** | 401/403/404 renders committed in `api-token-auth` / `api-projects` |
| migrations | **None** | no schema change |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| The `key` accessor N+1 ships unnoticed (7 relations, `preventLazyLoading` off globally) | **High** | Decision 9: `setRelation` + query-count equality test with `preventLazyLoading` enabled locally, which *does* catch a lazy `project` access. This is the single most likely defect in the change |
| The join in Decision 5 clobbers `issues.id` / `issues.position` during hydration | Med | Named as an implementer trap; the exact-shape `toBe()` assertion on `id` and `position` fails loudly if `->select('issues.*')` is missing |
| Pagination envelope diverges from `api-projects`' bare `{data}` and confuses the client | Low | The forward-compatible client rule was pre-published in `api-projects/design.md:100-105` precisely for this; restate it in the OpenAPI description |
| Embedded `comments` grows unbounded on a hot issue | Med | Single-owner app; threads are short in practice. If it bites, `api-labels-comments` adds a paginated comments endpoint — purely additive, and the embed can then be capped in a v2 |
| Deferring writes strands the mobile client again | Med | Accepted and sequenced: `api-board-sprints` → `api-issues-write`. Writes are **blocked** on discoverable column/sprint ids, not merely postponed |
| `{issue}` documented as `{issue:key}` | Med | `ApiContractTest` fails set-equality in both directions; Decision 10 names it |
| `IssueResource` name collides with `api-issues-write`'s needs | Low | Additive by construction — writes reuse the same resource for their response bodies |

## Rollback Plan

No migration, no schema change, no row written or mutated by this change — both endpoints are `GET`.

Revert the branch merge. That deletes `IssueController`, `IssueResource`, and `IssueDetailResource`,
restores `routes/api.php` to five routes, and restores `openapi/v1.json` to five operations.
`bootstrap/app.php`, `app/Models/Issue.php`, and every web route are untouched by this change, so
nothing outside `/api/v1` can regress. `ApiContractTest` re-greens automatically because the revert
removes routes and documentation together.

There is no data cleanup after revert, and no client-visible state to reconcile beyond a released
Flutter build losing two endpoints — mitigated by not shipping the mobile screens until this merges.

## Dependencies

- **None new.** No Composer or npm package. Builds on `api-token-auth` and `api-projects`, both merged.
- `api-issues-write` depends on **this change plus `api-board-sprints`** (Decision, scope section).

## Delivery Forecast

Estimated **~670 changed lines** against the 400-line review budget → **400-line budget risk: High**
(~1.7x). `size:exception` is pre-authorized for this session, so this ships as **one PR with two
atomic commits**, each leaving `php artisan test --compact` green:

| # | Scope | ~Lines |
|---|---|---|
| 1 | `IssueResource`, `IssueController::index` (ordering, pagination, `sprint` filter), list route, OpenAPI `listProjectIssues` + `Issue`/`LabelRef`/`UserRef`/`IssueCollectionResponse`/`PaginationLinks`/`PaginationMeta`, list tests incl. N+1 | ~370 |
| 2 | `IssueDetailResource`, `IssueController::show`, show route, OpenAPI `getProjectIssue` + `IssueDetail`/`IssueRef`/`IssueChildRef`/`IssueComment`, detail + 404×7 tests | ~300 |

Commits cannot be split by layer (code, then docs): `ApiContractTest` asserts route↔document
set-equality in **both** directions (`ApiContractTest.php:52-67`), so a route without its operation
is red. If the owner reverses the exception, commit 2 stacks cleanly as a child PR — it shares only
`routes/api.php`, `openapi/v1.json`, and the controller with commit 1.

`npm run build` is not required (no front-end surface). Run `vendor/bin/pint --dirty` before each
commit. Strict TDD: RED before code, inside the same commit.

## E2E Acceptance

Feature-level HTTP in `tests/Feature/ApiIssuesTest.php`, following the `ApiProjectsTest` convention
(`tests/Feature/ApiProjectsTest.php:56-72`): a real bearer token driving real requests, never
`actingAs`, so the guard → ability → project scoping → key resolution → resource chain is exercised
end to end. Reuse the existing `createMemberProject()` and `apiBearerHeaders()` helpers.

Browser E2E is **not** proposed and MUST NOT gate this merge: this change adds no browser surface,
and the browser suite is red from a pre-existing environment defect (headless browser renders blank
pages) that is out of scope here.

## Success Criteria

- [ ] `GET /api/v1/projects/{key}/issues` returns `{data, links, meta}`; every `data` element has
      exactly the 15 Decision-3 fields — asserted with `toBe()` so a leaked column fails.
- [ ] Issues come back in board order (`board_columns.position`, `issues.position`, `issues.id`) and
      the order is identical across two consecutive calls, including across a page boundary.
- [ ] `?sprint={id}` returns only that sprint's issues; `?sprint=backlog` returns only
      `sprint_id IS NULL`; a foreign sprint id returns 404; `?sprint=abc` returns 422.
- [ ] `GET /api/v1/projects/{key}/issues/PROJ-1` returns the 20-field detail shape with `labels`,
      `assignee`, `reporter`, `parent`, `children`, and `comments` embedded.
- [ ] All seven Decision-8 cases return `404 {"message":"Recurso no encontrado."}` with no
      `App\Models\Issue` or `App\Models\Project` substring in the body.
- [ ] Query count for the list is identical with 1 issue and with 5, with
      `Model::preventLazyLoading()` enabled inside the test; `labels` and `assignee` values are
      asserted, not just counted.
- [ ] No token → 401 + `WWW-Authenticate: Bearer`; an `mcp`-ability token → 403 with the Spanish body.
- [ ] `ApiContractTest` passes: two new operations documented, both declaring `bearerAuth`.
- [ ] `php artisan test --compact` is green.

## Proposal question round

Interactive shaping was unavailable (`auto` mode, owner explicitly asked not to be stopped). These
assumptions are recorded for correction before `sdd-spec`:

1. **The first mobile issue screen is a board, not a flat "my issues" inbox.** Decision 5's ordering
   and Decision 7's `sprint` filter both follow from this. If the phone's home screen is instead a
   cross-project assigned-to-me feed, the nesting in Decision 2 is wrong and a flat
   `GET /api/v1/issues?assignee=me` should be pulled forward.
2. **Comments belong on the issue payload, not behind their own endpoint.** Assumed the phone opens
   an issue and reads the thread immediately, as the web modal does. If comments are lazily loaded
   on a mobile detail screen, they should move to `api-labels-comments` and come out of Decision 4.
3. **50 issues per page is the right default.** Chosen for a slow connection with ~15 scalar fields
   plus label chips per row. If the client renders an infinite-scroll board, 100 may fit better; the
   `per_page` cap makes this a one-line change either way.
4. **404 on an archived project's issues is desired.** Decision 8 follows the existing web spec, but
   it is *stricter* than `api-projects`' `show`, which returns the archived project with
   `archived: true`. A client can therefore read a project and then get 404 on its issues. If that
   inconsistency is unacceptable, the alternative is returning the issues read-only with an
   `archived` hint — reversible, but it contradicts `openspec/specs/issues/spec.md:69-95`.
5. **Assignee/type/priority filters are not needed in v1.** If the mobile board has a filter bar on
   day one, adding them later is additive but means a client release that cannot filter.
