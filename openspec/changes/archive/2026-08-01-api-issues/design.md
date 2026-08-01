# Design: Issues Read API (`api-issues`)

## Technical Approach

Two GETs added to the existing `['auth:sanctum','abilities:mobile']` group in `routes/api.php:11-16`.
No new middleware, no new policy, no migration, no dependency, no change to `app/Models/Issue.php`.

The change inherits `api-projects`' single architectural choice — **scoping is a query constraint, not
a `Gate` call**, so an inaccessible resource is `404`, never `403`
(`openspec/changes/api-projects/design.md:5-13`) — and adds one of its own: **identity resolution
happens in the controller for both segments of the URL**. `{project}` is a `key`, not an id, and
`{issue}` is a computed accessor that no `where()` can reach (`app/Models/Issue.php:87-92`). Neither
segment can use route-model binding, so both resolve through code that returns `null`/no-row on every
failure mode and converges on one generic body.

The third pillar is that **serialising an issue is a database access unless prevented**: the `key`
accessor reads `$this->project->key` (`app/Models/Issue.php:90`). Every design element below —
eager-load list, `setRelation`, the query-count test — exists to make serialisation touch zero rows.

## Architecture Decisions

### D-1 — `{issue}` resolves via `Issue::resolveByKey()`, and prefix mismatch is already handled

Route parameter is plain `{issue}`, carrying the human key `PROJ-123`.

| Option | Behaviour | Verdict |
|---|---|---|
| `{issue:key}` implicit binding | `key` is `$appends`-only, computed from a relation (`app/Models/Issue.php:66,87-92`). `where('key', ?)` is not a column that exists → SQL error. Also breaks `ApiContractTest` path matching (D-7) | Impossible |
| `Route::bind('issue', ...)` | A binder is keyed by parameter name and would hijack `routes/web.php`'s issue routes, which the proposal promises are unchanged (`proposal.md:93`) | Rejected |
| **`Issue::resolveByKey($project, $issueKey)` in the controller, then `abort_if(... === null, 404)`** | Exactly the web path (`app/Http/Controllers/IssueController.php:99-113`) | **Chosen** |

`resolveByKey` already collapses **four** of the seven `404` cases with no extra controller code
(`app/Models/Issue.php:255-274`): no dash (`:259-261`), empty or non-digit suffix (`:266`),
**prefix mismatch** — `OTHER-1` requested under `/projects/PROJ/` fails `$prefix !== $project->key`
(`:266`) — and unknown number in this project (`:270-273`). All four return `null`, not an exception.
The remaining three (unknown key, non-member, archived) are D-2's `firstOrFail()`. Nesting is what
makes the prefix check possible at all: a flat route has no `$project` to compare against.

Key permanence is a prerequisite for deep links and client caches, and it holds: `number` is allocated
monotonically (`app/Models/Project.php:87-100`), no delete path exists anywhere in the app, and
`Issue::closeGapInScope` renumbers `position`, never `number` (`app/Models/Issue.php:229-243`).

### D-2 — Project resolution: owner-scoped, and deliberately **without** `withTrashed()`

```php
private function resolveProject(Request $request, string $projectKey): Project
{
    return $request->user()->projects()->where('projects.key', $projectKey)->firstOrFail();
}
```

Identical to `ProjectController::show` (`app/Http/Controllers/Api/V1/ProjectController.php:47-52`)
**minus `withTrashed()`**. `User::projects()` (`app/Models/User.php:52-55`) is a `belongsToMany`
through `project_members`; `SoftDeletingScope` therefore excludes archived projects by default. That
single omission delivers the archived case of Decision 8 with zero branches — and matches the web
requirement that an archived project's issue deep link is `404`
(`openspec/specs/issues/spec.md:69-95`). The divergence from `api-projects`' `show` is intentional and
is proposal question 4.

`where('projects.key', ...)` stays table-qualified: `project_members` carries its own `id` and
timestamps, so unqualified columns on this relation are ambiguous-column SQL errors
(`openspec/changes/api-projects/design.md:88-90`).

