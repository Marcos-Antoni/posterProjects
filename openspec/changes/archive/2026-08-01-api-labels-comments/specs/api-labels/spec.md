# Api-Labels Specification

## Purpose

Read-only `GET /api/v1/projects/{project}/labels` for `posterMobile`: the project's full label
catalogue with `issues_count`. An issue's labels are already embedded on every issue row
(`app/Http/Resources/IssueResource.php:46-49`), but that embed exposes only labels an issue
*has* — a zero-issue label is unreachable elsewhere in the API. This endpoint closes that gap.
New capability.

## Requirements

### Requirement: The List Endpoint Returns A Complete, Unpaginated, Name-Ordered Catalogue

`GET .../labels` MUST return `{project}`'s full label set as bare `{"data": [...]}` — no
pagination, no `links`/`meta`, no query parameter — per the absent-`meta`-means-complete client
rule (`openspec/changes/api-projects/design.md:100-105`). Each element MUST carry exactly four
fields: `id`, `name`, `issues_count`, `updated_at` (ISO-8601, nullable via `?->`).

Labels MUST be ordered by `name` ascending, with no tiebreaker needed:
`unique(['project_id', 'name'])` at the DB level
(`database/migrations/2026_07_21_054818_create_labels_table.php:21`) makes `name` a total order
within one project — no two rows can ever tie. A label attached to zero issues MUST still
appear, with `issues_count: 0`. A project with no labels MUST return `{"data": []}`, never `404`.

#### Scenario: A zero-issue label is present with issues_count 0

- GIVEN a project with a label attached to no issues
- WHEN the endpoint is called
- THEN the response SHALL include that label with `issues_count: 0`

#### Scenario: Ordering is deterministic and dialect-independent

- GIVEN labels seeded with explicit lowercase names, not `LabelFactory`'s default
  `fake()->unique()->word()` (SQLite's BINARY collation would sort `'Bug'` before `'apple'`)
- WHEN the endpoint is called twice
- THEN `data` SHALL list labels in ascending `name` order, identically both times

#### Scenario: An empty catalogue is not a 404

- GIVEN a project with zero labels
- WHEN the endpoint is called
- THEN the response SHALL be `200` with `{"data": []}`

### Requirement: `issues_count` Is An Eager Count, Guarded Against Silent Degradation

`issues_count` MUST come from an eager `withCount('issues')` aggregate, never a per-row
`->issues()->count()`. A dropped `withCount` returns `null` per row without changing the query
count, so query-count equality alone cannot catch it. Every such assertion MUST be paired with a
value assertion on `issues_count` for each label — including the zero-issue label above, the
exact blind spot this endpoint exists to close.

#### Scenario: Query count is flat and every count value is correct

- GIVEN a project with 1 label and, separately, 5, each with a known issue count
- WHEN the endpoint is called for each size with `Model::preventLazyLoading()` enabled
- THEN the query count SHALL be identical across sizes, and every `issues_count` SHALL equal its
  seeded number

### Requirement: Ability Boundary Enforced On The Route

The route MUST sit behind `auth:sanctum` + `abilities:mobile`. No token MUST respond `401` with
header `WWW-Authenticate: Bearer`. A token lacking `mobile` (e.g. `mcp`-only) MUST respond `403`
with the documented Spanish body.

#### Scenario: An mcp-only token is rejected, a missing token challenges

- GIVEN a bearer token with ability `['mcp']` only, and separately no `Authorization` header
- WHEN the endpoint is called
- THEN the first response SHALL be `403` with the Spanish body and the second SHALL be `401`
  with `WWW-Authenticate: Bearer`

### Requirement: Not Found Non-Disclosure Matches `api-issues` And `api-board-sprints`

The endpoint MUST respond `404` with `{"message": "Recurso no encontrado."}`, byte-identical,
for an unknown project key, a non-member project, and an archived (soft-deleted) project —
mirroring the existing convention
(`openspec/changes/api-board-sprints/specs/api-board-sprints/spec.md:89-97`). No response body
MUST contain an `App\Models\Project` substring.

#### Scenario: All three not-found cases are indistinguishable

- GIVEN an unknown project key, a non-member project, and an archived project the requester
  previously belonged to
- WHEN the endpoint is called for each
- THEN all three responses SHALL be `404` with the identical generic body

### Requirement: OpenAPI Contract Coverage

The operation MUST be documented under tag `Labels`, `operationId` `listProjectLabels`,
declaring `security: [{"bearerAuth": []}]`. The `{project}` path parameter MUST be documented as
a plain string, without a route-model-binding type suffix.

#### Scenario: The contract test passes for the new operation

- GIVEN the route is registered and documented
- WHEN `ApiContractTest` runs
- THEN registered routes and documented operations SHALL be set-equal in both directions
