# Proposal: Project Label Catalogue (`api-labels-comments`)

## Intent

Close the **last read gap** in `/api/v1` so the Flutter client can reconstruct every screen the web
board already renders. Nine operations ship today (`routes/api.php:10-23`); every prop the web board
assembles has an API counterpart except one — `BoardController::boardProps()` ships
`$project->labels()->orderBy('name')->get(['id','name'])` as a top-level prop
(`app/Http/Controllers/BoardController.php:56,70`) and the API has no equivalent.

## Honest audit: most of this change's namesake is already shipped

The plan named this change `api-labels-comments`. Verification says the name over-promises:

| Candidate | Status | Verdict |
|---|---|---|
| An issue's **labels** | **Already shipped** — `app/Http/Resources/IssueResource.php:46-49`, on every list row and (via spread) every detail row | Nothing to build |
| An issue's **comments** | **Already shipped** — `app/Http/Resources/IssueDetailResource.php:50-58`, ordered `created_at, id` | Nothing to build |
| The **project label catalogue** | **Genuinely missing.** Embedded labels only expose labels an issue *has*; a label with zero issues is invisible to the entire API | **Build it** |
| **Paginated comments endpoint** | Speculative | **Decline** (Decision 2) |
| **`?label=` filter on `GET /issues`** | No web precedent | **Decline** (Decision 3) |
| **Label / comment mutation** | Real, but a different change | **Decline** (Decision 4) |

This change therefore ships **one endpoint**, not six. That is the honest size of the remaining gap.

### In Scope

- `GET /api/v1/projects/{project}/labels` — the project's full label catalogue with `issues_count`.
- `App\Http\Resources\LabelResource` (4-field pinned allowlist).
- `openapi/v1.json` delta: 1 tag, 1 operation, 2 schemas.

### Out of Scope

- **A paginated comments endpoint** (Decision 2). `api-issues/proposal.md:47-48` already forbids a
  redundant comment *read* endpoint; `:104` made it conditional on the embed actually biting.
- **`?label=` on `GET /issues`** (Decision 3) — the only candidate that would modify a shipped,
  tested endpoint.
- **Label and comment mutation** (Decision 4) — create/rename/delete label, attach/detach,
  post/edit/delete comment. `/api/v1` stays 100% read-only after this change.
- Label colours. `Label` is exactly `id`, `project_id`, `name` (`app/Models/Label.php:14-20`).
- A project member list endpoint. Single-owner app; `members` is the one other unmirrored board prop.
- Anything Flutter.

## Capabilities

### New Capabilities

- `api-labels`: the `/api/v1/projects/{project}/labels` read surface — its pinned resource shape,
  its `name` ordering guarantee, its `issues_count` aggregate, and its non-disclosure behaviour.

### Modified Capabilities

- **None.** `labels` (`openspec/specs/labels/spec.md`) and `comments`
  (`openspec/specs/comments/spec.md`) describe web behaviour and are unchanged. `api-issues` is
  unchanged: no shipped endpoint, resource, route, or response shape is touched.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | **Build `GET /api/v1/projects/{project}/labels`.** Nested under the project, resolved with the shared `ResolvesProjectByKey` trait (`app/Http/Controllers/Api/V1/Concerns/ResolvesProjectByKey.php:16-19`), mirroring `BoardColumnController` (`:24-29`). | Three independent justifications, in order of weight. (a) **It is the only remaining read gap**: `project`→`GET /projects/{project}`, `columns`→`board-columns`, `sprints`→`sprints`, issues→`issues`, but `labels` (`BoardController.php:56,70`) has no counterpart, so a zero-issue label is unreachable through the whole API. (b) **It is a hard prerequisite for any future label mutation** — the phone cannot attach a `label_id` it has no endpoint to enumerate, which is byte-for-byte the dependency argument `api-issues` used to block writes on `api-board-sprints` (`api-issues/proposal.md:17-23`). (c) **It backs a screen the web already has**: `resources/js/pages/projects/labels.tsx:10` renders exactly `{id, name, issues_count}`. |
