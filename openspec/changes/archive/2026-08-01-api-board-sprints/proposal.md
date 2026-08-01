# Proposal: Board Columns And Sprints Read API (`api-board-sprints`)

## Intent

`api-issues` gave `posterMobile` a board-ordered issue list (`routes/api.php:17-18`), but the phone
still cannot render a board. Every issue carries a bare `board_column_id` and `sprint_id`
(`app/Http/Resources/IssueResource.php:38-40`) and there is **no endpoint that names them**: the
client receives `board_column_id: 7` with no way to learn it is called "In Progress", sits at
position 1, or that an empty column exists at all. It likewise cannot fill a sprint picker.

This change ships the two missing vocabularies — board columns and sprints — and thereby
**unblocks `api-issues-write`**, which was blocked (not merely deferred) on a phone being able to
discover the `board_column_id` and `sprint_id` it must POST
(`openspec/changes/api-issues/proposal.md:16-23`). `api-issues-write` still needs its own change
for three operations, hierarchy validation, and gap-closing on move; after this merges, nothing
blocks it. Change 4 of 9.

## Scope decision: **read-only, again**

Mirrors `api-projects` and `api-issues`. Column and sprint **mutation** is not deferred to a
planned follow-up — it is **not planned**, and would be opened only if a mobile screen asks for it.
Reasons, in order of weight:

1. **It would introduce the API's first authorization *decision*.** Every `/api/v1` endpoint today
   scopes by query constraint, never by `Gate` — which is exactly why an inaccessible resource is
   `404` and never `403` (`openspec/changes/api-projects/design.md:5-13`). But column and sprint
   management is **owner-only** (`openspec/specs/board/spec.md:76-83`,
   `openspec/specs/sprints/spec.md:44-52`), and a member-but-not-owner is a *real, authenticated,
   authorized-to-read* actor. That case cannot be a query constraint; it needs a policy call and a
   403-vs-404 ruling. That is an architectural boundary, not a feature.
2. **Column reorder is the single most dangerous write in the app.** `board_columns.position`
   carries a DB-level `unique(project_id, position)` index — unlike `issues.position`, which has
   none — so a naive one-row-at-a-time reassignment violates it mid-loop.
   `BoardColumn::reorderColumns` dodges it with a shift-by-1000 pass and deliberately writes
   through the query builder, because loaded models hold stale in-memory positions and Eloquent's
   dirty-checking would silently skip writes (`app/Models/BoardColumn.php:54-104`). Column delete
   additionally requires a destination and relocates issues
   (`openspec/specs/board/spec.md:97-106`). This belongs in a change whose whole review is about it.
3. **A phone does not reconfigure a board.** Board structure is desktop admin work. The mobile
   screens this API exists for — board, backlog, issue detail — are all read plus issue mutation.

Reads are complete on their own: after this change the phone renders a full board and a sprint
picker.

### In Scope

- `GET /api/v1/projects/{project}/board-columns` — the project's columns, ordered by `position`.
- `GET /api/v1/projects/{project}/sprints` — the project's sprints, newest first, each with a
  server-computed `state` and an `issues_count`.
- `App\Http\Resources\BoardColumnResource` and `SprintResource`.
- A shared `ResolvesProjectByKey` concern, with `IssueController` refactored onto it (Decision 8).
- `openapi/v1.json` delta: 2 operations, 2 tags, 4 schemas.

### Out of Scope

- **A `GET /board` endpoint returning columns with nested issues** — Decision 1, the central call.
- Column and sprint **mutation** (see the scope decision; not planned).
- Issue mutation (`api-issues-write`, unblocked by this change).
- Labels and comments as first-class resources (`api-labels-comments`).
- Per-column issue counts (Decision 6), a `?state=` sprint filter, and sprint velocity/story-point
  sums. All additive later.
- Anything Flutter.

## Capabilities

### New Capabilities

- `api-board-sprints`: the `/api/v1/projects/{project}/board-columns` and `.../sprints` read
  surface — its two pinned resource shapes, its deterministic orderings, its derived sprint state,
  its unpaginated-collection contract, and its non-disclosure guarantees.

