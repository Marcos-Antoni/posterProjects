# Design: Project Label Catalogue (`api-labels-comments`)

## Technical Approach

One GET added to the existing `['auth:sanctum','abilities:mobile']` group (`routes/api.php:14-23`).
No migration, no dependency, no middleware, no policy, no query parameter, no model change, and — per
`proposal.md:80` — **no edit to any shipped endpoint**.

Everything architectural was decided by the siblings and is inherited verbatim:

1. **Scoping is a query constraint, never a `Gate`** (`api-projects/design.md:5-13`). Inaccessible
   means `404`, never `403`.
2. **`{project}` resolves through the shared trait** (`Concerns/ResolvesProjectByKey.php:16-19`) —
   this change *consumes* it, it does not touch it. No fourth copy of those five lines.
3. **Serialising touches zero rows.** `LabelResource` reads no relation accessor, so the entire N+1
   surface is one thing: `issues_count` (D-3).

Nothing here is new. That is the point of D-1.

## Architecture Decisions

### D-1 — The controller is the shortest in `/api/v1`, and it should stay that way

```php
// app/Http/Controllers/Api/V1/LabelController.php
class LabelController extends Controller
{
    use ResolvesProjectByKey;

    public function index(Request $request, string $project): AnonymousResourceCollection
    {
        $resolvedProject = $this->resolveProject($request, $project);

        return LabelResource::collection(
            $resolvedProject->labels()->withCount('issues')->orderBy('labels.name')->get()
        );
    }
}
```

Two statements. It is byte-for-byte the `SprintController` shape (`SprintController.php:25-36`) minus
the tiebreaker, and one line longer than `BoardColumnController` (`:24-29`). **It is the shortest
controller in the API and it must stay that way** — there is no FormRequest (no parameter to
validate), no service, no query object, no `Builder` extraction, and no `LabelQuery` class. Anything
added here is structure the endpoint does not have work for.

| Option | Verdict |
|---|---|
| **`use ResolvesProjectByKey;`** | **Chosen.** The trait exists precisely so the third and fourth consumer cost one line |
| Copy the five resolver lines a fourth time | Rejected — `api-board-sprints/design.md:45` already named the third copy as "where drift starts". This is the fourth |
| Route-model binding on `Label` | Rejected — the route addresses no single label; and `{project}` is a `key`, not an id, so binding is unavailable upstream anyway (`api-issues/design.md:11-14`) |
| `withCount` moved into a `Project::labelsWithCounts()` scope | Rejected — the web already inlines the identical query (`LabelController.php:33`); a shared scope would modify a shipped model that `proposal.md:81` pins as unchanged, to save nothing |

`labels()` is a plain `HasMany` (`Project.php:68-71`) with no pivot, so column qualification on
`orderBy` is not strictly required — it is used anyway so the API has one rule, not two, exactly as
`SprintController.php:31-32` does.

### D-2 — `LabelResource`: four fields

```php
['id' => $this->id, 'name' => $this->name,
 'issues_count' => $this->issues_count,              // eager aggregate — NEVER ->issues()->count()
 'updated_at' => $this->updated_at?->toIso8601String()]
```

`@mixin Label`, allowlist style pinned like `BoardColumnResource:25-33`. `updated_at` keeps its `?->`
because `Label`'s timestamps are nullable in the docblock (`app/Models/Label.php:17-18`).

| NOT exposed | Why |
|---|---|
| `project_id` | The URL carries the project. A numeric FK the client cannot address (`api-issues/design.md:116`) |
| `created_at` | No client screen uses it. Omitting is the reversible direction — adding later is additive, removing later is breaking (`api-board-sprints/design.md:104`) |
| nested `issues` | Would be an unbounded array inside an unpaginated collection. The client composes with `GET /issues`, where labels already ride on every row (`IssueResource.php:46-49`) |
| a `color` field | No column exists. `Label` is exactly `id`, `project_id`, `name` (`Label.php:14-20`) |
| `can_edit` / `can_delete` | `/api/v1` is read-only (proposal D-4). Shipping a permission flag with no endpoint behind it is a promise the API cannot keep |