| 2 | **Decline the paginated comments endpoint.** | `api-issues/proposal.md:47-48` states this change "**MUST NOT** add a redundant issue-comment *read* endpoint — Decision 4 already serves reads". `:104` framed pagination as an escape hatch conditional on the embed biting: single-owner app, threads are short, **and no measurement, complaint, or slow screen exists**. Building it now creates two sources of truth for one thread and a second wire shape to keep in sync forever. **Recorded trigger** so this stays a live hatch, not a forgotten idea: build it when any real issue exceeds ~50 comments or the detail payload exceeds ~100 KB. It is purely additive when that day comes. |
| 3 | **Decline `?label=` on `GET /issues`.** | **The web has no label filter anywhere.** `BoardController::boardProps` filters only by sprint (`:46-52`); the catalogue's sole consumer is the *picker* (`board.tsx:160` → `issue-modal.tsx:243` → `labels-picker.tsx:23,49-53`), a write affordance. `api-issues` Decision 7 (`proposal.md:78`) already pinned "one filter: `?sprint=`" and deferred assignee/type/priority as "no screen asks for them yet" — label is the same class. It would also be the **only** part of this change that edits a shipped, tested endpoint, and would drag in a foreign-label-id `404` rule, a `422` rule, and a `withQueryString()` regression surface for a feature the web itself lacks. **Honest caveat**: labels are already on every issue row (`IssueResource.php:46`), so a client can filter a loaded page for free — but that filters one page, not the project. A true filter must be server-side; it is deferred, not solved. |
| 4 | **Decline label and comment mutation.** `/api/v1` remains read-only. | Not a budget call — an **architecture** call. `openspec/specs/comments/spec.md:23-32` requires that only a comment's author may edit or delete it, **and the project owner MUST be rejected for comments they did not author**. The API's published convention is "scoping is a query constraint, never a `Gate`; inaccessible means `404`, never `403`" (`api-board-sprints/design.md:8-13`). A foreign-authored comment is *visible* (it is embedded in the detail payload), so `404` would be a lie and `403` would be the API's first authorization branch. That decision, plus 8 write operations with FormRequests, Spanish validation messages, unique-per-project handling (`specs/labels/spec.md:9-30`) and idempotent attach, is ~1200 lines and its own proposal. **This is the one place where the change's name promises more than it delivers — see question 1.** |
| 5 | **`LabelResource` = 4 pinned fields**: `id`, `name`, `issues_count`, `updated_at` (ISO-8601, `?->`). | Mirrors `BoardColumnResource`'s allowlist style (`app/Http/Resources/BoardColumnResource.php:27-32`). Omits `project_id` (the URL carries it) and `created_at` (no client screen uses it; adding it later is additive, removing it is breaking) — the rule set in `api-board-sprints/design.md:102-106`. **`issues_count` IS included**, unlike `BoardColumnResource` where it was rejected as "meaningless without a sprint filter": a label's count is sprint-independent and the web already ships it (`LabelController.php:33`, `pages/projects/labels.tsx:10`). It MUST come from `withCount('issues')` — a correlated sub-select, never `->issues()->count()` per row. |
| 6 | **Order by `name` alone — no `id` tiebreaker, and it is provable.** | `unique(['project_id','name'])` exists at the DB level (`database/migrations/2026_07_21_054818_create_labels_table.php:21`), so `name` is a **total** order inside one project in both dialects: two names that compare equal under any collation cannot both exist. This is the board-columns `position` argument (`api-board-sprints/design.md:227`), not the sprints `start_date` one. **Fixture rule for spec/design**: `LabelFactory` uses `fake()->unique()->word()` (`:23`), so any ordering assertion MUST pin explicit lowercase names — SQLite compares `name` with BINARY collation, so `'Bug'` sorts before `'apple'`, and a case-mixed fixture would encode a dialect artefact into a `toBe()`. |
| 7 | **Bare `{"data": [...]}` — no pagination, no `links`, no `meta`.** | Consistent with `board-columns` and `sprints`, and it keeps the published client rule intact: absent `meta` means `data` is the complete set (`api-projects/design.md:100-105`). A project holds tens of labels, not hundreds. Adding pagination later is the additive direction. |
| 8 | **404 non-disclosure inherited whole, three cases, one branch.** Unknown project key, non-member project, **archived project** — all `404 {"message":"Recurso no encontrado."}`. | `ResolvesProjectByKey` deliberately omits `withTrashed()` (`:12-14`), so `SoftDeletingScope` excludes archived projects and `firstOrFail()` has exactly one failure mode. Non-disclosure holds by construction, not by discipline (`api-board-sprints/design.md:288-301`). No new `403`, no `422` — the operation declares no query parameter. |
| 9 | **OpenAPI: path is `/api/v1/projects/{project}/labels`, tag `Labels`, `operationId` `listProjectLabels`.** | `documentedApiOperations()` asserts set-equality against `$route->uri()` in **both** directions (`tests/Feature/ApiContractTest.php:42-67`) and a separate test requires exactly `[['bearerAuth' => []]]` on every non-login operation (`:69-86`). **Implementer trap**: never `{project:key}` — a binding field in the document or the route breaks the first test. Takes the contract from 9 documented operations to 10. |

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `app/Http/Controllers/Api/V1/LabelController.php` | New | `index` only; `use ResolvesProjectByKey;` |
| `app/Http/Resources/LabelResource.php` | New | 4-field allowlist, `@mixin Label` |
| `routes/api.php` | Modified | +1 GET inside the existing group (`:14-23`); name `api.v1.projects.labels.index` |
| `openapi/v1.json` | Modified | +1 tag, +1 operation, +2 schemas (`Label`, `LabelCollectionResponse`) |
| `tests/Feature/ApiLabelsTest.php` | New | shape, ordering, zero-issue label, `issues_count`, empty catalogue, 404 ×3, 401/403, N+1 guard |
| `app/Http/Resources/IssueResource.php`, `IssueDetailResource.php`, `Api/V1/IssueController.php` | **Unchanged — enforced** | Decision 3. `git diff` on these MUST be empty |
| `app/Models/*`, `database/factories/*`, `routes/web.php`, `bootstrap/app.php`, `Concerns/ResolvesProjectByKey.php` | **Unchanged** | Relations, error renders and the trait reused as-is |
| migrations | **None** | No schema change |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| `issues_count` implemented as `->issues()->count()` per row | Med | Query-count-equality guard (1 label vs 5) with `Model::preventLazyLoading()` enabled **locally** in `try/finally` — it is not on app-wide — plus `forgetGuards()` and a counter reset before each request |
| A dropped `withCount` returns `null` and keeps the query count flat, so the guard passes for the wrong reason | **High** | **Paired value assertion is mandatory**: every returned `issues_count` equals its seeded number, including a `0` for a zero-issue label. This is the exact blind spot `api-projects` had |
| Ordering assertion encodes a SQLite BINARY-collation artefact and flips under MySQL | Med | Decision 6's fixture rule: explicit lowercase names, never `fake()->word()` |
| The endpoint ships and no client calls it, because the picker it feeds needs writes | **Med — accepted** | Named openly. Justification (a) — closing the last read gap so a zero-issue label is reachable — stands on its own; a read-only labels screen mirroring `pages/projects/labels.tsx` is shippable today |
| The change under-delivers against the plan's name and the owner expected mutation | **Med** | Question 1. Decision 4 states the reason and the size of the real alternative. Reversible: mutation is purely additive on top of this |
| `{project:key}` documented or routed | Low | Decision 9 names it; `ApiContractTest` fails set-equality in both directions |