### Modified Capabilities

- **None.** `board` (`openspec/specs/board/spec.md`) and `sprints`
  (`openspec/specs/sprints/spec.md`) describe web behavior and are unchanged — this change mirrors
  them over HTTP without altering a requirement. `api-issues` is unchanged too: Decision 8's
  refactor is behavior-identical and its wire shape, ordering, and 404 set are untouched.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | **No `GET /board` endpoint. Two flat collections instead**, and the board screen is composed from three requests the client already makes: `board-columns` + `sprints` + the existing `GET /issues?sprint={id}`. | `GET /projects/{project}/issues` already returns issues ordered `board_columns.position, issues.position, issues.id` (`app/Http/Controllers/Api/V1/IssueController.php:51-64`) — that **is** "columns in order, issues within each, in order". The only thing missing is column *identity*. Rendering is `groupBy(board_column_id)` over an already-correctly-ordered array: zero client-side sorting. A nested `/board` would re-ship those exact bytes in a second envelope — two ways to get the same data, which Decision 2 refuses. It would also be strictly *worse*: nesting cannot paginate (Decision 3), and would force either a duplicate leaner resource or a second serialization of `IssueResource` (Decision 4). The flat columns endpoint is load-bearing for the inverse reason: an **empty column has no issues in the list**, so without it empty columns are invisible and undroppable. |