### D-3 — Board ordering, and the join-hydration trap

```php
$project->issues()
    ->join('board_columns', 'board_columns.id', '=', 'issues.board_column_id')
    ->select('issues.*')                       // ← MANDATORY, see below
    ->orderBy('board_columns.position')
    ->orderBy('issues.position')
    ->orderBy('issues.id')
```

`board_columns` and `issues` share three column names — `id`, `project_id`, `position`. Without
`->select('issues.*')` the join emits both sides and Eloquent hydrates the **last** `id` and
`position` it sees, so an issue silently reports its column's id and its column's position. Every
`orderBy` is table-qualified for the same reason.

The `issues.id` tiebreaker is mandatory, not decorative: `position` is unique only inside a
`(board_column_id, sprint_id)` scope (`app/Models/Issue.php:279-288`), so two issues in one column
belonging to different sprints legitimately share `position: 0`.

**The assertion that catches it** (not an incidental one — it is designed to bite): build the fixture
so that the issue's own `id` and `position` differ from its column's. `createWithDefaultColumns`
creates three columns at positions 0/1/2 (`app/Models/Project.php:102-113`); put the issue in the
**third** column at `position: 0`. A missing `->select('issues.*')` then reports
`position: 2` and the column's `id`, and the `toBe()` exact-shape comparison on `data[0]` fails on two
fields at once. This is why the fixture must not use the first column.

### D-4 — Resource shapes: two classes, no conditionals

`app/Http/Resources/IssueResource.php` (list, 15 fields) and `IssueDetailResource.php` (detail, the
same 15 **plus** 5), both `@mixin Issue`, both mirroring `ProjectResource`'s pinned-allowlist style
(`app/Http/Resources/ProjectResource.php:14-36`).

| Field | List | Detail | Wire form |
|---|---|---|---|
| `id`, `number`, `title`, `story_points`, `board_column_id`, `sprint_id`, `parent_id`, `position` | ✅ | ✅ | raw column |
| `key` | ✅ | ✅ | accessor — see D-5 |
| `type`, `priority` | ✅ | ✅ | `->value` (never the raw enum), so the wire format cannot drift if `IssueType`/`IssuePriority` gain an interface |
| `due_date` | ✅ | ✅ | `?->toDateString()` — date, not timestamp |
| `updated_at` | ✅ | ✅ | `?->toIso8601String()`, pinned like `ProjectResource:33` |
| `assignee` | ✅ | ✅ | `{id,name}` or `null` |
| `labels` | ✅ | ✅ | `[{id,name}]` |
| `description` | — | ✅ | nullable string |
| `reporter` | — | ✅ | `{id,name}` — non-null (`reporter_id` is not nullable, `app/Models/Issue.php:32`) |
| `parent` | — | ✅ | `{id,key,title}` or `null` |
| `children` | — | ✅ | `[{id,key,title,type,board_column_id}]` |
| `comments` | — | ✅ | `[{id,body,created_at,author:{id,name}}]` |

**Deliberately absent from both, with the reason:**

| Omitted | Why |
|---|---|
| `project_id` | The URL carries the project; a numeric FK the client cannot address |
| `created_at` | No client screen uses it. Additive later, so omitting is the reversible choice |
| `description` on the list | A board card does not need 4 KB × 50 rows on a phone connection — the whole reason there are two classes |
| `reporter` on the list | Constant in a single-owner app |
| `board_column` / `sprint` objects | `api-board-sprints` owns those shapes; ids only here |
| `label.project_id` | `Label` has exactly `id`, `project_id`, `name` (`app/Models/Label.php:14-20`) — there is no colour, and the FK is redundant under a project-scoped URL |
| `user.email` | `UserRef` is `{id,name}` only. The web loads precisely `:id,name` (`app/Http/Controllers/IssueController.php:102-104`); widening it here would leak an identity attribute into a board card |
| `comment.updated_at`, `comment.issue_id` | Not rendered; both implied by context |

Conditional fields inside one class were rejected: they defeat the `toBe()` exact-shape assertions
that `tests/Feature/ApiProjectsTest.php:68-71` established as this API's regression net.