Unlike `BoardColumnResource`, `issues_count` **is** exposed: a label's count is sprint-independent,
so it needs no `?sprint=` parameter, and the web already ships it (`LabelController.php:33`,
`resources/js/pages/projects/labels.tsx:10`).

### D-3 — `issues_count`, and the guard that actually catches a dropped `withCount`

`withCount('issues')` emits one correlated sub-select, **not** a join, so the `->select('issues.*')`
hydration trap from `api-issues` does not exist here. `issues` is a `BelongsToMany` through
`issue_label` (`Label.php:37-40`); `withCount` handles the pivot without qualification.

The guard mirrors `ApiSprintsTest.php:225-276` structurally:

| Element | Why it is mandatory |
|---|---|
| `Model::preventLazyLoading()` inside `try { … } finally { Model::preventLazyLoading(false); }` | Strict mode is **not** on app-wide, so it must be enabled locally; the `finally` stops it leaking into every later test in the file |
| `DB::listen` counting statements where `str_starts_with(strtolower(trim($q->sql)), 'select')` | Same counter as `ApiSprintsTest:241-245` |
| `$this->app['auth']->forgetGuards()` **and** `$selectCount = 0` before **each** request | Without `forgetGuards()` the resolved token is cached and the second call issues fewer queries — the assertion would pass for the wrong reason (`ApiSprintsTest:247,260`) |
| 1 label vs 5, `expect($manyQueries)->toBe($soloQueries)` | Catches `->issues()->count()` per row |
| **Paired value assertions, including a zero-issue label** | See below. Non-negotiable |

**Why the count-only assertion is not enough, stated precisely.** If `withCount('issues')` is dropped
entirely, `$this->issues_count` resolves to `null` — Eloquent returns `null` for a missing attribute
rather than raising, and `preventLazyLoading` never fires because no relation was touched. The query
count stays flat. **The guard passes on a completely broken endpoint.** This is the exact blind spot
`api-projects` shipped with (`proposal.md:89`).

The pairing therefore has two halves and both are required:

1. Every non-zero label asserts its seeded number with `toBe(N)` — this is what distinguishes a real
   aggregate from `null`.
2. **A label attached to zero issues asserts `toBe(0)`, strictly.** Never `toBeEmpty()`,
   `toBeFalsy()`, or `==` — `null` satisfies all three and would re-open the hole the first assertion
   closed. This label is also the endpoint's whole reason to exist: it is the one row embedded labels
   (`IssueResource.php:46`) can never deliver.

### D-4 — Ordering by `name` alone, and the SQLite collation trap in the fixture

`ORDER BY labels.name`, **no `id` tiebreaker, and its absence is provable.**
`unique(['project_id','name'])` exists at the DB level
(`database/migrations/2026_07_21_054818_create_labels_table.php:21`), so inside one project two names
that compare equal under any collation cannot both exist — `name` is a *total* order. This is the
board-columns `position` argument (`api-board-sprints/design.md:227`), not the sprints `start_date`
one. It also matches the web (`LabelController.php:33`) exactly, so phone and web agree by
construction.

**Fixture rule, non-negotiable: every ordering assertion pins explicit lowercase names.**
`LabelFactory::definition()` uses `fake()->unique()->word()` (`database/factories/LabelFactory.php:23`),
which is unusable for a `toBe()` array assertion for two independent reasons:

| Hazard | Effect |
|---|---|
| Values are random | The expected array cannot be written literally; any assertion degrades to "is sorted", which passes even if the endpoint sorts by `id` and the words happen to arrive alphabetical |
| Case mixing is dialect-dependent | SQLite compares `TEXT` with **BINARY** collation, so `'Bug'` (0x42) sorts **before** `'apple'` (0x61). MySQL's default `utf8mb4_*_ci` is case-insensitive and puts `'apple'` first. A case-mixed fixture green on SQLite encodes a dialect artefact into a `toBe()` and flips the day the suite runs on MySQL |

