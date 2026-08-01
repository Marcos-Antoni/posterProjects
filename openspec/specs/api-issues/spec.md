# Api-Issues Specification

## Purpose

The read-only `/api/v1/projects/{project}/issues` surface for the `posterMobile` Flutter client: a
board-ordered, paginated issue list and a single-issue detail view, both addressed by the human key
(`PROJ-123`). Writes (create/update/move) are out of scope — `api-issues-write`, gated on
`api-board-sprints`.

## Requirements

### Requirement: List Returns Board-Ordered, Paginated Results

`GET /api/v1/projects/{project}/issues` MUST return `{project}`'s issues ordered by board column
position, then issue `position`, then issue `id` as a mandatory tiebreaker — `position` is unique only
within a `(board_column_id, sprint_id)` scope, so two issues sharing a column from different sprints
MAY share the same `position`. The response body MUST be the paginator envelope `{data, links, meta}`
with `per_page` defaulting to 50 and capped at 100 regardless of the requested value. A client MUST be
able to detect the last page from `meta.current_page === meta.last_page` or `links.next === null`,
without inspecting `data`'s length.

#### Scenario: Order is stable across consecutive calls and a page boundary

- GIVEN a project with issues across two board columns, spanning two pages
- WHEN the list is requested twice, and across the page boundary
- THEN the order SHALL be identical every time

#### Scenario: The client detects the last page without counting rows

- GIVEN a project with more issues than fit on one page
- WHEN the last page is requested
- THEN `meta.current_page` SHALL equal `meta.last_page` and `links.next` SHALL be `null`

### Requirement: Sprint Filter Scopes The List

`?sprint=` MUST accept an integer sprint id belonging to `{project}`, or the literal `backlog`
(`sprint_id IS NULL`); absent, it MUST return every issue in the project. A sprint id belonging to a
different project MUST return `404`. A value that is neither `backlog` nor an integer MUST return
`422`.

#### Scenario: backlog returns only unsprinted issues

- GIVEN a project with sprinted and unsprinted issues
- WHEN `?sprint=backlog` is requested
- THEN `data` SHALL contain only issues with `sprint_id` null

#### Scenario: A foreign sprint id is not found, a malformed one is rejected

- GIVEN `?sprint={id}` for a sprint of a different project, and separately `?sprint=abc`
- WHEN each is requested
- THEN the first response SHALL be `404` and the second SHALL be `422`

### Requirement: List And Detail Resource Shapes Are Pinned

The list resource MUST expose exactly: `id`, `key`, `number`, `title`, `type`, `priority`,
`story_points`, `due_date`, `board_column_id`, `sprint_id`, `parent_id`, `position`, `assignee`,
`labels`, `updated_at` — no more, no fewer. The detail resource (`GET .../issues/{issue}`) MUST expose
those 15 plus `description`, `reporter`, `parent`, `children`, `comments`. `type` and `priority` MUST
serialize as their string `->value`. Related data MUST be embedded, never a bare id:

| Field | Shape |
|---|---|
| `assignee` / `reporter` | `{id, name}` or `null` |
| `labels` | `[{id, name}]` |
| `parent` | `{id, key, title}` or `null` |
| `children` | `[{id, key, title, type, board_column_id}]` |
| `comments` (detail only) | `[{id, body, created_at, author:{id,name}}]`, ordered by `created_at` ascending |

#### Scenario: The list shape excludes detail-only fields

- GIVEN an issue with a description and comments
- WHEN it appears in the list response
- THEN `description`, `reporter`, `parent`, `children`, and `comments` SHALL be absent

#### Scenario: The detail shape embeds every relation

- GIVEN an issue with labels, an assignee, a reporter, a parent, children, and comments
- WHEN `GET .../issues/{key}` is called
- THEN all six SHALL be present, embedded rather than referenced by id alone

### Requirement: Key Resolution Fails Closed Uniformly

The `{issue}` path segment MUST be resolved via `Issue::resolveByKey($project, $issueKey)`
(`app/Models/Issue.php:255-274`), never route-model binding. A key with no dash, a non-numeric or
empty numeric suffix, a prefix not matching `{project}`'s key, and a well-formed key whose number does
not exist in this project MUST all produce the identical outcome: `404`, indistinguishable from one
another.

