# Design: Projects Read API (`api-projects`)

## Technical Approach

Two GETs added to the existing `['auth:sanctum','abilities:mobile']` group in `routes/api.php:10-13`.
No new middleware, no new policy, no migration, no dependency. The whole change rests on one
architectural choice: **membership scoping is expressed as a query constraint, not as a `Gate` call**,
because the spec requires `404` (non-disclosure) where a `Gate` denial would produce `403`
(`specs/api-projects/spec.md:50-68`). Everything else — resource shape, ordering, eager loading —
follows from that.

The `api/*` `403` and `404` renders (`bootstrap/app.php:68-82`) are **already implemented and
committed at `f8aa467`**. This change consumes them; it does not touch `bootstrap/app.php`.

## Architecture Decisions

### D-1 — `show` resolves inside the controller, not by route-model binding

Implicit binding excludes soft-deleted rows (the `SoftDeletingScope` global scope), but the spec
requires `show` to resolve archived projects (`spec.md:31-48`) while `index` excludes them
(`spec.md:11-17`). Three ways to reconcile:

| Option | Behaviour | Verdict |
|---|---|---|
| `{project:key}` + `->withTrashed()` on the route + `Gate::authorize('view')` | Resolves **globally** by key, then authorizes. A non-member gets `AuthorizationException` → `403`, which discloses that the key exists. Recovering `404` means catching and re-throwing — a footgun changes 3-9 would copy | Rejected |
| `Route::bind('project', ...)` in a provider | Explicit binders are keyed by **parameter name** and are applied by `Router::substituteBindings()` before implicit binding. `routes/web.php:65,70,72,86,89` all use `{project:key}` — the binder would hijack the web surface too and start resolving archived projects there. The proposal promises the `projects` capability is unchanged | Rejected |
| **Controller-side resolution from `$request->user()->projects()`** | One owner-scoped query per endpoint. Unknown key, non-member key, and force-deleted key all take the identical `firstOrFail()` path → `ModelNotFoundException` → `NotFoundHttpException` → the committed generic `404` body. Non-disclosure holds **by construction**, not by discipline | **Chosen** |

The route parameter is therefore plain `{project}` (no `:key` suffix), which incidentally makes the
route URI and the OpenAPI path template identical — see D-6.

`ProjectPolicy::view` semantics (membership, `ProjectPolicy.php:22-25`) are preserved exactly: the
`whereKey($user->id)` existence check becomes the `project_members` join that
`User::projects()` (`app/Models/User.php:52-55`) already is. `index` keeps
`Gate::authorize('viewAny', Project::class)` for parity with `ProjectController.php:22`; it returns
`true` unconditionally (`ProjectPolicy.php:14-17`) so it cannot leak.

### D-2 — Owner scoping never assumes a single user

Both queries start from `$request->user()->projects()`, the same relation the web index uses
(`ProjectController.php:24-28`). No `where('owner_id', ...)`, no unscoped `Project::query()`.
Membership is the boundary today and stays correct the day a second user exists.

### D-3 — Resource shape

`app/Http/Resources/ProjectResource.php`, `@mixin Project`, mirroring `UserResource.php:9-29`.

| Field | Source | Rationale |
|---|---|---|
| `id` | column | Stable local primary key for the Flutter client's cache. Never addressable (D-6) |
| `key` | column | The addressable identifier |
| `name` | column | |
| `description` | column, nullable | |
| `issues_count` | `withCount('issues')` attribute | The list screen's only aggregate. `Issue` does **not** use `SoftDeletes`, so the count needs no trashed qualifier |
| `updated_at` | `?->toIso8601String()` | Owner decision — enables client delta sync. Pinned to `toIso8601String()` rather than Carbon's default `json_encode` form, which emits microseconds; `Date::use(CarbonImmutable::class)` (`AppServiceProvider.php:40`) sets no custom format, so without the explicit call the wire format is a framework default that could drift |
| `archived` | `$this->trashed()` | `deleted_at` **is** the archive mechanism (`2026_07_21_074851_add_soft_deletes_and_cascade_to_projects_table.php:12`). Exposes the state as a boolean instead of the raw column |

