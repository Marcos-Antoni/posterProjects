# Design: Board Columns And Sprints Read API (`api-board-sprints`)

## Technical Approach

Two GETs added to the existing `['auth:sanctum','abilities:mobile']` group (`routes/api.php:12-19`).
No migration, no dependency, no middleware, no policy, no query parameter, and — the proposal's
`Affected Areas` table pins this (`proposal.md:108`) — **no change to any model**.

Three pillars carry over from the siblings and one is new:

1. **Scoping is a query constraint, never a `Gate`** — inherited whole
   (`openspec/changes/api-projects/design.md:5-13`). An inaccessible resource is `404`, never `403`.
2. **Both new controllers resolve `{project}` through one shared trait**, not a third copy of the
   same five lines (D-1). `{project}` is a `key`, not an id, so route-model binding is unavailable
   — same as `api-issues` (`openspec/changes/api-issues/design.md:11-14`).
3. **Serialising must touch zero rows.** Unlike `api-issues`, neither resource reads a relation
   accessor, so the N+1 surface is exactly one thing: `issues_count` (D-5).
4. **New here: one field on the wire has no column behind it.** `Sprint` has no status column, so
   `state` is computed at serialisation from `today()` (D-4). That is the only derived field in
   this change and it owns the whole flakiness budget.

## Architecture Decisions

### D-1 — `ResolvesProjectByKey`: extraction shape, call sites, and the regression proof

`IssueController::resolveProject()` (`app/Http/Controllers/Api/V1/IssueController.php:114-117`)
moves verbatim into a trait. **The body is copied byte-for-byte; only its location changes.**

```php
// app/Http/Controllers/Api/V1/Concerns/ResolvesProjectByKey.php
trait ResolvesProjectByKey
{
    protected function resolveProject(Request $request, string $projectKey): Project
    {
        return $request->user()->projects()->where('projects.key', $projectKey)->firstOrFail();
    }
}
```