The detail shape is field-for-field `IssueController::presentIssue`
(`app/Http/Controllers/IssueController.php:130-176`), so the phone and the web modal show one issue,
not two dialects of it.

### D-5 — Eager loading: every relation named, and why serialising touches zero rows

**List** — `$project->issues()` plus:

| Loaded | Why |
|---|---|
| `assignee:id,name` | `IssueResource.assignee`. Two columns only, per the web (`IssueController.php:103`) |
| `labels` closure (below) | `IssueResource.labels` |
| `setRelation('project', $project)` on every row | The `key` accessor reads `$this->project->key`. Because the query root **is** `$project->issues()`, every row provably belongs to the already-resolved `$project`, so the assignment is exact and costs **zero** queries — `->with('project')` would cost one. Established at `IssueController.php:115-128` |

Not loaded: `boardColumn`, `sprint`, `parent`, `children`, `comments`, `reporter` — the list shape
exposes none of them, and loading one would be a silent per-page cost.

**Detail** — `->load()` mirroring `IssueController::resolveIssue` (`IssueController.php:101-108`),
then `setRelation('project', $project)` on the issue, on `$issue->parent`, and on **each** child
(`IssueController.php:126-128`) — the parent's and each child's `key` accessor is a lazy `project`
load otherwise, and every issue in a hierarchy shares one project by construction.

| Loaded | Ordering | Why the ordering |
|---|---|---|
| `labels` | `orderBy('labels.name')->orderBy('labels.id')` | The web does not order labels. A `toBe()` array comparison needs a total order; `name` is not unique, so `labels.id` is the tiebreaker |
| `assignee:id,name`, `reporter:id,name` | — | Scalar refs |
| `parent:id,project_id,number,title` | — | `number` is required by the `key` accessor; dropping it from the select breaks `parent.key`, not just an omitted field |
| `children` (all columns) | `orderBy('issues.position')->orderBy('issues.id')` | `parent_id` is required for HasMany matching, so no column select. Children can sit in different columns and share `position` (`Issue.php:279-288`) — the `id` tiebreaker is mandatory |
| `comments.author:id,name` | `orderBy('comments.created_at')->orderBy('comments.id')` | **The `id` tiebreaker is mandatory here too**, and the reason is not theoretical: SQLite stores `created_at` at second precision, so two comments written in one test tick share a timestamp and the `toBe()` order flips between runs |

### D-6 — Pagination, filtering, and validation

**Envelope**: a real `LengthAwarePaginator`, handed to `IssueResource::collection()`, which yields
Laravel's default `{data, links, meta}`. `->withQueryString()` is **mandatory** so `links.next`
preserves `?sprint=` — without it, page 2 of a filtered board silently returns the unfiltered project.

This satisfies the forward-compatibility rule published at
`openspec/changes/api-projects/design.md:100-105` *exactly*: `data` stays a top-level array and only
`links`/`meta` are **added**, so a client built against `api-projects`' bare `{data}` keeps working,
and the documented last-page test `meta.current_page === meta.last_page` (equivalently
`links.next === null`) becomes live rather than hypothetical. That rule was written to anticipate this
change; nothing about it is amended here.

No custom `ResourceCollection` subclass. Laravel's default `meta` includes a presentational
`meta.links` array of `{url,label,page,active}`; stripping it would mean a subclass, a divergence from
framework defaults, and an OpenAPI schema that lies about the wire. It is documented and marked
ignorable instead. `->paginate()` costs one extra `COUNT` — constant, not an N+1.

**Query parameters**, validated by `$request->validate()` so a bad value is `422` via the existing
`ValidationErrorResponse` schema, not a 500 or a silent default:

| Param | Rule | Behaviour |
|---|---|---|
| `per_page` | `nullable, integer, min:1, max:100` | Default 50. The cap is the point: this is the API's first unbounded collection |
| `page` | `nullable, integer, min:1` | Validated rather than left to `Paginator::resolveCurrentPage()`, which silently coerces `?page=abc` to 1 — a contract that says "422 on malformed input" must not have one parameter that lies |
| `sprint` | `nullable, regex:/^(backlog\|[1-9][0-9]*)$/` | `backlog` → `whereNull('issues.sprint_id')`; an integer → membership-checked, then `where('issues.sprint_id', $id)`; absent → no constraint |