## Rollback Plan

No migration, no schema change, no row written or mutated — the endpoint is a `GET`.

**Revert the branch merge.** That deletes `LabelController` and `LabelResource`, restores
`routes/api.php` to nine routes and `openapi/v1.json` to nine operations. `ApiContractTest` re-greens
automatically because the revert removes the route and its documentation together. There is **no
partial-revert case and no shared-file refactor** — unlike `api-board-sprints`, which had to modify a
shipped `IssueController` to extract `ResolvesProjectByKey`, this change only *consumes* that trait.
Every existing file except `routes/api.php` and `openapi/v1.json` is untouched, so nothing outside
`/api/v1` can regress and no shipped endpoint changes shape.

No data cleanup. No client-visible state to reconcile beyond a released Flutter build losing one
endpoint — mitigated by not shipping the labels screen until this merges.

## Dependencies

- **None new.** No Composer or npm package. Builds on `api-token-auth`, `api-projects`, `api-issues`
  and `api-board-sprints`, all merged.
- Consumes `ResolvesProjectByKey`, shipped by `api-board-sprints`.
- Any future label/comment mutation change depends on **this** one (Decision 1b).

## Delivery Forecast

Estimated **~340 changed lines** against the 400-line review budget → **400-line budget risk: Low**
(~0.85×). **Decision needed before apply: No. Chained PRs recommended: No.** Ships as **one PR with
one atomic commit** — `size:exception` is pre-authorised but **not needed**.