Fixture: `'backend'`, `'bug'`, `'frontend'`, `'urgent'` — created in a deliberately non-alphabetical
insertion order so the assertion proves `ORDER BY` and not insertion order, and seeded lowercase so
BINARY and case-insensitive collations agree. `LabelFactory` itself is **not** modified: changing a
shared factory default is out of scope and would ripple into `LabelTest` and the MCP suite.

### D-5 — OpenAPI delta: one operation

| Operation | `operationId` | Documented path | Tag | Responses |
|---|---|---|---|---|
| `GET` labels | `listProjectLabels` | `/api/v1/projects/{project}/labels` | `Labels` | `200` `LabelCollectionResponse`, `401`, `403`, `404` |

New schemas: `Label` (4 fields, all `required`, `issues_count` documented as an eager aggregate) and
`LabelCollectionResponse` — bare `{"data": [...]}` with **no** `links`/`meta`, mirroring
`BoardColumnCollectionResponse` (`openapi/v1.json:912-921`). `401`/`403`/`404` reuse the committed
`UnauthenticatedResponse` / `ForbiddenResponse` / `NotFoundResponse`. **`422` is absent** — the
operation declares no query parameter, same as board-columns and sprints. `security` is exactly
`[{"bearerAuth": []}]`. The `Labels` tag description follows the `Sprints` pattern
(`openapi/v1.json:32-34`): read-only catalogue, ordered by name, complete because an absent `meta`
means complete.

> **Implementer trap**: the documented path parameter is `{project}` — **never** `{project:key}`.
> `documentedApiOperations()` builds `strtoupper($method).' '.ltrim($path,'/')` and asserts
> set-equality against `$route->uri()` in **both** directions
> (`tests/Feature/ApiContractTest.php:42-67`); a separate test requires exactly `[['bearerAuth' => []]]`
> on every non-login operation (`:69-86`). A binding field in either the document or the route breaks
> the first. Contract goes from 9 documented operations to 10.

### D-6 — `404` non-disclosure: three cases, one branch

Inherited whole from the trait, which has exactly one failure mode, so the response *cannot* branch on
the reason: unknown key → no row; non-member → `$request->user()->projects()` is a `belongsToMany`
through `project_members`, so a stranger's project is not in the relation; archived → the deliberate
absence of `withTrashed()` (`ResolvesProjectByKey.php:12-14`) lets `SoftDeletingScope` exclude it.
All three render `404 {"message":"Recurso no encontrado."}` from `bootstrap/app.php`, unchanged.
A project with **no labels** is a different case entirely: it resolves, and returns `{"data":[]}`.

## Data Flow

```
phone            router          Api\V1\LabelController        DB              LabelResource
  │ GET /api/v1/projects/DEMO/labels
  │───────────────►│ auth:sanctum ──► 401 {"message":"No autenticado."} + WWW-Authenticate: Bearer
  │                │ abilities:mobile ──► 403 {"message":"Este token no tiene permiso…"}
  │                │──────────────────►│
  │                │                   │ resolveProject()   (trait, unchanged)
  │                │                   │────────────────────►│
  │                │                   │◄── no row ──────────│  unknown / non-member / archived
  │                │                   │  ModelNotFoundException ──► 404 (one body, D-6)
  │                │                   │
  │                │                   │◄── Project ─────────│
  │                │                   │ labels()
  │                │                   │   ->withCount('issues')   ← ONE correlated sub-select
  │                │                   │                             over issue_label, not a join
  │                │                   │   ->orderBy('labels.name') ← total order, no tiebreaker (D-4)
  │                │                   │────────────────────►│
  │                │                   │◄── rows + issues_count ───│   0 for an unattached label
  │                │                   │──────────────────────────────────────►│
  │◄── 200 {"data":[{id,name,issues_count,updated_at}]} ──────────────────────│  bare {data}
```