A **syntactically valid but foreign** sprint id is `404`, not `422`: `$project->sprints()->whereKey($id)->exists()`
(`app/Models/Project.php:52-55`), then `abort_if(! $exists, 404)` with the same generic body.
`422` would confirm that the id exists somewhere, which is precisely the disclosure D-2 removes.
The ordering matters — validation (shape) runs first, existence (`404`) second.

### D-7 — OpenAPI delta

Tag `Issues`. Both operations declare `security: [{"bearerAuth": []}]`, required by
`tests/Feature/ApiContractTest.php:69-86`.

| Operation | `operationId` | Documented path | Responses |
|---|---|---|---|
| `GET` list | `listProjectIssues` | `/api/v1/projects/{project}/issues` | `200` `IssueCollectionResponse`, `401`, `403`, `404`, `422` |
| `GET` detail | `getProjectIssue` | `/api/v1/projects/{project}/issues/{issue}` | `200` `IssueResponse`, `401`, `403`, `404` |

New schemas: `Issue` (15 fields, all required), `IssueDetail` (20), `IssueRef` (`{id,key,title}`),
`IssueChildRef`, `IssueComment`, `LabelRef`, `UserRef`, `PaginationLinks`, `PaginationMeta`,
`IssueResponse`, `IssueCollectionResponse`. `401`/`403`/`404`/`422` reuse the committed
`UnauthenticatedResponse` / `ForbiddenResponse` / `NotFoundResponse` / `ValidationErrorResponse`.

> **Implementer trap**: the documented path is `{issue}` — **never** `{issue:key}`.
> `documentedApiOperations()` compares `strtoupper($method).' '.ltrim($path,'/')` against
> `$route->uri()` and asserts set-equality in **both** directions
> (`tests/Feature/ApiContractTest.php:42-67`). D-1 uses a plain string parameter, so route and
> document match literally — but the trap survives if anyone "improves" the route to `{issue:key}`,
> which cannot work anyway. The same applies to `{project}`, per
> `openspec/changes/api-projects/design.md:122-126`.

`{project}` is documented as the project **`key`** (`DEMO`); `{issue}` as the full issue key
(`DEMO-123`), never a numeric id.

## Data Flow

```
GET /api/v1/projects/{project}/issues?sprint=7&per_page=50
  auth:sanctum ──401 {"message":"No autenticado."} + WWW-Authenticate: Bearer
       │
  abilities:mobile ──403 {"message":"Este token no tiene permiso para usar esta API."}
       ▼
  $request->validate(per_page|page|sprint) ──422 ValidationErrorResponse
       ▼
  user()->projects()->where('projects.key',$project)->firstOrFail()   ← archived excluded
       ▼                                                                (no withTrashed)
  $project->sprints()->whereKey(7)->exists()  ──false──► abort(404)
       ▼
  $project->issues()
      ->join(board_columns).select('issues.*')            ← D-3 hydration guard
      ->where('issues.sprint_id', 7)
      ->with(assignee:id,name  +  labels ordered)
      ->orderBy(board_columns.position, issues.position, issues.id)
      ->paginate($perPage)->withQueryString()
       ▼
  each row: setRelation('project', $project)              ← 0 queries; key accessor safe
       ▼
  IssueResource::collection($paginator) → 200 {data, links, meta}
```

Detail, showing the seven `404` causes converging on one branch:

```
phone         router          Api\V1\IssueController        Issue/DB           Handler
  │ GET /api/v1/projects/PROJ/issues/PROJ-12
  │────────────►│ auth + abilities pass
  │             │────────────────►│
  │             │                 │ user()->projects()
  │             │                 │   ->where('projects.key','PROJ')
  │             │                 │   ->firstOrFail()
  │             │                 │──────────────────────►│
  │             │                 │◄── no row ────────────│  (1) unknown key
  │             │                 │  ModelNotFoundException  (2) non-member
  │             │                 │──────────────────────────────────────►│  (3) archived
  │             │                 │
  │             │                 │◄── Project ───────────│
  │             │                 │ Issue::resolveByKey($project,'PROJ-12')
  │             │                 │   strrpos '-' ──────── null ─────┐  (4) no dash
  │             │                 │   prefix !== key ───── null ─────┤  (5) prefix mismatch
  │             │                 │   ! ctype_digit ────── null ─────┤  (6) bad suffix
  │             │                 │──────────────────────►│          │
  │             │                 │◄── no row ──── null ─────────────┤  (7) unknown number
  │             │                 │  abort_if(null, 404) ────────────┴───►│
  │             │                 │       NotFoundHttpException           │
  │             │                 │       api/* render (bootstrap/app.php:80-82)
  │◄── 404 {"message":"Recurso no encontrado."} ────────────────────────────│
  │             │                 │
  │             │                 │◄── Issue ─────────────│
  │             │                 │ ->load(labels, assignee, reporter,
  │             │                 │        parent, children↑pos, comments↑created_at)
  │             │                 │ setRelation('project') on issue + parent + each child
  │◄── 200 {"data":{...20 fields}} │
```

All seven produce the byte-identical body. Cases 1-3 cannot branch — `firstOrFail()` has one failure
mode. Cases 4-7 cannot branch — `resolveByKey` returns `null` for all of them
(`app/Models/Issue.php:255-274`). Non-disclosure holds **by construction**, not by discipline.

## File Changes

| File | Action | Description |
|---|---|---|
| `routes/api.php` | Modify | +2 GETs inside the existing group (lines 11-16); names `api.v1.projects.issues.index` / `.show` |
| `app/Http/Controllers/Api/V1/IssueController.php` | Create | `index`, `show`, private `resolveProject()`; sits beside `ProjectController.php` |
| `app/Http/Resources/IssueResource.php` | Create | 15-field allowlist, `@mixin Issue` |
| `app/Http/Resources/IssueDetailResource.php` | Create | 20-field allowlist, `@mixin Issue` |
| `openapi/v1.json` | Modify | +1 tag, +2 operations, +11 schemas |
| `tests/Feature/ApiIssuesTest.php` | Create | shape, ordering, pagination, sprint filter, 404 ×7, 401/403, 422, N+1 ×2, detail |
| `app/Models/Issue.php`, `Project.php`, `IssuePolicy`, `routes/web.php` | **Unchanged** | `resolveByKey` and all relations reused as-is |
| `bootstrap/app.php` | **Unchanged** | 401/403/404/422 renders committed in `api-token-auth` / `api-projects` |
| migrations | **None** | No schema change |

## Interfaces / Contracts

```php
// app/Http/Controllers/Api/V1/IssueController.php
public function index(Request $request, string $project): AnonymousResourceCollection;
public function show(Request $request, string $project, string $issue): IssueDetailResource;
//                                     ↑ project key           ↑ issue key "PROJ-123"
private function resolveProject(Request $request, string $projectKey): Project;
```

```php
// IssueResource::toArray() — the list shape, pinned
[
    'id' => $this->id,
    'key' => $this->key,                              // safe: project setRelation'd in controller
    'number' => $this->number,
    'title' => $this->title,
    'type' => $this->type->value,                     // NEVER the raw enum
    'priority' => $this->priority->value,
    'story_points' => $this->story_points,
    'due_date' => $this->due_date?->toDateString(),
    'board_column_id' => $this->board_column_id,
    'sprint_id' => $this->sprint_id,
    'parent_id' => $this->parent_id,
    'position' => $this->position,                    // fails loudly if select('issues.*') is missing
    'assignee' => $this->assignee === null ? null : ['id' => ..., 'name' => ...],
    'labels' => $this->labels->map(fn (Label $l) => ['id' => $l->id, 'name' => $l->name])->all(),
    'updated_at' => $this->updated_at?->toIso8601String(),
];
```