**Deliberately absent**: `owner_id` (constant in a single-owner app, and a foreign key the client can
do nothing with), `next_issue_number` (the allocator at `Project.php:87-100` — exposing it lets a
client predict unissued issue keys), `deleted_at` (raw timestamp superseded by `archived`),
`created_at` (no client use; adding later is non-breaking), `url` (server-side concern),
`members` / `boardColumns` / `sprints` / `labels` (changes 3-9 own those surfaces).

Adding a field later is backward compatible; removing one is not. The exclusion list is the whole
point of the resource.

### D-4 — N+1 guard: `withCount` plus a query-count pin

`Model::preventLazyLoading` is **not** enabled — `AppServiceProvider::configureDefaults()` sets only
`Date::use`, `DB::prohibitDestructiveCommands`, and `Password::defaults` (`AppServiceProvider.php:38-55`).
Nothing catches a lazy count at runtime, so the guard has to be a test. Two complementary tests,
because neither alone is sufficient:

| Test | Catches | Misses |
|---|---|---|
| Query-count equality (1 project vs 5, `DB::listen` counter reset before each request) | `withCount` dropped and replaced by `$this->issues()->count()` in the resource | `withCount` dropped with nothing replacing it (`issues_count` silently `null`) |
| Value assertion: a project with 3 issues reports `issues_count === 3` | The silent-`null` case | An N+1 that still returns correct values |

`Model::preventLazyLoading()` is additionally enabled **inside** the N+1 test only. It catches an
accidental `$this->issues` collection access; it does **not** catch `$this->issues()->count()`,
which is an explicit query, not a lazy load. Stated here so the implementer does not mistake it for
the primary guard.

### D-5 — Ordering and pagination envelope

**Ordering**: `->orderBy('projects.name')->orderBy('projects.id')`. The tiebreaker is not optional —
`name` is not unique, and without it two same-named projects can swap between requests on any
engine. **Both columns must be table-qualified**: `project_members` has its own `id` and
`timestamps` (`2026_07_21_053928_create_project_members_table.php:14-18`), so a bare `orderBy('id')`
on this `belongsToMany` is an ambiguous-column SQL error.

**Pagination**: none, per `spec.md:16-17`. The entire result set ships in one response. The envelope
and the last-page rule are pinned anyway, so the client is never guessing:

```
today   { "data": [ {...}, {...} ] }              // no "links", no "meta"
future  { "data": [...], "links": {...}, "meta": { "current_page": 1, "last_page": 3, ... } }
```

**Client rule, documented in the OpenAPI description**: *if `meta` is absent, `data` is the complete
set — there is no next page. If `meta` is present, the last page is `meta.current_page ===
meta.last_page` (equivalently `links.next === null`).* This rule is correct today and stays correct
after a paginator lands, because Laravel's paginated `AnonymousResourceCollection` keeps `data` as a
top-level array and only **adds** keys. A client written against it never breaks and never has to
hard-code "the response has exactly one key".

### D-6 — OpenAPI delta

Two operations, tag `Projects`, both `security: [{"bearerAuth": []}]`
(required by `ApiContractTest.php:69-86`).

| Operation | `operationId` | Path | Responses |
|---|---|---|---|
| `GET` list | `listProjects` | `/api/v1/projects` | `200` `ProjectCollectionResponse`, `401`, `403` |
| `GET` detail | `getProject` | `/api/v1/projects/{project}` | `200` `ProjectResponse`, `401`, `403`, `404` `NotFoundResponse` |

New schemas: `Project` (the 7 D-3 fields, all required), `ProjectResponse` (`{data: Project}`),
`ProjectCollectionResponse` (`{data: [Project]}`), `NotFoundResponse` (`{message}`), mirroring the
existing `User`/`UserResponse` pair (`openapi/v1.json:201-216`). `401`/`403` reuse
`UnauthenticatedResponse` / `ForbiddenResponse` (`openapi/v1.json:232-245`).