## File Changes

| File | Action | Description |
|---|---|---|
| `app/Http/Controllers/Api/V1/LabelController.php` | Create | `index` only, `use ResolvesProjectByKey;`. Two statements (D-1) |
| `app/Http/Resources/LabelResource.php` | Create | 4-field allowlist, `@mixin Label` (D-2) |
| `routes/api.php` | Modify | +1 GET inside the group at `:14`; name `api.v1.projects.labels.index` |
| `openapi/v1.json` | Modify | +1 tag (after `Sprints` at `:32-34`), +1 operation, +2 schemas |
| `tests/Feature/ApiLabelsTest.php` | Create | shape, ordering, zero-issue label, counts, empty catalogue, 404 ×3, 401/403, query-count guard |
| `app/Http/Resources/IssueResource.php`, `IssueDetailResource.php`, `Api/V1/IssueController.php` | **Unchanged — enforced** | Proposal D-3. `git diff` on these MUST be empty |
| `Concerns/ResolvesProjectByKey.php`, `app/Models/*`, `database/factories/*`, `routes/web.php`, `bootstrap/app.php` | **Unchanged** | Trait, relations, factories and error renders consumed as-is |
| migrations | **None** | No schema change |

## Interfaces / Contracts

```php
// app/Http/Controllers/Api/V1/LabelController.php
public function index(Request $request, string $project): AnonymousResourceCollection;
//                                        ↑ the project key ("DEMO"), never a numeric id
```

```
GET /api/v1/projects/DEMO/labels
{ "data": [ { "id": 4, "name": "backend",  "issues_count": 3,
              "updated_at": "2026-08-01T10:22:31+00:00" },
            { "id": 9, "name": "urgent",   "issues_count": 0,   ← the row that justifies the endpoint
              "updated_at": "2026-08-01T10:22:31+00:00" } ] }
```

No `links`, no `meta` — absent `meta` means `data` is the complete set
(`api-projects/design.md:100-105`).

## Testing Strategy

Strict TDD: RED before code, inside the same commit. Real bearer tokens over HTTP, never `actingAs`,
reusing `createMemberProject()` and `apiBearerHeaders()` (`tests/Feature/ApiProjectsTest.php:16-25`)
so guard → ability → project scoping → resource runs end to end. A local `labelPayload()` helper
mirrors `projectPayload()` (`:30-41`); an `assertProjectNotFound()`-style helper mirrors `:49-54`.

| Layer | What to test | Approach |
|---|---|---|
| Feature | Exactly the 4 D-2 fields | `expect($r->json('data'))->toBe([...])` — a leaked `project_id`/`created_at` fails |
| Feature | Ordered by `name`, stable across two consecutive calls | Explicit lowercase fixture (D-4), inserted non-alphabetically |
| Feature | **A zero-issue label is present with `issues_count` `toBe(0)`** | Strict `toBe`, never `toBeEmpty`/`toBeFalsy` (D-3) |
| Feature | `issues_count` correct for a label on several issues | Value assertion, paired with the guard below |
| Feature | A project with no labels returns `{"data":[]}`, never `404` | Distinguishes empty catalogue from unresolved project |
| Feature (perf) | Query count identical for 1 vs 5 labels, `preventLazyLoading()` in `try/finally`, `forgetGuards()` + counter reset per request, **every value asserted** | Structure mirrors `ApiSprintsTest:225-276` (D-3) |
| Feature | All three D-6 cases → byte-identical `404 {"message":"Recurso no encontrado."}`, body containing no `App\Models\Project` | Three tests, one shared helper |
| Feature | No token → `401` + `WWW-Authenticate: Bearer`; `['mcp']` token → `403` + Spanish body | Mirrors `ApiAuthTest` |
| Regression | `ApiIssuesTest`, `ApiBoardColumnsTest`, `ApiSprintsTest` green with **zero edits** | Nothing shipped is touched |
| Contract | 10 documented operations, all declaring `bearerAuth` | Existing `tests/Feature/ApiContractTest.php`, no edit |