| 2 | **Duplication is the reason, not a side effect.** The issues list is not made redundant and gains no `?with=columns` parameter. | Folding columns into `/issues` as a parameter would give one endpoint two envelopes (`{data,links,meta}` of issues vs. a nested structure), breaking the `toBe()` exact-shape assertions that are this API's regression net (`openspec/changes/api-issues/design.md:125-127`) and the published client rule that `data` is always a top-level array (`openspec/changes/api-projects/design.md:100-105`). Columns and sprints also have a **different cache lifetime** from issues — a board's structure changes monthly, its cards change per minute. Separate endpoints let the phone cache the vocabulary and re-poll only the cards. |
| 3 | **Neither new endpoint paginates. Bare `{data}`, complete set.** The board's unbounded dimension is handled where it already works: `GET /issues?sprint={id}`. | Honest tradeoff, stated plainly: **a paginated board is unrenderable, so the board must never be unbounded.** It isn't. The web board *always* filters to exactly one sprint or the backlog — `resolveSelectedSprintId` returns a sprint id or `null`, never "all" (`app/Http/Controllers/BoardController.php:94-103`) — and a sprint's issue set is bounded by planning, not by data growth. The genuinely unbounded surface is the **backlog**, which is a flat scrolling list where the existing paginator is natural. So the 500-issue column does not exist inside a sprint-filtered board; if it does, the sprint is the bug. Columns are bounded by board UI (~3-10; a new project gets 3, `app/Models/Project.php:117-121`). Sprints accrue ~26/year and each is 7 scalar fields. Both stay under the `api-projects` rule that absent `meta` means `data` is complete — so adding pagination later is purely additive. |
| 4 | **`IssueResource` is untouched. No board-card variant.** | It *already is* the board card. `api-issues` split list from detail for precisely this reason: "A board card does not need 4 KB × 50 rows on a phone connection — the whole reason there are two classes" (`openspec/changes/api-issues/design.md:118`). Its 15 fields are exactly what a card renders: key, title, type, priority, points, due date, assignee, labels (`app/Http/Resources/IssueResource.php:29-51`). A third variant would be a leaner copy of a resource already designed lean, and would fork the shape a client caches. |
| 5 | **Sprint `state` is derived server-side**: `future` when `start_date > today`, `completed` when `end_date < today`, `active` otherwise — both bounds inclusive. | `Sprint` has **no status column** — only `start_date` and `end_date`, both `date` casts (`app/Models/Sprint.php:23,34-40`), so state is purely a date computation and the phone would otherwise reinvent it. Bounds are inclusive to match the web exactly (`start_date->lte($today) && end_date->gte($today)`, `app/Http/Controllers/BoardController.php:80-87`), which also makes the single-day sprint that `openspec/specs/sprints/spec.md:36-42` explicitly permits come back `active` rather than falling through a gap. **Anchor: `today()` in the app timezone, which is `UTC` (`config/app.php:68`) — *not* UTC-6.** The UTC-6 rule in `openspec/config.yaml:13` is habit-day math only; borrowing it here would silently disagree with the web board for six hours a day. Computing state on the phone instead was rejected: the device clock and timezone are attacker- and traveller-controlled, and the board's default selection would drift from the web's. |
| 6 | **`SprintResource` carries `issues_count`; `BoardColumnResource` deliberately does not.** | Not an inconsistency — a sprint's count is **absolute**, a column's is **meaningless without a sprint filter**, because a column holds issues from every sprint at once. Giving columns a count would mean giving that endpoint a `?sprint=` parameter, a FormRequest, a foreign-sprint `404`, and a `422` — the whole apparatus, to render a number the web board does not render either (`resources/js/pages/projects/board.tsx:71-75` only flatMaps). The sprint count is one `withCount('issues')` subquery and mirrors `ProjectResource:32` including its warning: it MUST come from the eager aggregate, never `$this->issues()->count()`. Result: **neither new endpoint takes a query parameter**, so `422` is not in either response set. |
| 7 | **Ordering, with the tiebreaker question answered per endpoint.** Columns: `ORDER BY position` and **no tiebreaker**. Sprints: `ORDER BY start_date DESC, id DESC`. | Columns need no tiebreaker and this is provable, not optimistic: `position` carries a DB-level `unique(project_id, position)` index (`app/Models/BoardColumn.php:59-60`, `openspec/specs/board/spec.md:24-31`), so within one project it is a **total** order. This is the exact inverse of `issues.position`, which has no such index and therefore made `issues.id` mandatory in `api-issues`. Reuse `$project->boardColumns()`, which already orders by position (`app/Models/Project.php:60-63`). Sprints are the opposite case: `start_date` is a **date** cast, so two sprints trivially share one, and the web's `orderByDesc('start_date')` (`BoardController.php:41`) is *not* deterministic on its own — `id DESC` is mandatory. `created_at` is unusable as a tiebreaker anywhere: SQLite stores it at second precision, so rows written in one test tick tie and the `toBe()` order flips between runs (`openspec/changes/api-issues/design.md:156`). |
| 8 | **The board's default sprint stays a documented ordering rule, not a new envelope key.** The client selects the **first element with `state: "active"`**; if none, the backlog. Extraction of `resolveProject` into `App\Http\Controllers\Api\V1\Concerns\ResolvesProjectByKey`, with `IssueController` refactored onto it. | Given Decision 7's `start_date DESC` order, "first active" is byte-for-byte `BoardController::resolveActiveSprint`, which takes the first match in that same order (`BoardController.php:80-87`) — so overlapping sprints, which nothing in the schema forbids, resolve identically on both surfaces. A top-level `meta.active_sprint_id` was rejected: it would put a non-pagination `meta` on the wire and break the published client rule that `meta` present means paginated (`openspec/changes/api-projects/design.md:100-105`). On the concern: this is the **third** copy of the same five-line owner-scoped `firstOrFail()` (`IssueController.php:114-117`), and all three want the identical `withTrashed()`-free form — an archived project's board is `404` (`openspec/specs/board/spec.md:121-135`), exactly as its issues are. `ProjectController::show` keeps its own `withTrashed()` variant and is untouched. The refactor is behavior-identical and lands under the existing seven-case 404 suite in `ApiIssuesTest`, which is the regression net. Fallback if a reviewer wants zero churn on shipped files: duplicate the five lines twice, ~10 lines worse. |
| 9 | **404 non-disclosure, three cases, byte-identical** `{"message": "Recurso no encontrado."}`: unknown project key, non-member project key, archived project. No new `403` and no new `422`. | Inherited whole from Decision 8's single resolver: one `firstOrFail()` with one failure mode, so it *cannot* branch on the reason (`openspec/changes/api-issues/design.md:276-278`). Both routes sit in the existing `['auth:sanctum','abilities:mobile']` group (`routes/api.php:12`), so `401` + `WWW-Authenticate: Bearer` and the Spanish `403` come from `bootstrap/app.php` unchanged. With no query parameters (Decision 6) there is no `422` path at all. |
| 10 | **OpenAPI**: `/api/v1/projects/{project}/board-columns` and `/api/v1/projects/{project}/sprints`, tags `Board Columns` and `Sprints`, `operationId`s `listProjectBoardColumns` / `listProjectSprints`, both declaring `security: [{"bearerAuth": []}]`. URL vocabulary mirrors the web (`routes/web.php:132,144`). | `ApiContractTest` asserts route↔document set-equality in **both** directions and fails on either side (`tests/Feature/ApiContractTest.php:52-67`), and separately requires `bearerAuth` on every non-login operation (`:69-86`). `{project}` is documented **without** the binding field — these routes use a plain string parameter resolved in code, same as `api-issues` (`openspec/changes/api-issues/design.md:205-211`). |

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `routes/api.php` | Modified | +2 GETs inside the existing group (line 12); names `api.v1.projects.board-columns.index`, `api.v1.projects.sprints.index` |
| `app/Http/Controllers/Api/V1/BoardColumnController.php` | New | `index` only |
| `app/Http/Controllers/Api/V1/SprintController.php` | New | `index` only, with `withCount('issues')` |
| `app/Http/Controllers/Api/V1/Concerns/ResolvesProjectByKey.php` | New | Owner-scoped, `withTrashed()`-free project resolution (Decision 8) |
| `app/Http/Controllers/Api/V1/IssueController.php` | Modified | `resolveProject()` (`:114-117`) replaced by the concern — behavior-identical |
| `app/Http/Resources/BoardColumnResource.php` | New | `id`, `name`, `position`, `updated_at` |
| `app/Http/Resources/SprintResource.php` | New | `id`, `name`, `goal`, `start_date`, `end_date`, `state`, `issues_count`, `updated_at` |
| `openapi/v1.json` | Modified | +2 tags, +2 operations, +4 schemas (`BoardColumn`, `Sprint`, `BoardColumnCollectionResponse`, `SprintCollectionResponse`) |
| `tests/Feature/ApiBoardColumnsTest.php` | New | shape, ordering, empty board, 404 ×3, 401/403, N+1 |
| `tests/Feature/ApiSprintsTest.php` | New | shape, state matrix incl. both boundaries, ordering tiebreaker, `issues_count`, 404 ×3, 401/403, N+1 |
| `app/Models/BoardColumn.php`, `Sprint.php`, `Project.php`, `routes/web.php`, `bootstrap/app.php` | **Unchanged** | Relations and error renders reused as-is |
| migrations | **None** | No schema change |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| `state` computed against the wrong day anchor (UTC-6 borrowed from the habits rule, or the device clock), silently disagreeing with the web board for hours a day | **High** | Decision 5 pins `today()` in `UTC` (`config/app.php:68`) and names the trap explicitly. RED tests assert both inclusive boundaries: a sprint starting today and a sprint ending today are both `active` |
| The `issues_count` N+1: `$this->issues()->count()` instead of the `withCount` aggregate | Med | Decision 6 restates `ProjectResource:19-21`'s warning verbatim; the query-count test asserts an identical count for 1 sprint vs 5, **paired with a value assertion** on the counts so a dropped aggregate returning `null` fails too |
| Decision 8's concern refactor regresses a shipped endpoint | Low | Behavior-identical extraction; `ApiIssuesTest`'s seven-case 404 suite and shape assertions run unchanged. Fallback is duplication, ~10 lines |
| A client builds a board from `/issues` **without** `?sprint=`, pages through 500 issues, and renders a broken board | Med | Decision 3's contract is documented in the OpenAPI description and pinned in the spec: the board composes with an explicit sprint filter. Not enforceable server-side without changing `api-issues`' default, which this change refuses to do |
| Sprints grow unbounded over years with no pagination | Low | ~26/year, 7 scalar fields each. The `api-projects` rule (absent `meta` = complete set) makes adding a paginator later purely additive, and the client needs no change to keep working |
| Deciding against `/board` strands a client that wanted one payload | Low | Reversible and additive: a `/board` endpoint could be added later without touching either endpoint shipped here. The reverse — retiring a shipped nested endpoint — would be a breaking change |
| A reviewer expects nested issues because the scope brief said "the issues within each" | Med | Decision 1 answers it directly and is the first thing in the table; the existing `/issues` ordering (`IssueController.php:56-58`) is the evidence |