| # | Scope | ~Lines | Green at end |
|---|---|---|---|
| 1 | `LabelResource`; `LabelController::index` with `withCount('issues')`; route; OpenAPI `Labels` tag + `listProjectLabels` + `Label`/`LabelCollectionResponse`; `ApiLabelsTest` (shape, ordering, zero-issue label, counts, empty catalogue, 404 ×3, 401/403, N+1) | ~340 | `php artisan test --compact` |

The commit cannot be split by layer: `ApiContractTest` asserts route↔document set-equality in both
directions (`ApiContractTest.php:52-67`), so a route without its operation is red and an operation
without its route is red. Run `vendor/bin/pint --dirty` before the commit. `npm run build` is not
required — no front-end surface is touched. Strict TDD: RED before code, inside the same commit.

## E2E Acceptance

Feature-level HTTP in `tests/Feature/ApiLabelsTest.php`, following the `ApiProjectsTest` convention
(`tests/Feature/ApiProjectsTest.php:16-25`): a real bearer token driving real requests, never
`actingAs`, so guard → ability → project scoping → resource runs end to end. Reuse
`createMemberProject()` and `apiBearerHeaders()`; add a local `labelPayload()` helper mirroring
`projectPayload()` (`:30-41`) and an `assertProjectNotFound()`-style helper (`:49-54`).

Browser E2E is **not** proposed and MUST NOT gate this merge: no browser surface is added, and the
browser suite is red from a pre-existing headless-render environment defect out of scope here.

## Success Criteria

- [ ] `GET /api/v1/projects/{key}/labels` returns bare `{"data":[…]}` with **no** `links`/`meta`, and
      every element has exactly the 4 Decision-5 fields — asserted with `toBe()` so a leaked column
      (`project_id`, `created_at`) fails.
- [ ] Labels come back ordered by `name`, and the order is identical across two consecutive calls.
- [ ] **A label attached to zero issues is present in the response with `issues_count: 0`** — the
      single behaviour that embedded labels (`IssueResource.php:46`) cannot deliver, and therefore
      the reason this change exists.
- [ ] `issues_count` is correct for a label on several issues; a project with no labels returns
      `{"data":[]}`, never `404`.
- [ ] Query count is identical for 1 label and 5, with `Model::preventLazyLoading()` enabled inside
      the test, **and** every count value asserted (catches a dropped `withCount` returning `null`).
- [ ] All three Decision-8 cases return `404 {"message":"Recurso no encontrado."}` with no
      `App\Models\Project` substring in the body.
- [ ] No token → `401` + `WWW-Authenticate: Bearer`; an `mcp`-ability token → `403` + Spanish body.
- [ ] `ApiContractTest` passes with 10 documented operations, all declaring `bearerAuth`.
- [ ] `git diff` on `IssueResource.php`, `IssueDetailResource.php` and `Api/V1/IssueController.php`
      is **empty** — no shipped endpoint changed shape.
- [ ] `php artisan test --compact` is green.

## Proposal question round

Interactive shaping was unavailable (`auto` mode; the owner explicitly asked not to be stopped).
These questions materially change the proposal and are recorded for correction before `sdd-spec`:

1. **Is `/api/v1` meant to stay read-only forever?** This is the last change in the plan, and
   Decision 4 declines mutation. If the phone must post a comment or attach a label, that is a
   separate `api-writes` change (~1200 lines) whose first architectural question is whether the
   comment-authorship rule (`specs/comments/spec.md:23-32`) returns `403` — the API's first
   authorization branch — or `404`. **Assumed: read-only companion app; writes go through the web.**
2. **Does the mobile client have a labels screen, or only issue cards?** If it never renders a label
   catalogue *and* never writes, Decision 1's justification narrows to (a) alone and the honest
   answer becomes "build nothing at all". **Assumed: yes — mirroring `pages/projects/labels.tsx`.**
3. **Does the mobile board need a label filter?** Decision 3 declines it because the web has none.
   If the phone's board ships a filter bar on day one, `?label=` must be pulled into this change,
   and it would be the only edit to a shipped endpoint. **Assumed: no filter bar in v1.**
4. **Should `issues_count` count issues across *all* sprints, or only the selected one?** Decision 5
   ships the all-sprints count, matching `LabelController.php:33`. A sprint-scoped count would need a
   `?sprint=` parameter and would reopen the board-columns argument. **Assumed: all sprints.**
5. **Is a paginated comments endpoint wanted despite no evidence it is needed?** Decision 2 declines
   it and records a concrete trigger (~50 comments on one issue). **Assumed: not now.**