> **Implementer trap**: the documented path is `/api/v1/projects/{project}` — **never**
> `{project:key}`. `RouteUri::parse` strips the binding field from the stored URI and
> `ApiContractTest` compares documented paths against `$route->uri()`
> (`tests/Feature/ApiContractTest.php:14-18,52-67`). D-1's plain `{project}` parameter makes the two
> sides match literally, but the trap survives if anyone "fixes" the route back to `{project:key}`.

The `{project}` path parameter is documented as the project **`key`** (e.g. `DEMO`), never the
numeric `id`.

## Data Flow

```
GET /api/v1/projects
  auth:sanctum ──401 {"message":"No autenticado."} + WWW-Authenticate: Bearer
       │
  abilities:mobile ──403 {"message":"Este token no tiene permiso para usar esta API."}
       │                  (AccessDeniedHttpException render, bootstrap/app.php:68-74)
       ▼
  Gate::authorize('viewAny', Project::class)          → always true
       ▼
  $request->user()->projects()                        → project_members join, owner-scoped
        ->withCount('issues')                         → single aggregate subquery
        ->orderBy('projects.name')->orderBy('projects.id')
        ->get()                                       → SoftDeletingScope excludes archived
       ▼
  ProjectResource::collection(...)                    → 200 {"data":[...]}
```

Sequence for `show`, where the three inaccessible cases converge on one response:

```
phone            router              ProjectController          DB              Handler
  │  GET /api/v1/projects/{key}
  │───────────────►│
  │                │ auth + abilities pass
  │                │──────────────►│
  │                │               │ user()->projects()
  │                │               │   ->withTrashed()          ── includes archived
  │                │               │   ->withCount('issues')
  │                │               │   ->where('projects.key', $project)
  │                │               │   ->firstOrFail()
  │                │               │──────────────────────────►│
  │                │               │                            │
  │                │               │◄── row (active OR archived)│
  │                │◄── ProjectResource                          │
  │◄── 200 {"data":{... "archived": false|true}}                 │
  │                │               │
  │                │               │◄── no row ─────────────────│
  │                │               │  ModelNotFoundException     │
  │                │               │────────────────────────────────────────►│
  │                │               │       prepareException() → NotFoundHttpException
  │                │               │       api/* render (bootstrap/app.php:80-82)
  │◄── 404 {"message":"Recurso no encontrado."} ─────────────────────────────│
```

The three `no row` causes — key never existed, key belongs to a project the user is not a member of,
key was force-deleted — are **the same branch**. There is no code path that can tell them apart, so
none can leak (`spec.md:50-68`).

`withTrashed()` on a `belongsToMany`: `Relation::__call` forwards to the underlying Eloquent builder
and returns the relation, so the chain holds. Membership pivot rows survive a soft delete
(only `projects.deleted_at` is set), so an archived project is still reachable through the relation.

## File Changes

| File | Action | Description |
|---|---|---|
| `routes/api.php` | Modify | +2 GETs inside the existing group (lines 10-13); names `api.v1.projects.index` / `.show` |
| `app/Http/Controllers/Api/V1/ProjectController.php` | Create | `index`, `show`; sits beside `AuthController.php` |
| `app/Http/Resources/ProjectResource.php` | Create | 7-field allowlist, `@mixin Project` |
| `openapi/v1.json` | Modify | +1 tag, +2 operations, +4 schemas |
| `tests/Feature/ApiProjectsTest.php` | Create | list, show, archived, 404 ×3, 401, 403, N+1, ordering |
| `bootstrap/app.php` | **Unchanged** | `403`/`404` renders already committed at `f8aa467` |
| `app/Models/Project.php`, `ProjectPolicy.php`, `routes/web.php`, migrations | **Unchanged** | Reused as-is |

## Interfaces / Contracts

```php
// app/Http/Controllers/Api/V1/ProjectController.php
public function index(Request $request): AnonymousResourceCollection;
public function show(Request $request, string $project): ProjectResource;   // $project is the key
```

```php
// ProjectResource::toArray()
[
    'id' => $this->id,
    'key' => $this->key,
    'name' => $this->name,
    'description' => $this->description,
    'issues_count' => $this->issues_count,          // NEVER $this->issues()->count()
    'updated_at' => $this->updated_at?->toIso8601String(),
    'archived' => $this->trashed(),
];
```