## Rollback Plan

No migration, no schema change, no row written or mutated — both endpoints are `GET`.

Revert the branch merge. That deletes `BoardColumnController`, `SprintController`,
`BoardColumnResource`, `SprintResource`, and the `ResolvesProjectByKey` concern, restores
`routes/api.php` to seven routes, and restores `openapi/v1.json` to seven operations.
`ApiContractTest` re-greens automatically because the revert removes routes and documentation
together.

The one non-additive element is Decision 8's refactor, and it reverts cleanly with the same commit:
the revert restores `IssueController::resolveProject()` inline. Because the extraction is
behavior-identical and covered by `ApiIssuesTest`, a *partial* revert is never needed — but if one
is ever wanted, re-inlining five lines into `IssueController` is the whole operation, and the
existing 404 suite proves it.

`app/Models/*`, every web route, and `bootstrap/app.php` are untouched, so nothing outside
`/api/v1` can regress. No data cleanup. No client-visible state to reconcile beyond a released
Flutter build losing two endpoints — mitigated by not shipping the board screen until this merges.

## Dependencies

- **None new.** No Composer or npm package. Builds on `api-token-auth`, `api-projects`, and
  `api-issues`, all merged.
- `api-issues-write` depended on this change and is **unblocked** by it. It still requires its own
  change for its three operations, hierarchy validation, and move/gap-closing semantics.

