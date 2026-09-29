## MODIFIED Requirements

### Requirement: Not Found And Non-Disclosure

Both objective endpoints (`GET /api/v1/objectives` detail and any nested objective route) MUST respond
`404` with the shared `api/*` body `{"message": "Recurso no encontrado."}` (see the `api-auth` spec's
JSON Error Response Contract) for: a key that never existed, a key belonging to an objective owned by
another user record, and a key whose objective is retired (retired objectives are reachable only from the
web Retired view). These cases MUST be indistinguishable in status code and body — the response MUST NOT
disclose whether an inaccessible key exists. The retired `/api/v1/projects` paths MUST NOT be registered.

#### Scenario: An unknown key returns the generic body

- **GIVEN** no objective has the requested key
- **WHEN** `GET /api/v1/objectives/{key}` is called
- **THEN** the response SHALL be `404` with `{"message": "Recurso no encontrado."}`

#### Scenario: A key belonging to a project the user cannot access is not disclosed

- **GIVEN** an objective exists with the requested key but belongs to another user record, and separately
  a retired objective of the owner
- **WHEN** `GET /api/v1/objectives/{key}` is called for each
- **THEN** both responses SHALL be `404` with the same generic body — never `403`

### Requirement: OpenAPI Contract Coverage

Both objective operations MUST be documented in `openapi/v1.json` under an `Objectives` tag, each
declaring `security: [{"bearerAuth": []}]`. The show operation's path template MUST be exactly
`/api/v1/objectives/{objective}` — never `{objective:key}` — because `ApiContractTest` compares documented
paths against `$route->uri()`. The `Projects` tag and its operations MUST be removed from the document.

#### Scenario: The contract test passes for both new operations

- **GIVEN** the two objective routes are registered and documented, and the project routes are neither
- **WHEN** `ApiContractTest` runs
- **THEN** registered routes and documented operations SHALL be set-equal in both directions

## REMOVED Requirements

### Requirement: Listing Returns The Member's Active Projects
**Reason**: Projects become objectives; membership no longer exists (R19, R20).
**Migration**: `GET /api/v1/objectives` (`Listing Returns The Owner's Active Objectives`, ADDED below). posterMobile migrates in `marcos-os-mobile`.

### Requirement: Show Resolves By Key, Active Or Archived
**Reason**: The archived flag is replaced by the objective lifecycle.
**Migration**: `GET /api/v1/objectives/{objective}` (`Show Returns The Objective Tree`, ADDED below).

### Requirement: Pinned Resource Shape With An Eager-Loaded Aggregate
**Reason**: The project shape (`issues_count`, `archived`) no longer describes the domain.
**Migration**: Replaced by `Pinned Objective Shape` (ADDED below).

### Requirement: Ability Boundary Enforced On Both Routes
**Reason**: The routes it protected are removed.
**Migration**: Replaced by `Objective Routes Enforce The Mobile Ability` (ADDED below), same rule.

## ADDED Requirements

### Requirement: Listing Returns The Owner's Active Objectives

`GET /api/v1/objectives` MUST return the owner's `active` objectives in the owner's manual order as
`{"data": [...]}` with no pagination envelope, each using the pinned objective shape. Closed, retired and
draft objectives MUST be absent.

#### Scenario: Only active objectives are listed

- **GIVEN** the owner has two active objectives, one closed and one retired
- **WHEN** `GET /api/v1/objectives` is called
- **THEN** the response SHALL be `200` with exactly the two active objectives in manual order

### Requirement: Show Returns The Objective Tree

`GET /api/v1/objectives/{objective}` MUST resolve `{objective}` by key among the owner's active and
closed objectives and MUST return the pinned objective shape plus `plans`, each with its non-retired
items (`key`, `kind`, `title`, `two_minute_version`, `state`, `prerequisite_keys`) in manual order.

#### Scenario: Retired items are absent from the tree

- **GIVEN** an objective whose plan holds a retired task
- **WHEN** its detail is requested
- **THEN** the retired task SHALL NOT appear in `plans[].items`

### Requirement: Pinned Objective Shape

Every objective representation MUST expose exactly: `id`, `key`, `title`, `identity_statement`,
`state`, `outcome`, `deadline`, `metric` (`{name, target, current}`), `progress` (`{done, total}` over
non-retired items, produced by eager aggregates), `updated_at`. The query count MUST NOT grow with the
number of objectives.

#### Scenario: Query count does not grow with objective count

- **GIVEN** the owner has 1 objective, and separately 5 objectives
- **WHEN** `GET /api/v1/objectives` is called in each case
- **THEN** the total query count SHALL be identical

### Requirement: Objective Routes Enforce The Mobile Ability

Objective routes MUST sit behind `auth:sanctum` + `abilities:mobile`. No token MUST respond `401` with
`WWW-Authenticate: Bearer`; a token lacking `mobile` MUST respond `403` with the documented Spanish body.

#### Scenario: An mcp-only token cannot list objectives

- **GIVEN** a bearer token with ability `['mcp']` only
- **WHEN** `GET /api/v1/objectives` is called
- **THEN** the response SHALL be `403` with the documented Spanish body
