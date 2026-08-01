# Api-Projects Specification

## Purpose

The read-only `/api/v1/projects` surface for the `posterMobile` Flutter client: list and detail
views over the same projects the web app manages. Writes (create/update/archive/restore/force-delete)
are out of scope — a separate `api-projects-write` change. New capability — no prior spec exists.

## Requirements

### Requirement: Listing Returns The Member's Active Projects

`GET /api/v1/projects` MUST return the requesting user's active (non-archived) projects, scoped by
the same membership relation the web index uses (`User::projects()`, `app/Models/User.php:52-55`;
mirrored by `ProjectController.php:24-28`), ordered by `name` ascending. The response body MUST be
`{"data": [...]}` — one entry per project using the pinned shape (see Resource Shape below). There is
no `links`/`meta`/pagination envelope; the entire result set is always returned in one response.

#### Scenario: The list is ordered and scoped to active membership

- GIVEN the requesting user is a member of two active projects and one archived project
- WHEN `GET /api/v1/projects` is called
- THEN the response SHALL be `200` with `data` containing exactly the two active projects, ordered by `name`

#### Scenario: A user with no projects gets an empty array, not an error

- GIVEN the requesting user is a member of no projects
- WHEN `GET /api/v1/projects` is called
- THEN the response SHALL be `200` with `{"data": []}`

### Requirement: Show Resolves By Key, Active Or Archived

`GET /api/v1/projects/{project}` MUST resolve `{project}` against the `key` column, matching the
web's binding convention (`routes/web.php:65,70,72`, etc.), for any project the requesting user is a
member of — including a soft-deleted (archived) one. A stale link to an archived project MUST still
resolve so the client can render a meaningful state instead of a dead end.

#### Scenario: An active project resolves with archived false

- GIVEN a project the user is a member of, not archived
- WHEN `GET /api/v1/projects/{key}` is called
- THEN the response SHALL be `200` with `archived: false`

#### Scenario: An archived project still resolves, flagged

- GIVEN a project the user is a member of, archived (soft-deleted)
- WHEN `GET /api/v1/projects/{key}` is called
- THEN the response SHALL be `200` with `archived: true`

### Requirement: Not Found And Non-Disclosure

Both endpoints MUST respond `404` with the shared `api/*` body `{"message": "Recurso no
encontrado."}` (see `api-auth`'s JSON Error Response Contract, already implemented) for: a key that
never existed, a key belonging to a project the requesting user is not a member of, and a key whose
project has been permanently force-deleted. These cases MUST be indistinguishable in status code and
body — the response MUST NOT disclose whether an inaccessible key exists.

#### Scenario: An unknown key returns the generic body

- GIVEN no project has the requested key
- WHEN `GET /api/v1/projects/{key}` is called
- THEN the response SHALL be `404` with `{"message": "Recurso no encontrado."}`

#### Scenario: A key belonging to a project the user cannot access is not disclosed

- GIVEN a project exists with the requested key, but the requesting user is not a member
- WHEN `GET /api/v1/projects/{key}` is called
- THEN the response SHALL be `404` with the same generic body — never `403`

### Requirement: Pinned Resource Shape With An Eager-Loaded Aggregate

Every project representation returned by either endpoint MUST expose exactly these fields, and no
others:

| Field | Type | Note |
|---|---|---|
| `id` | integer | stable local key; never used to address the resource |
| `key` | string | the addressable identifier |
| `name` | string | |
| `description` | string\|null | |
| `issues_count` | integer | eager-loaded aggregate |
| `updated_at` | ISO 8601 string | enables client delta sync |
| `archived` | boolean | true only when soft-deleted |

`owner_id`, `next_issue_number`, `deleted_at`, `created_at`, and `url` MUST NOT be present.
`issues_count` MUST be produced by a single eager-loaded aggregate per request (e.g.
`withCount('issues')`) and MUST NOT trigger a query per project.

#### Scenario: Query count does not grow with project count

- GIVEN the requesting user has 1 project, and separately 5 projects
- WHEN `GET /api/v1/projects` is called in each case
- THEN the total query count SHALL be identical in both cases

### Requirement: Ability Boundary Enforced On Both Routes

Both routes MUST sit behind the same `auth:sanctum` + `abilities:mobile` group as the rest of
`/api/v1` (`routes/api.php:10-13`). No token MUST respond `401` with `WWW-Authenticate: Bearer`. A
token lacking the `mobile` ability (e.g. `mcp`-only) MUST respond `403` with the body defined in
`api-auth`'s JSON Error Response Contract.

#### Scenario: An mcp-only token cannot list projects

- GIVEN a bearer token with ability `['mcp']` only
- WHEN `GET /api/v1/projects` is called
- THEN the response SHALL be `403` with the documented Spanish body

### Requirement: OpenAPI Contract Coverage

Both operations MUST be documented in `openapi/v1.json` under a `Projects` tag, each declaring
`security: [{"bearerAuth": []}]`. The show operation's path template MUST be exactly
`/api/v1/projects/{project}` — never `{project:key}` — because `RouteUri::parse` strips the binding
field from the stored URI and `ApiContractTest` compares documented paths against `$route->uri()`
(`tests/Feature/ApiContractTest.php:15-18,52-67`).

#### Scenario: The contract test passes for both new operations

- GIVEN the two routes are registered and documented
- WHEN `ApiContractTest` runs
- THEN registered routes and documented operations SHALL be set-equal in both directions