## Delivery Forecast

Estimated **~760 changed lines** against the 400-line review budget.

- **Decision needed before apply: No** — `size:exception` is pre-authorized for this session.
- **Chained PRs recommended: No** (one PR, two atomic commits).
- **400-line budget risk: High** (~1.9×).

| # | Scope | ~Lines |
|---|---|---|
| 1 | `ResolvesProjectByKey` + `IssueController` refactor; `BoardColumnResource`; `BoardColumnController::index`; board-columns route; OpenAPI `Board Columns` tag + `listProjectBoardColumns` + `BoardColumn`/`BoardColumnCollectionResponse`; `ApiBoardColumnsTest` (shape, ordering, empty board, 404 ×3, 401/403, N+1) | ~330 |
| 2 | `SprintResource`; `SprintController::index` with `withCount`; sprints route; OpenAPI `Sprints` tag + `listProjectSprints` + `Sprint`/`SprintCollectionResponse`; `ApiSprintsTest` (shape, state matrix, boundaries, ordering tiebreaker, `issues_count`, 404 ×3, 401/403, N+1) | ~430 |

Commits cannot split by layer (code, then docs): `ApiContractTest` asserts set-equality in both
directions, so a route without its operation leaves the suite red. If the owner reverses the
exception, commit 2 stacks cleanly as a child PR — it shares only `routes/api.php`,
`openapi/v1.json`, and the concern with commit 1. Each commit leaves `php artisan test --compact`
green. Run `vendor/bin/pint --dirty` before each. `npm run build` is not required — no front-end
surface is touched.

## E2E Acceptance

Feature-level HTTP in `tests/Feature/ApiBoardColumnsTest.php` and `tests/Feature/ApiSprintsTest.php`,
following the `ApiProjectsTest` / `ApiIssuesTest` convention: a real bearer token driving real
requests, never `actingAs`, so guard → ability → project scoping → resource is exercised end to end.
Reuse `createMemberProject()` and `apiBearerHeaders()` (`tests/Feature/ApiProjectsTest.php:16-25,56-58`).

