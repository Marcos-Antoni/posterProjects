# Api-Board-Sprints Specification

## Purpose

Read-only `/api/v1/projects/{project}/board-columns` and `/api/v1/projects/{project}/sprints`
surfaces for `posterMobile`: the naming vocabularies for the `board_column_id` and `sprint_id`
already present on every issue (`app/Http/Resources/IssueResource.php:38-40`). No mutation, no
nested `/board` endpoint — the client composes a board from these two collections plus the
existing `GET /issues?sprint={id}`.

## Requirements

### Requirement: Both List Endpoints Return Complete, Unpaginated, Deterministically Ordered Collections

Both `GET .../board-columns` and `GET .../sprints` MUST return `{project}`'s full row set as bare
`{"data": [...]}` — no pagination, no `links`/`meta` keys, no query parameter accepted — consistent
with the client rule that an absent `meta` means `data` is complete. Ordering and field shape are
pinned:

| Endpoint | Order | Tiebreaker | Fields (exact) |
|---|---|---|---|
| `board-columns` | `position` ASC | None needed — DB `unique(project_id, position)` makes it a total order within one project (`app/Models/BoardColumn.php:59-60`) | `id`, `name`, `position`, `updated_at` |
| `sprints` | `start_date` DESC | `id` DESC, mandatory — `start_date` is a `date` cast (`app/Models/Sprint.php:37`) and MAY repeat | `id`, `name`, `goal`, `start_date`, `end_date`, `state`, `issues_count`, `updated_at` |

A board column with zero issues assigned to it MUST still appear in the response. `issues_count`
MUST come from an eager `withCount('issues')` aggregate, never a per-row `->count()` call
(`app/Http/Resources/ProjectResource.php:19-21`).

#### Scenario: Columns are position-ordered and an empty one is not hidden

- GIVEN a project with 3 columns at positions 0-2, one holding no issues
- WHEN the endpoint is called
- THEN `data` SHALL list all three in that order with only the pinned four fields

#### Scenario: Sprints break a shared start_date tie by descending id

- GIVEN two sprints with an identical `start_date`
- WHEN the endpoint is called
- THEN they SHALL appear in descending `id` order

#### Scenario: The envelope carries only data, no query params accepted

- GIVEN any project, and separately a request with an arbitrary query string
- WHEN either endpoint is called
- THEN the response body SHALL contain only the `data` key

### Requirement: Sprint `state` Is Computed From An Inclusive UTC Day Anchor

`state` MUST be computed server-side: `future` when `start_date` is after today, `completed` when
`end_date` is before today, `active` otherwise — both bounds inclusive. "Today" MUST anchor to
`today()` in the application timezone, which is `UTC` (`config/app.php:68`), matching
`BoardController::resolveActiveSprint` exactly
(`app/Http/Controllers/BoardController.php:82-86`). This is deliberately NOT the UTC-6 anchor used
for habit-day math (`openspec/config.yaml:13`) — that rule is habits-only and MUST NOT be reused
here, or `state` would silently disagree with the web board for six hours a day.

#### Scenario: Both inclusive boundaries and a single-day sprint are active

- GIVEN a sprint starting today (ending later), one ending today (started earlier), and one where
  `start_date == end_date ==` today
- WHEN the endpoint is called
- THEN all three SHALL have `state: "active"`

#### Scenario: Future and completed sprints classify correctly

- GIVEN a sprint starting tomorrow and a separate sprint that ended yesterday
- WHEN the endpoint is called
- THEN the first SHALL be `future` and the second SHALL be `completed`

#### Scenario: The first active sprint matches the web board's default pick

- GIVEN sprints ordered per the table above, including two overlapping active sprints
- WHEN a client selects the first element with `state: "active"`
- THEN it SHALL match `BoardController::resolveActiveSprint`'s pick for identical data

### Requirement: Ability Boundary Enforced On Both Routes

Both routes MUST sit behind `auth:sanctum` + `abilities:mobile`. No token MUST respond `401` with
header `WWW-Authenticate: Bearer`. A token lacking the `mobile` ability (e.g. `mcp`-only) MUST
respond `403` with the documented Spanish body.

#### Scenario: An mcp-only token is rejected, a missing token challenges

- GIVEN a bearer token with ability `['mcp']` only, and separately no `Authorization` header
- WHEN either endpoint is called
- THEN the first response SHALL be `403` with the Spanish body and the second SHALL be `401` with
  `WWW-Authenticate: Bearer`

### Requirement: Not Found Non-Disclosure Matches `api-issues`

Both endpoints MUST respond `404` with `{"message": "Recurso no encontrado."}`, byte-identical,
for all three: an unknown project key, a project the requester is not a member of, and an archived
(soft-deleted) project. This mirrors the `api-issues` spec's convention exactly — deliberately
inconsistent with `api-projects`'s `show`, which resolves an archived project with `archived: true`.
No response body MUST contain an `App\Models\Project` substring, and no case MUST return `403` for a
reason other than the ability check above.

#### Scenario: All three not-found cases are indistinguishable

- GIVEN an unknown project key, a project the requester is not a member of, and an archived
  project the requester previously belonged to
- WHEN either endpoint is called for each
- THEN all three responses SHALL be `404` with the identical generic body

### Requirement: Serialization Does Not Cause N+1 Queries

Neither endpoint's total query count MUST scale with the number of columns or sprints returned.
`Model::preventLazyLoading()` is not enabled application-wide, so this MUST be asserted by
comparing query counts across two fixture sizes, paired with a value assertion so a degraded
aggregate returning `null` also fails.

#### Scenario: Query count is identical for 1 and 5 rows

- GIVEN a project with 1 board column, and separately 5, and likewise 1 sprint and separately 5
- WHEN each endpoint is called for each fixture size
- THEN the total query count SHALL be identical within each endpoint, and every field SHALL be
  correctly populated

### Requirement: OpenAPI Contract Coverage

Both operations MUST be documented in `openapi/v1.json` under tags `Board Columns` and `Sprints`,
`operationId`s `listProjectBoardColumns` / `listProjectSprints`, each declaring
`security: [{"bearerAuth": []}]`. The `{project}` path parameter MUST be documented as a plain
string, without a route-model-binding type suffix, matching `api-issues`.

#### Scenario: The contract test passes for both new operations

- GIVEN the two routes are registered and documented
- WHEN `ApiContractTest` runs
- THEN registered routes and documented operations SHALL be set-equal in both directions