#### Scenario: A malformed key and a prefix mismatch are equally not found

- GIVEN the path segment `nodash` under `/projects/PROJ/`, and separately `OTHER-1` under the same
  project
- WHEN each is requested
- THEN both responses SHALL be `404`, identical to an unknown number under `PROJ-`

### Requirement: Not Found Non-Disclosure

Both endpoints MUST respond `404` with `{"message": "Recurso no encontrado."}` for: a project the
requester is not a member of, an unknown issue key, and a malformed issue key. These MUST be
byte-identical in status and body — never `403` for the first case — and MUST NOT contain an
`App\Models\Issue` or `App\Models\Project` substring.

#### Scenario: A non-member's project issues are not found

- GIVEN a project the requester is not a member of
- WHEN `GET .../issues` is called
- THEN the response SHALL be `404` with the generic body, never `403`

#### Scenario: An unknown key and a malformed key are equally not found

- GIVEN a member's project, an issue key with no matching number, and separately a key with no dash
- WHEN each is requested
- THEN both responses SHALL be `404` with the identical generic body

### Requirement: Archived Project Issues Are Not Found

Unlike `GET /api/v1/projects/{project}`, which resolves an archived (soft-deleted) project with
`archived: true`, both issue endpoints MUST `404` when `{project}` is archived — consistent with
existing web behavior (`openspec/specs/issues/spec.md:69-95`), deliberately inconsistent with
`api-projects`' `show`. This is intended: a client that read the project detail and saw
`archived: true` already has the signal not to request its issues, since `ProjectResource` exposes
that flag; consistency with the web outweighs internal API symmetry here.

#### Scenario: An archived project's issues are not found despite the project resolving

- GIVEN a project the requester belongs to, soft-deleted
- WHEN `GET /api/v1/projects/{key}/issues` is called
- THEN the response SHALL be `404`, even though `GET /api/v1/projects/{key}` would resolve it with
  `archived: true`

### Requirement: Ability Boundary Enforced On Both Routes

Both routes MUST sit behind `auth:sanctum` + `abilities:mobile`. No token MUST respond `401` with
header `WWW-Authenticate: Bearer`. A token lacking the `mobile` ability (e.g. `mcp`-only) MUST respond
`403` with the documented Spanish body.

#### Scenario: An mcp-only token is rejected, a missing token challenges

- GIVEN a bearer token with ability `['mcp']` only, and separately no `Authorization` header
- WHEN either endpoint is called
- THEN the first response SHALL be `403` with the Spanish body and the second SHALL be `401` with
  `WWW-Authenticate: Bearer`

### Requirement: Serialization Does Not Cause N+1 Queries

Because the `key` accessor reads `$this->project->key` (`app/Models/Issue.php:87-92`), serializing any
issue touches the `project` relation. Neither response MUST issue a query count proportional to the
number of issues, children, or the parent serialized — this MUST include the `key`-accessor path
specifically, not only the explicitly eager-loaded relations.

#### Scenario: Query count is identical for 1 and 5 issues

- GIVEN a project with 1 issue, and separately with 5 issues, each with a label and an assignee
- WHEN `GET .../issues` is called in each case with lazy-loading prevention enabled
- THEN the total query count SHALL be identical, and each issue's `key`, `labels`, and `assignee`
  SHALL be correctly populated

### Requirement: OpenAPI Contract Coverage

Both operations MUST be documented in `openapi/v1.json` under an `Issues` tag, each declaring
`security: [{"bearerAuth": []}]`. The show operation's path template MUST be exactly
`/api/v1/projects/{project}/issues/{issue}` — never `{issue:key}`, since no route-model binding exists
on this parameter — because `ApiContractTest` compares documented paths against `$route->uri()` in
both directions.

#### Scenario: The contract test passes for both new operations

- GIVEN the two routes are registered and documented
- WHEN `ApiContractTest` runs
- THEN registered routes and documented operations SHALL be set-equal in both directions