## Testing Strategy

Strict TDD: RED before code, inside the same commit. Real bearer tokens over HTTP, never `actingAs`,
following `tests/Feature/ApiAuthTest.php:9-50` and its `apiBearerHeaders()` helper — the full
guard → ability → resolution → resource chain is exercised end to end.

| Layer | What to test | Approach |
|---|---|---|
| Feature | List returns only active projects, ordered by `name`, `data` shape asserted with `toBe()` so a leaked column fails | `getJson('/api/v1/projects', apiBearerHeaders($token))` |
| Feature | Empty membership → `200 {"data": []}`, not an error | Fresh user, no projects |
| Feature | Ordering tiebreaker: two projects sharing a `name` come back in `id` order, stable across two calls | Same request twice, `toBe()` on the id sequence |
| Feature | Show resolves an active project → `archived: false`; an archived one → `archived: true` | `$project->delete()` between calls |
| Feature | Unknown key, non-member key, and force-deleted key are byte-identical `404 {"message":"Recurso no encontrado."}` with no `App\Models\Project` substring | Three tests, one shared assertion helper |
| Feature | No token → `401` + `WWW-Authenticate: Bearer`; `['mcp']` token → `403` + the Spanish body | Mirrors `ApiAuthTest` |
| Feature (perf) | Query count identical for 1 vs 5 projects; `issues_count` value correct | `DB::listen` counter, reset before each request; `Model::preventLazyLoading()` local to this test (D-4) |
| Contract | Both operations documented, both declaring `bearerAuth` | Existing `tests/Feature/ApiContractTest.php`, no edit |

Browser E2E is not applicable — this change adds no browser surface. The pre-existing headless-render
defect affecting `tests/Browser/` is out of scope and is not a merge gate.

## Threat Matrix

| Boundary | Applicability | Reason |
|---|---|---|
| Documentation-like paths | N/A | No file-type classification, no execution of repository files |
| Git repository selection | N/A | No VCS invocation |
| Commit state | N/A | No index/worktree manipulation |
| Push state | N/A | No push automation |
| PR commands | N/A | No PR automation |

The routing boundary this change *does* introduce — guard, ability, key resolution, non-disclosure —
is covered by the RED tests above, not by this matrix. The adversarial case that matters here is
**existence disclosure**, and D-1 removes the code path that could produce it.

## Commit Boundaries

Split by endpoint so every commit registers a route *and* its OpenAPI operation together. Splitting
by layer instead (code, then docs) would leave `ApiContractTest` red in between, since it asserts
route↔document set-equality in both directions (`ApiContractTest.php:52-67`).

| # | Scope | ~Lines | Green at end |
|---|---|---|---|
| 1 | `ProjectResource`, `ProjectController::index`, list route, `Projects` tag + `listProjects` + 4 schemas in `openapi/v1.json`, list/empty/ordering/401/403/N+1 tests | ~210 | `php artisan test --compact` |
| 2 | `ProjectController::show`, show route, `getProject` operation + `NotFoundResponse`, show/archived/404×3 tests | ~170 | `php artisan test --compact` |

Total ~380 authored lines. **400-line budget risk: Medium** — under budget as one PR, but with no
headroom; if commit 2 overruns, ship it as a stacked child PR rather than growing the first.
`npm run build` is not required (no front-end surface touched). Run `vendor/bin/pint --dirty` before
each commit.

## Migration / Rollout

No migration, no schema change, nothing written to the database. Revert the branch merge: that
deletes `ProjectController`/`ProjectResource`, restores `routes/api.php` to three routes, and
restores `openapi/v1.json` to three operations. `bootstrap/app.php` is untouched by this change, so
the error contract fixed at `f8aa467` survives a revert — unlike the original proposal's rollback
note, which assumed those renders shipped here.

## Open Questions

- [ ] None blocking. `GET /api/v1/projects/trash` and every write operation remain deferred to
      `api-projects-write`, as scoped by the proposal.