| Option | Verdict |
|---|---|
| **Trait in `Api\V1\Concerns`** | **Chosen.** Laravel-native `Concerns` convention, zero DI wiring, zero call-site edits |
| Shared `Api\V1\ApiController` base class | Rejected — the three controllers already extend `App\Http\Controllers\Controller`; a second base class buys nothing a trait does not and makes every future `Api\V1\*` controller inherit a resolver it may not want |
| `ProjectResolver` injected service | Rejected — would move `firstOrFail()` behind a constructor argument and change the four call sites; the whole point of D-1 is that they do not change |
| Duplicate the five lines twice (proposal's stated fallback) | Rejected — ~10 lines worse and the third copy is where drift starts. Still the escape hatch if a reviewer vetoes churn on a shipped file |

**Visibility changes `private` → `protected`.** A `private` trait method is importable, but `private`
would forbid a subclass overriding the resolver and reads as "this trait owns it exclusively", which
is false. `protected` is the trait convention.

**Every call site, exhaustively.** This is the whole blast radius:

| Site | Change |
|---|---|
| `IssueController.php:41` (`index`) | **None.** Still `$this->resolveProject($request, $project)` |
| `IssueController.php:88` (`show`) | **None.** Same call |
| `IssueController.php:108-117` | **Deleted** (method body + docblock), replaced by `use ResolvesProjectByKey;` at class top and one `use App\Http\Controllers\Api\V1\Concerns\ResolvesProjectByKey;` import. The `use App\Models\Project;` import becomes unused *only if* nothing else in the file references `Project` — it does not, so that import is removed too |
| `BoardColumnController` (new) | `use ResolvesProjectByKey;` — first new consumer |
| `SprintController` (new) | `use ResolvesProjectByKey;` — second |
| `ProjectController::show` (`ProjectController.php:45-55`) | **Untouched, deliberately.** It carries `withTrashed()` *and* `withCount('issues')` (`:49-50`) — a different resolver with different semantics (an archived project resolves there and 404s here). Folding both into one trait would need a flag argument, which is exactly the branch D-6's non-disclosure argument forbids |

**How identical behavior is proven — the specific tests, by name.** `ApiIssuesTest` is the net and
it MUST NOT be edited by this change. Three of its tests exercise the resolver's failure modes:

| Test | Line | Resolver case proven |
|---|---|---|
| `a non members project issues are not found, never forbidden` | `tests/Feature/ApiIssuesTest.php:259` | non-member → `404` (list route) |
| `an unknown project key on the detail endpoint is not found` | `:374` | unknown key → `404` |
| `a non members project key on the detail endpoint is not found` | `:383` | non-member → `404` (detail route) |
| `an archived projects issues are not found despite the project itself resolving with archived true` | `:395` | the missing `withTrashed()` — the single most fragile property of the extraction |

The other four `404` tests (`:412`, `:422`, `:434`, `:446`) go through `Issue::resolveByKey`, not the
resolver — but each first resolves its project **successfully**, so they double as the happy-path
proof. The shape, ordering, tiebreaker, pagination, sprint-filter and N+1 tests (`:103`-`:318`) all
do too.

**The mechanical guard, and it is a hard gate:**

1. `php artisan test --compact --filter=ApiIssuesTest` green **before** touching anything (baseline).
2. Extract.
3. Same command green **with `tests/Feature/ApiIssuesTest.php` unchanged — `git diff` on that file
   MUST be empty.** If one character of that test file needs editing, the extraction is not
   behavior-identical: stop and revert to the duplication fallback.
4. `vendor/bin/pint --dirty` before the commit.

The extraction is copy-move, not rewrite. `$request->user()`, the table-qualified
`where('projects.key', ...)` (mandatory: `project_members` carries its own `id`, so unqualified
columns on this `belongsToMany` are ambiguous-column SQL errors,
`openspec/changes/api-projects/design.md:88-90`), and `firstOrFail()` are preserved character-for-
character so Larastan sees the same expression it already accepts.

### D-2 — `BoardColumnResource`: four fields

```php
['id' => $this->id, 'name' => $this->name, 'position' => $this->position,
 'updated_at' => $this->updated_at?->toIso8601String()]
```

`@mixin BoardColumn`, allowlist style pinned like `ProjectResource:14-36`.

| NOT exposed | Why |
|---|---|
| `project_id` | The URL carries the project. A numeric FK the client cannot address (same rule as `IssueResource`, `openspec/changes/api-issues/design.md:116`) |
| `created_at` | No client screen uses it. Omitting is the reversible choice — adding it later is additive; removing it later is breaking |
| `issues_count` | Decision 6: a column's count is meaningless without a sprint filter, because one column holds issues from every sprint at once. Adding it would drag in a `?sprint=` parameter, a FormRequest, a foreign-sprint `404` and a `422` |
| nested `issues` | Decision 1. The client composes with `GET /issues?sprint={id}` |

### D-3 — `SprintResource`: eight fields, and the date-vs-timestamp trap

```php
['id', 'name', 'goal',
 'start_date' => $this->start_date->toDateString(),   // "2026-08-01", NOT ISO8601
 'end_date'   => $this->end_date->toDateString(),
 'state'      => …D-4…,
 'issues_count' => $this->issues_count,               // eager aggregate — NEVER ->issues()->count()
 'updated_at' => $this->updated_at?->toIso8601String()]
```

`start_date`/`end_date` are `date` casts (`app/Models/Sprint.php:36-39`) over `date` columns
(`database/migrations/2026_07_21_054341_create_sprints_table.php:19-20`), so they ship as
`toDateString()` — mirroring `IssueResource:37`'s `due_date`, not `:50`'s `updated_at`. Emitting
`2026-08-01T00:00:00+00:00` would tell a phone in UTC-6 that the sprint starts on July 31.
Both are non-nullable (`NOT NULL` in the migration), so no `?->`; `updated_at` keeps its `?->`
exactly as `ProjectResource:33`.

| NOT exposed | Why |
|---|---|
| `project_id` | Same as D-2 |
| `created_at` | Same as D-2 |
| nested `issues` | Would be an unbounded array inside an unpaginated collection — the exact shape Decision 3 refuses |
| `is_active` boolean | Subsumed by `state`; two fields encoding one fact is a drift surface |
| velocity / story-point sum | Out of scope (`proposal.md:60-61`). Would be a second aggregate and a product decision about what counts as "done" |

### D-4 — `state` lives in the Resource, not the model, not the query

```php
$today = today();          // Carbon at 00:00:00 in config/app.php:68 → UTC

'state' => match (true) {
    $this->start_date->gt($today) => 'future',
    $this->end_date->lt($today)   => 'completed',
    default                       => 'active',
},
```

| Option | Tradeoff | Verdict |
|---|---|---|
| **`SprintResource::toArray()`** | Presentation logic in the presentation layer; unit-visible through the HTTP shape assertion; **`app/Models/Sprint.php` stays untouched, which `proposal.md:108` pins as a constraint** | **Chosen** |
| `Attribute` accessor on `Sprint` | Reusable by the web — but the web does not want it (`BoardController:80-87` computes activeness inline over a *collection*, picking the first match, which an accessor cannot express) and it modifies a shipped model this change promised not to touch. `state` is a wire concept, not a domain concept: nothing in the app persists or queries it | Rejected |
| SQL `CASE WHEN` / `selectRaw` | Pushes date comparison into dialect-specific SQL (SQLite `date()` vs MySQL `CURDATE()`), makes the value invisible to `toBe()` reasoning, and buys nothing — there is no filter or sort on `state` | Rejected |

**Ordering of the arms is load-bearing.** `future` is tested first; both bounds are then inclusive
*by construction*, with no explicit boundary branch:

| Fixture | `start_date->gt(today)` | `end_date->lt(today)` | Result |
|---|---|---|---|
| starts today | false | false | `active` ✅ inclusive lower bound |
| ends today | false | false | `active` ✅ inclusive upper bound |
| `start == end == today` | false | false | `active` ✅ single-day sprint (`openspec/specs/sprints/spec.md:36-42`) |
| starts tomorrow | true | — | `future` |
| ended yesterday | false | true | `completed` |

Byte-identical to `BoardController::resolveActiveSprint`'s
`start_date->lte($today) && end_date->gte($today)` (`app/Http/Controllers/BoardController.php:80-87`),
which also anchors on bare `today()`. **`today()` resolves in `config/app.php:68` → `UTC`, not
UTC-6.** The UTC-6 rule in `openspec/config.yaml:13` is habit-day math only; borrowing it here would
disagree with the web board for six hours every day.

**Testing it without a flaky clock.** Two rules, both mandatory:

1. **Fixtures are relative, never hardcoded**: `today()->addDays(7)`, `today()->subDays(7)`,
   `today()` itself. A hardcoded `'2026-08-01'` rots the day after it is written.
2. **Freeze at midday of the real current day**, not at a fixed calendar date:
   `Carbon::setTestNow(today()->addHours(12))` at the top of the state tests. This closes the only
   genuine race — a test whose fixture computes `today()` at `23:59:59.9` and whose request computes
   it after midnight — without pinning the suite to a date. `Illuminate\Foundation\Testing\TestCase::tearDown()`
   clears `setTestNow` for both `Carbon` and `CarbonImmutable`; if that is not obvious to a reader,
   add `afterEach(fn () => Carbon::setTestNow());` — one free line.

Freezing time is confined to the state tests. Shape, ordering, count and `404` tests use the real
clock, because none of them reads a date boundary.

### D-5 — Queries, eager loads, and the N+1 guard

**Board columns** — no eager load at all, by design:

```php
$project->boardColumns()->get();   // Project.php:60-63 already ->orderBy('position')
```

`BoardColumnResource` reads four raw columns and zero relations, so `->with(...)` anything would be
pure cost. Reusing `boardColumns()` also means the ordering is defined in exactly one place.

**Sprints** — one relation aggregate, no joined rows:

```php
$project->sprints()
    ->withCount('issues')                    // → $sprint->issues_count
    ->orderByDesc('sprints.start_date')
    ->orderByDesc('sprints.id')
    ->get();
```

`withCount` emits a correlated sub-select, **not** a join, so `api-issues`' `->select('issues.*')`
hydration trap (`openspec/changes/api-issues/design.md:64-89`) does not exist here. Column
qualification is therefore not strictly required — `sprints()` is a plain `HasMany` with no pivot,
unlike `User::projects()` (`ProjectController.php:19-21`) — but is used anyway so the API has one
rule, not two.

**The guard test, mirroring `ApiIssuesTest:270-318` structurally:**

| Element | Why it is mandatory |
|---|---|
| `Model::preventLazyLoading()` inside `try { … } finally { Model::preventLazyLoading(false); }` | Not on app-wide: `AppServiceProvider::configureDefaults()` sets only `Date::use`, `DB::prohibitDestructiveCommands`, `Password::defaults`. The `finally` prevents leaking strict mode into every later test in the file |
| `DB::listen` counting statements that `str_starts_with(…, 'select')` | Same counter as `ApiIssuesTest:285-289` |
| `$this->app['auth']->forgetGuards()` **and** `$selectCount = 0` before **each** request | Without `forgetGuards()` the resolved token is cached across requests and the second call issues fewer queries — the assertion would pass for the wrong reason (`ApiIssuesTest:291-303`) |
| 1 sprint vs 5 sprints, `expect($manyQueries)->toBe($soloQueries)` | Catches `->issues()->count()` per row |
| **Paired value assertion**: every returned `issues_count` equals its seeded number | Catches the inverse defect — a `withCount` dropped entirely, which returns `null` and would keep the query count flat. This pairing is exactly the blind spot `api-projects` had |

The columns endpoint gets the same guard even though it currently cannot N+1: it is a tripwire for
the first person who adds a count or a nested relation to `BoardColumnResource`.

### D-6 — Ordering, with the tiebreaker answered per endpoint

| Endpoint | `ORDER BY` | Tiebreaker needed? |
|---|---|---|
| board-columns | `position` | **No, and it is provable.** `unique(project_id, position)` at the DB level (`database/migrations/2026_07_21_054729_create_board_columns_table.php:22`, documented at `app/Models/BoardColumn.php:59-60`) makes `position` a *total* order inside one project. The exact inverse of `issues.position`, which has no such index and therefore made `issues.id` mandatory in `api-issues` |
| sprints | `start_date DESC, id DESC` | **Yes, mandatory.** `start_date` is a `date` — two sprints trivially share one. The web's bare `orderByDesc('start_date')` (`BoardController.php:41`) is *not* deterministic on its own |

`created_at` is unusable as a tiebreaker **anywhere in this suite**: SQLite stores it at second
precision, so two rows written inside one test tick tie and a `toBe()` exact-shape comparison flips
between runs (`openspec/changes/api-issues/design.md:156`). Every tiebreaker in this change is
`id`.

Given `start_date DESC`, "the first element with `state: "active"`" is byte-for-byte
`BoardController::resolveActiveSprint`, which takes the first match in that same order — so two
**overlapping** sprints (nothing in the schema forbids it) resolve identically on phone and web.
That is a documented ordering rule, not an envelope key: a `meta.active_sprint_id` would put a
non-pagination `meta` on the wire and break the published rule that `meta` present means paginated
(`openspec/changes/api-projects/design.md:100-105`).

### D-7 — `unique(project_id, position)` and its effect on fixtures

Read-only, so no reorder runs and `BoardColumn::reorderColumns` / `reindexPositions`
(`BoardColumn.php:77-128`) are untouched. **But it absolutely affects test fixtures, and this is a
latent flake:**

`createMemberProject()` → `Project::createWithDefaultColumns()` already occupies positions **0, 1, 2**
(`app/Models/Project.php:117-121`). `BoardColumnFactory::definition()` defaults to
`'position' => fake()->numberBetween(0, 10)` (`database/factories/BoardColumnFactory.php:24`) — a
random value that collides with 0/1/2 roughly **27% of the time**, raising
`UNIQUE constraint failed: board_columns.project_id, board_columns.position` on a run that passed
yesterday.

**Rule, non-negotiable: every fixture that adds a column to a `createMemberProject()` project MUST
pass an explicit `position` (3, 4, …). Never rely on the factory default.** The 5-column N+1 fixture
uses positions 3 and 4. Columns in *different* projects may share a position freely, so the
cross-project non-disclosure fixture is unconstrained. The factory itself is **not** modified —
changing a shared factory default is out of scope and would ripple into unrelated suites.

### D-8 — OpenAPI delta

Neither operation declares a query parameter, so **`422` is absent from both response sets** — the
first `/api/v1` operations with no `422`.

| Operation | `operationId` | Documented path | Tag | Responses |
|---|---|---|---|---|
| `GET` columns | `listProjectBoardColumns` | `/api/v1/projects/{project}/board-columns` | `Board Columns` | `200` `BoardColumnCollectionResponse`, `401`, `403`, `404` |
| `GET` sprints | `listProjectSprints` | `/api/v1/projects/{project}/sprints` | `Sprints` | `200` `SprintCollectionResponse`, `401`, `403`, `404` |

New schemas: `BoardColumn` (4 fields, all required), `Sprint` (8, all required, `state` as
`enum: ["future","active","completed"]`), `BoardColumnCollectionResponse`, `SprintCollectionResponse`
— both bare `{"data": [...]}` with **no** `links`/`meta`, per Decision 3. `401`/`403`/`404` reuse the
committed `UnauthenticatedResponse` / `ForbiddenResponse` / `NotFoundResponse`. Both operations
declare `security: [{"bearerAuth": []}]`.

> **Implementer trap**: the documented path parameter is `{project}` — **never** `{project:key}`.
> `documentedApiOperations()` builds `strtoupper($method).' '.ltrim($path,'/')` and asserts
> set-equality against `$route->uri()` in **both** directions
> (`tests/Feature/ApiContractTest.php:42-67`), and a separate test requires exactly
> `[['bearerAuth' => []]]` on every non-login operation (`:69-86`). A binding field in the document
> or in the route breaks the first; a missing `security` block breaks the second.

The `Sprints` tag description must restate Decision 3's contract — the board composes from
`board-columns` + `sprints` + `GET /issues?sprint={id}`, and the response is the complete set
because absent `meta` means complete (`openspec/changes/api-projects/design.md:100-105`).

### D-9 — `404` non-disclosure: three cases, one branch

Inherited whole from D-1's single resolver. `firstOrFail()` has exactly one failure mode, so the
response *cannot* branch on the reason:

| Case | Mechanism |
|---|---|
| unknown project key | no row → `ModelNotFoundException` |
| project the user is not a member of | `$request->user()->projects()` is a `belongsToMany` through `project_members` (`app/Models/User.php:52-55`) — a stranger's project is not in the relation |
| archived project | the deliberate absence of `withTrashed()`: `SoftDeletingScope` excludes it. Matches `openspec/specs/board/spec.md:121-135` |

All three render `404 {"message": "Recurso no encontrado."}` from `bootstrap/app.php`, unchanged.
No new `403` (scoping is a constraint, not a `Gate`) and no `422` (no parameters). Non-disclosure
holds **by construction**, not by discipline.

## Data Flow

```
GET /api/v1/projects/{project}/board-columns
  auth:sanctum ──401 {"message":"No autenticado."} + WWW-Authenticate: Bearer
       │
  abilities:mobile ──403 {"message":"Este token no tiene permiso para usar esta API."}
       ▼
  ResolvesProjectByKey::resolveProject()  ← no withTrashed → archived excluded
       │  user()->projects()->where('projects.key',$key)->firstOrFail()
       │       └── no row ─────────────► 404 {"message":"Recurso no encontrado."}
       ▼
  $project->boardColumns()->get()          ← Project.php:60-63 orders by position
       │  no eager load: the resource reads zero relations
       ▼
  BoardColumnResource::collection(...) → 200 {"data":[{id,name,position,updated_at}]}
```

Sprints, showing where the two derived values come from — one from SQL, one from the clock:

```
phone          router        Api\V1\SprintController      DB                 SprintResource
  │ GET /api/v1/projects/DEMO/sprints
  │─────────────►│ auth + abilities pass
  │              │──────────────────►│
  │              │                   │ resolveProject()      (trait, D-1)
  │              │                   │─────────────────────►│
  │              │                   │◄── no row ───────────│  unknown / non-member / archived
  │              │                   │  ModelNotFoundException ──► 404 (identical body, D-9)
  │              │                   │
  │              │                   │◄── Project ──────────│
  │              │                   │ sprints()
  │              │                   │   ->withCount('issues')      ← ONE correlated sub-select,
  │              │                   │   ->orderByDesc(start_date)    not a join, not per row
  │              │                   │   ->orderByDesc(id)          ← tiebreaker MANDATORY (D-6)
  │              │                   │─────────────────────►│
  │              │                   │◄── rows + issues_count ──────│
  │              │                   │───────────────────────────────────────►│
  │              │                   │                        today() ← config/app.php:68 = UTC
  │              │                   │                        match(true):
  │              │                   │                          start_date > today → future
  │              │                   │                          end_date   < today → completed
  │              │                   │                          otherwise          → active
  │◄── 200 {"data":[{…8 fields, state, issues_count…}]} ─────│  bare {data}, no links/meta
```

Client-side board composition — Decision 1's claim, which the acceptance test executes:

```
board-columns ──┐
                ├──► groupBy(board_column_id) ──► rendered board, zero client-side sorting
/issues?sprint=─┘        (issues already arrive in board order, IssueController.php:56-58)

empty column: present in the columns array, absent from the issues array ──► renders, droppable
```

## File Changes

| File | Action | Description |
|---|---|---|
| `app/Http/Controllers/Api/V1/Concerns/ResolvesProjectByKey.php` | Create | Trait, one `protected` method, body copied verbatim from `IssueController:114-117` |
| `app/Http/Controllers/Api/V1/IssueController.php` | **Modify** | `use ResolvesProjectByKey;` added, `:108-117` deleted, now-unused `App\Models\Project` import dropped. **Zero call-site edits.** D-1's gate applies |
| `app/Http/Controllers/Api/V1/BoardColumnController.php` | Create | `index` only |
| `app/Http/Controllers/Api/V1/SprintController.php` | Create | `index` only, `withCount('issues')` |
| `app/Http/Resources/BoardColumnResource.php` | Create | 4-field allowlist, `@mixin BoardColumn` |
| `app/Http/Resources/SprintResource.php` | Create | 8-field allowlist, `@mixin Sprint`, `state` computed (D-4) |
| `routes/api.php` | Modify | +2 GETs inside the group at `:12`; names `api.v1.projects.board-columns.index`, `api.v1.projects.sprints.index` |
| `openapi/v1.json` | Modify | +2 tags (after `Issues` at `:23-26`), +2 operations, +4 schemas |
| `tests/Feature/ApiBoardColumnsTest.php` | Create | shape, ordering, empty column, composition, 404 ×3, 401/403, query-count guard |
| `tests/Feature/ApiSprintsTest.php` | Create | shape, state matrix + both boundaries, tiebreaker, `issues_count`, active-pick parity, 404 ×3, 401/403, N+1 |
| `tests/Feature/ApiIssuesTest.php` | **Unchanged — enforced** | `git diff` on this file MUST be empty (D-1) |
| `app/Models/*`, `database/factories/*`, `routes/web.php`, `bootstrap/app.php` | **Unchanged** | Relations, factories and error renders reused as-is |
| migrations | **None** | No schema change |

## Interfaces / Contracts

```php
// app/Http/Controllers/Api/V1/Concerns/ResolvesProjectByKey.php
protected function resolveProject(Request $request, string $projectKey): Project;

// app/Http/Controllers/Api/V1/BoardColumnController.php
public function index(Request $request, string $project): AnonymousResourceCollection;

// app/Http/Controllers/Api/V1/SprintController.php
public function index(Request $request, string $project): AnonymousResourceCollection;
//                                       ↑ the project key ("DEMO"), never a numeric id
```

Wire shapes, both bare `{data}` with no `links` and no `meta`:

```
GET /api/v1/projects/DEMO/board-columns
{ "data": [ { "id": 7, "name": "In Progress", "position": 1,
              "updated_at": "2026-08-01T10:22:31+00:00" } ] }

GET /api/v1/projects/DEMO/sprints
{ "data": [ { "id": 3, "name": "Sprint 4", "goal": "Ship the board",
              "start_date": "2026-07-28", "end_date": "2026-08-10",   ← dates, not timestamps
              "state": "active", "issues_count": 12,
              "updated_at": "2026-08-01T10:22:31+00:00" } ] }
```

`state` ∈ `{"future","active","completed"}`. The client's default sprint is the **first** element
with `state: "active"`; if none, the backlog.

## Testing Strategy

Strict TDD: RED before code, inside the same commit. Real bearer tokens over HTTP, never `actingAs`,
reusing `createMemberProject()` and `apiBearerHeaders()`
(`tests/Feature/ApiProjectsTest.php:16-25`) so guard → ability → project scoping → resource runs end
to end. Local `boardColumnPayload()` / `sprintPayload()` helpers mirror `projectPayload()`
(`ApiProjectsTest.php:30-41`); `assertProjectNotFound()`-style helpers mirror `:49-54`.

| Layer | What to test | Approach |
|---|---|---|
| Feature | Columns return exactly the 4 D-2 fields, ordered by `position` | `expect($r->json('data'))->toBe([payload($todo), payload($inProgress), payload($done)])` — a leaked column fails |
| Feature | An **empty** column is present even though no issue references it | Seed issues only into column 3; assert all three columns return |
| Feature (acceptance) | Composition: `board-columns` + `GET /issues?sprint={id}`, grouped by `board_column_id`, reconstructs the board — every column present including the empty one, issues in position order inside each | Decision 1, executed |
| Feature | Sprints return exactly the 8 D-3 fields, `start_date`/`end_date` as `YYYY-MM-DD` | `toBe()` on `data` |
| Feature | State matrix incl. **both** inclusive boundaries and the single-day sprint | 5 fixtures per the D-4 table, relative to `today()`, with `Carbon::setTestNow(today()->addHours(12))` |
| Feature | Ordering tiebreaker: two sprints sharing one `start_date` come back `id DESC` | Requires `orderByDesc('sprints.id')` (D-6) |
| Feature | The first `state:"active"` element matches `BoardController::resolveActiveSprint`'s pick for the same fixture, **including two overlapping sprints** | Instantiate the web path over the same rows and compare ids |
| Feature | `issues_count` is correct per sprint, and `0` for an empty sprint | Value assertion, paired with the guard below |
| Feature (perf) | Query count identical for 1 vs 5 rows, `Model::preventLazyLoading()` in `try/finally`, `forgetGuards()` + counter reset before each request, **values asserted** | Both endpoints. Structure mirrors `ApiIssuesTest:270-318` (D-5) |
| Feature | All three D-9 cases → byte-identical `404 {"message":"Recurso no encontrado."}`, body containing no `App\Models\Project` | Three tests per endpoint, one shared helper |
| Feature | No token → `401` + `WWW-Authenticate: Bearer`; `['mcp']` token → `403` + Spanish body | Mirrors `ApiAuthTest` |
| Regression | `ApiIssuesTest` and `ApiProjectsTest` green **with zero edits** after D-1 | `php artisan test --compact --filter=ApiIssuesTest`; `git diff` on the test file must be empty |
| Contract | Both operations documented, both declaring `bearerAuth` | Existing `tests/Feature/ApiContractTest.php`, no edit |

Browser E2E is **not** applicable and MUST NOT gate this merge: no browser surface is added, and the
browser suite is red from a pre-existing headless-render defect out of scope here.

## Threat Matrix

The change introduces a **routing** boundary (two new URL segments resolved from user input) and no
other boundary from the reference matrix.

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | **N/A** — no file-type classification, no execution of repository files | — | — |
| Git repository selection | **N/A** — no VCS invocation at runtime | — | — |
| Commit state | **N/A** — no index/worktree manipulation | — | — |
| Push state | **N/A** — no push automation | — | — |
| PR commands | **N/A** — no PR automation | — | — |
| **Routing / identifier resolution** (applicable) | `{project}` is an attacker-controlled string resolved in code | D-1's single owner-scoped `firstOrFail()`, `withTrashed()`-free; D-9's one-branch convergence; no query parameter at all, so no parser to abuse | The three-case `404` suite per endpoint, each asserting the body contains no `App\Models\Project` |

The adversarial case that matters is **existence disclosure**, and D-1/D-9 remove the code path that
could produce it: there is no variant of either branch that can report *why* it failed.

## Commit Boundaries

Split **by endpoint**, never by layer. `ApiContractTest` asserts route↔document set-equality in both
directions (`ApiContractTest.php:52-67`), so a commit registering a route without its operation — or
documenting an operation without its route — leaves the suite red.

| # | Scope | ~Lines | Green at end |
|---|---|---|---|
| 1 | `ResolvesProjectByKey` + `IssueController` refactor (D-1 gate); `BoardColumnResource`; `BoardColumnController::index`; board-columns route; OpenAPI `Board Columns` tag + `listProjectBoardColumns` + `BoardColumn`/`BoardColumnCollectionResponse`; `ApiBoardColumnsTest` (shape, ordering, empty column, composition, 404 ×3, 401/403, query-count guard) | ~330 | `php artisan test --compact` |
| 2 | `SprintResource` (incl. `state`); `SprintController::index` with `withCount`; sprints route; OpenAPI `Sprints` tag + `listProjectSprints` + `Sprint`/`SprintCollectionResponse`; `ApiSprintsTest` (shape, state matrix + boundaries, tiebreaker, `issues_count`, active-pick parity, 404 ×3, 401/403, N+1) | ~430 | `php artisan test --compact` |

~760 authored lines → **400-line budget risk: High (~1.9×)**. `size:exception` is pre-authorised, so
this ships as one PR with two atomic commits. **Decision needed before apply: No. Chained PRs
recommended: No.** If the owner reverses the exception, commit 2 stacks cleanly as a child PR — it
shares only `routes/api.php`, `openapi/v1.json`, and the concern with commit 1. Run
`vendor/bin/pint --dirty` before each commit. `npm run build` is not required — no front-end surface
is touched.

The refactor rides in commit 1 rather than a commit of its own on purpose: a standalone extraction
commit adds a second `ApiIssuesTest` run for zero behavioral delta, and commit 1 is the first
consumer that proves the trait is actually shared.

## Migration / Rollout

No migration, no schema change, no row written or mutated — both endpoints are `GET`.

Revert the branch merge: that deletes both controllers, both resources, and the concern, restores
`routes/api.php` to seven routes and `openapi/v1.json` to seven operations. `ApiContractTest`
re-greens automatically because the revert removes routes and documentation together. The only
non-additive element is D-1, and it reverts in the same commit — the revert re-inlines
`resolveProject()` into `IssueController`. A *partial* revert is never needed, but if one is ever
wanted, re-inlining five lines is the whole operation and `ApiIssuesTest`'s three resolver cases
prove it.

`app/Models/*`, `database/factories/*`, every web route and `bootstrap/app.php` are untouched, so
nothing outside `/api/v1` can regress. No data cleanup, no client-visible state to reconcile beyond
a released Flutter build losing two endpoints — mitigated by not shipping the board screen until
this merges.

## Open Questions

- [ ] **None blocking.** The proposal's six recorded assumptions (`proposal.md:213-236`) carry into
      this design unchanged; each is reversible and additive.
- [ ] Worth an owner glance before `sdd-apply`: D-1 modifies a shipped, tested file. The gate is
      mechanical (`ApiIssuesTest` green with an empty `git diff` on the test file) and the fallback
      is ~10 lines of duplication, but it is the only place in this change where an existing green
      endpoint can regress.
- [ ] `BoardColumnFactory`'s random default `position` (D-7) is a pre-existing flake generator for
      any future test that adds a column without an explicit position. This change works around it
      rather than fixing it, because changing a shared factory default is out of scope. Worth its
      own tiny change later.