`IssueDetailResource` returns the same 15 plus `description`, `reporter`, `parent`, `children`,
`comments`, shaped exactly as `IssueController::presentIssue` (`IssueController.php:130-176`).

Wire envelope, restated in the OpenAPI description so the client never guesses:

```
{ "data": [ {…Issue} ],
  "links": { "first": …, "last": …, "prev": null, "next": null },
  "meta":  { "current_page": 1, "last_page": 3, "per_page": 50, "total": 118,
             "from": 1, "to": 50, "path": …, "links": [ …presentational, ignorable… ] } }
```

## Testing Strategy

Strict TDD: RED before code, inside the same commit. Real bearer tokens over HTTP, never `actingAs`,
reusing `createMemberProject()` and `apiBearerHeaders()`
(`tests/Feature/ApiProjectsTest.php:16-25,56-58`) so guard → ability → project scoping → key
resolution → resource is exercised end to end. A local `issuePayload()` helper mirrors
`projectPayload()` (`ApiProjectsTest.php:30-41`), and `assertIssueNotFound()` mirrors
`assertProjectNotFound()` (`:49-54`), additionally asserting the body contains neither
`App\Models\Issue` nor `App\Models\Project`.

| Layer | What to test | Approach |
|---|---|---|
| Feature | List returns exactly the 15 D-4 fields | `expect($response->json('data'))->toBe([issuePayload($a), issuePayload($b)])` — a leaked column fails |
| Feature (trap) | Join hydration: issue in the **third** column at `position: 0` reports its own `id` and `position: 0` | Same `toBe()`; without `select('issues.*')` it reports the column's id and `position: 2` (D-3) |
| Feature | Board order across three columns; identical across two consecutive calls | `toBe()` on the key sequence, request repeated |
| Feature | Tiebreaker: two issues sharing `(column, position)` across different sprints come back in `id` order | Requires `issues.id` in the `ORDER BY` |
| Feature | `links`/`meta` present; `meta.last_page` correct; page 2 disjoint from page 1; `links.next` retains `?sprint=` | `per_page=1` over 2 issues; asserts `withQueryString()` |
| Feature | `?sprint={id}` filters; `?sprint=backlog` returns only `sprint_id IS NULL`; foreign sprint id → 404; `?sprint=abc` → 422; `?per_page=101` → 422; `?page=abc` → 422 | Six requests |
| Feature | Detail returns the 20-field shape with `labels`, `assignee`, `reporter`, `parent`, `children`, `comments` embedded and correctly ordered | `toBe()` on the whole `data` object |
| Feature | Comment order is stable when two comments share `created_at` to the second | Two comments in one tick; asserts the `comments.id` tiebreaker (D-5) |
| Feature | All seven D-2/D-1 cases → byte-identical `404 {"message":"Recurso no encontrado."}` | Seven tests, one shared helper. Case 5 (`OTHER-1` under `/projects/PROJ/`) is the one nesting exists for |
| Feature | No token → `401` + `WWW-Authenticate: Bearer`; `['mcp']` token → `403` + Spanish body | Mirrors `ApiAuthTest` |
| Feature (perf) | **Query count identical for 1 issue vs 5**, with `Model::preventLazyLoading()` enabled **inside this test only** | `DB::listen` counter reset before each request. `preventLazyLoading` is **not** on app-wide — `AppServiceProvider::configureDefaults()` sets only `Date::use`, `DB::prohibitDestructiveCommands`, `Password::defaults`. Unlike `api-projects`' `issues()->count()` blind spot, it **does** catch this change's failure mode, because a missing `setRelation` is a genuine lazy relation access |
| Feature (perf) | Value assertions paired with the count: `labels` names and `assignee.name` are correct | Catches the inverse defect — a dropped eager load that returns `null`/`[]` instead of querying |
| Contract | Both operations documented, both declaring `bearerAuth` | Existing `tests/Feature/ApiContractTest.php`, no edit |

Browser E2E is not applicable: no browser surface is added. The pre-existing headless-render defect in
`tests/Browser/` is out of scope and MUST NOT gate this merge.