Browser E2E is **not** applicable and MUST NOT gate this merge: no browser surface is added, and the
browser suite is red from a pre-existing headless-render defect out of scope here.

## Threat Matrix

The change introduces a **routing** boundary (one new URL segment resolved from user input) and no
other boundary from the reference matrix.

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | **N/A** — no file-type classification, no execution of repository files | — | — |
| Git repository selection | **N/A** — no VCS invocation at runtime | — | — |
| Commit state | **N/A** — no index/worktree manipulation | — | — |
| Push state | **N/A** — no push automation | — | — |
| PR commands | **N/A** — no PR automation | — | — |
| **Routing / identifier resolution** (applicable) | `{project}` is an attacker-controlled string resolved in code | The trait's single owner-scoped `firstOrFail()`, `withTrashed()`-free; D-6's one-branch convergence; no query parameter at all, so no parser to abuse | The three-case `404` suite, each asserting the body contains no `App\Models\Project` |

The adversarial case that matters is **existence disclosure**, and the trait removes the code path
that could produce it: there is no variant of the branch that can report *why* it failed.

## Commit Boundaries

**One endpoint, therefore one commit — a split would be invented, not discovered.**
`ApiContractTest` asserts route↔document set-equality in **both** directions (`ApiContractTest.php:52-67`),
so a commit registering the route without its operation is red, and one documenting the operation
without its route is equally red. Resource-then-controller is not a valid split either: neither is
reachable, and therefore neither is testable, without the route.

| # | Scope | ~Lines | Green at end |
|---|---|---|---|
| 1 | `LabelResource`; `LabelController::index`; route; OpenAPI `Labels` tag + `listProjectLabels` + `Label`/`LabelCollectionResponse`; `ApiLabelsTest` | ~340 | `php artisan test --compact` |

~340 authored lines → **400-line budget risk: Low (~0.85×). Decision needed before apply: No.
Chained PRs recommended: No.** `size:exception` is pre-authorised but not needed. Run
`vendor/bin/pint --dirty` before the commit. `npm run build` is not required — no front-end surface
is touched.

## Migration / Rollout

No migration, no schema change, no row written or mutated — the endpoint is a `GET`.

Revert the branch merge: that deletes `LabelController` and `LabelResource` and restores
`routes/api.php` to nine routes and `openapi/v1.json` to nine operations. `ApiContractTest` re-greens
automatically because the revert removes the route and its documentation together. **There is no
partial-revert case**: unlike `api-board-sprints`, which had to modify a shipped `IssueController` to
extract `ResolvesProjectByKey`, this change only *consumes* that trait. Every file except
`routes/api.php` and `openapi/v1.json` is either new or untouched, so nothing outside `/api/v1` can
regress and no shipped endpoint changes shape. No data cleanup.

## Open Questions

- [ ] **None blocking.** The proposal's five recorded assumptions (`proposal.md:164-185`) carry into
      this design unchanged; each is reversible and additive.
- [ ] Accepted, not resolved: the endpoint's strongest consumer (the label *picker*) needs writes,
      which this change declines. Justification (a) — a zero-issue label being unreachable through the
      entire API — stands alone, and a read-only labels screen mirroring `pages/projects/labels.tsx`
      is shippable today.
- [ ] `LabelFactory`'s `fake()->unique()->word()` default (D-4) is unusable for any deterministic
      ordering assertion. This change works around it with explicit fixtures rather than fixing the
      shared factory, which is out of scope. Same class of latent trap as `BoardColumnFactory`'s
      random `position` (`api-board-sprints/design.md:499-502`); worth its own tiny change later.