One composition test is the acceptance that matters and belongs in `ApiBoardColumnsTest`: fetch
`board-columns`, then `GET /issues?sprint={id}`, group the issue array by `board_column_id`, and
assert the result reconstructs the board — every column present including an **empty** one, issues
in position order inside each. That is Decision 1's claim, executed.

Browser E2E is **not** proposed and MUST NOT gate this merge: no browser surface is added, and the
browser suite is red from a pre-existing environment defect (headless renders blank pages) that is
out of scope here.

## Success Criteria

- [ ] `GET /api/v1/projects/{key}/board-columns` returns bare `{data}` with exactly `id`, `name`,
      `position`, `updated_at` per element, ordered by `position` — asserted with `toBe()` so a
      leaked column fails.
- [ ] An empty board column is present in the response even though no issue references it.
- [ ] `GET /api/v1/projects/{key}/sprints` returns exactly `id`, `name`, `goal`, `start_date`,
      `end_date`, `state`, `issues_count`, `updated_at`, ordered `start_date DESC, id DESC`; two
      sprints sharing a `start_date` come back in descending `id` order.
- [ ] `state` is `future` / `active` / `completed` correctly, **including both inclusive
      boundaries**: a sprint starting today and a sprint ending today are both `active`, and a
      single-day sprint (`start_date == end_date == today`) is `active`.
- [ ] The first `state: "active"` element matches `BoardController::resolveActiveSprint`'s pick for
      the same fixture, including when two sprints overlap.
- [ ] `issues_count` is correct and comes from the eager aggregate: query count identical for 1
      sprint and 5, with `Model::preventLazyLoading()` enabled inside the test, values asserted.
- [ ] Query count for `board-columns` is identical with 1 column and with 5.
- [ ] Composition test: `board-columns` + `/issues?sprint={id}` reconstructs the board.
- [ ] All three Decision-9 cases return `404 {"message":"Recurso no encontrado."}` with no
      `App\Models\Project` substring; no token → `401` + `WWW-Authenticate: Bearer`; an `mcp`-only
      token → `403` with the Spanish body.
- [ ] `ApiContractTest` passes: two new operations documented, both declaring `bearerAuth`.
- [ ] `ApiIssuesTest` and `ApiProjectsTest` are green **unchanged** after Decision 8's refactor.
- [ ] `php artisan test --compact` is green.

## Proposal question round

Interactive shaping was unavailable (`auto` mode; the owner explicitly asked not to be stopped).
These assumptions are recorded for correction before `sdd-spec`:

1. **The mobile board always renders one sprint (or the backlog), never "all issues".** Decision 3's
   entire pagination answer rests on this, and it matches the web
   (`BoardController.php:94-103`). If the phone's board is meant to show every issue across sprints,
   the unbounded-column problem returns and a nested `/board` with per-column pagination has to be
   reconsidered.
2. **Composing the board from two requests is acceptable on a phone connection.** Assumed yes:
   columns are cacheable across sessions, so the steady-state cost is one request. If a single
   round trip is a hard product requirement, Decision 1 flips and the duplication becomes a
   deliberate cost.
3. **The client, not the server, decides the default sprint** — first `state: "active"`, per
   Decision 8. If the server should own that ruling outright, the alternative is a top-level
   `meta.active_sprint_id`, which costs the `meta`-means-paginated client rule.
4. **Per-column issue counts are not needed in v1.** The web board does not render them
   (`board.tsx:71-75`). If the mobile column header shows "To Do · 12", the columns endpoint needs
   a `?sprint=` parameter and its whole `422`/`404` apparatus (Decision 6).
5. **Completed sprints are worth returning.** No `?state=` filter ships; the client filters
   locally. If a project accumulates years of sprints and the picker only ever shows active and
   future ones, a filter becomes worthwhile — additive either way.
6. **Column and sprint writes are genuinely unwanted on mobile.** If board configuration from the
   phone is on the roadmap, the owner-only authorization boundary (scope decision, reason 1) should
   be designed once, deliberately, rather than bolted onto a read change.