## Threat Matrix

The change introduces a **routing** boundary (two new URL segments, both resolved from user input) and
no other boundary from the reference matrix.

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | **N/A** — no file-type classification, no execution of repository files | — | — |
| Git repository selection | **N/A** — no VCS invocation at runtime | — | — |
| Commit state | **N/A** — no index/worktree manipulation | — | — |
| Push state | **N/A** — no push automation | — | — |
| PR commands | **N/A** — no PR automation | — | — |
| **Routing / identifier resolution** (applicable) | Both URL segments are attacker-controlled strings resolved in code | D-2 owner-scoped `firstOrFail()`; D-1 `resolveByKey` returning `null` on every malformed or cross-project form; D-6 validate-then-check ordering so shape errors are `422` and existence errors are `404` | The seven-case `404` suite, the cross-project `OTHER-1` case, the foreign-sprint `404`, and the `?sprint=abc` `422` — all listed above |

The adversarial case that matters here is **existence disclosure**, and D-1/D-2 remove the code paths
that could produce it: neither converging branch has a variant that can report *why* it failed.

## Commit Boundaries

Split **by endpoint**, never by layer. `ApiContractTest` asserts route↔document set-equality in both
directions (`ApiContractTest.php:52-67`), so a commit that registers a route without its operation —
or documents an operation without its route — leaves the suite red.

| # | Scope | ~Lines | Green at end |
|---|---|---|---|
| 1 | `IssueResource`; `IssueController::index` + `resolveProject()`; list route; `Issues` tag + `listProjectIssues` + `Issue`/`LabelRef`/`UserRef`/`IssueCollectionResponse`/`PaginationLinks`/`PaginationMeta`; list, ordering, hydration-trap, pagination, sprint-filter, 422, 401/403, N+1 ×2 tests | ~370 | `php artisan test --compact` |
| 2 | `IssueDetailResource`; `IssueController::show`; show route; `getProjectIssue` + `IssueDetail`/`IssueRef`/`IssueChildRef`/`IssueComment`/`IssueResponse`; detail shape, comment-order, 404 ×7 tests | ~300 | `php artisan test --compact` |

~670 authored lines → **400-line budget risk: High** (~1.7×). `size:exception` is pre-authorised for
this session, so this ships as one PR with two atomic commits. If the owner reverses the exception,
commit 2 stacks cleanly as a child PR — it shares only `routes/api.php`, `openapi/v1.json`, and the
controller with commit 1. Run `vendor/bin/pint --dirty` before each commit. `npm run build` is not
required — no front-end surface is touched.

## Migration / Rollout

No migration, no schema change, no row written or mutated — both endpoints are `GET`.

Revert the branch merge: that deletes `IssueController`, `IssueResource`, and `IssueDetailResource`,
restores `routes/api.php` to five routes, and restores `openapi/v1.json` to five operations.
`ApiContractTest` re-greens automatically because the revert removes routes and documentation
together. `bootstrap/app.php`, `app/Models/Issue.php`, and every web route are untouched, so nothing
outside `/api/v1` can regress. No data cleanup, no client-visible state to reconcile beyond a released
Flutter build losing two endpoints — mitigated by not shipping the mobile screens until this merges.

## Open Questions

- [ ] **None blocking.** The proposal's five recorded assumptions (`proposal.md:176-198`) are carried
      unchanged into this design; each is reversible.
- [ ] Question 4 is the one worth an owner glance before `sdd-apply`: D-2 deliberately returns `404`
      for an archived project's issues while `api-projects`' `show` returns that same project with
      `archived: true`. A client can therefore read a project and then 404 on its issues. This follows
      `openspec/specs/issues/spec.md:69-95`; overriding it means adding `withTrashed()` to
      `resolveProject()` and an `archived` hint — a two-line change, but it contradicts the web spec.
- [ ] Writes (`api-issues-write`) stay blocked on `api-board-sprints`, as scoped by the proposal: the
      phone cannot discover a `board_column_id` to post.
