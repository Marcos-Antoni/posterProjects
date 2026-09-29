## MODIFIED Requirements

### Requirement: Key Resolution Fails Closed Uniformly

The `{item}` path segment of `GET /api/v1/objectives/{objective}/items/{item}` and of every
`POST /api/v1/objectives/{objective}/items/{item}/*` operation (`check`, `start`, `two-minute`) MUST be resolved by the objective-scoped key
resolver, never by route-model binding. A key with no dash, a non-numeric or empty numeric suffix, a
prefix not matching `{objective}`'s key, a well-formed key whose number does not exist in this objective,
and a retired item MUST all produce the identical outcome: `404`, indistinguishable from one another.

#### Scenario: A malformed key and a prefix mismatch are equally not found

- **GIVEN** the path segment `nodash` under `/objectives/SALUD/`, and separately `DINERO-1` under the same
  objective
- **WHEN** each is requested
- **THEN** both responses SHALL be `404`, identical to an unknown number under `SALUD-`

### Requirement: OpenAPI Contract Coverage

The item operations MUST be documented in `openapi/v1.json` under an `Items` tag, each declaring
`security: [{"bearerAuth": []}]`, with path templates exactly `/api/v1/objectives/{objective}/items/{item}`,
`/api/v1/objectives/{objective}/items/{item}/check`, `/api/v1/objectives/{objective}/items/{item}/start` and
`/api/v1/objectives/{objective}/items/{item}/two-minute`. The `Issues`, `Board`, `Sprints` and `Labels`
tags and operations MUST be removed from the document.

#### Scenario: The contract test passes for both new operations

- **GIVEN** the item routes are registered and documented and the issue routes are neither
- **WHEN** `ApiContractTest` runs
- **THEN** registered routes and documented operations SHALL be set-equal in both directions

## REMOVED Requirements

### Requirement: List Returns Board-Ordered, Paginated Results
**Reason**: Board order no longer exists; items are listed inside the objective tree.
**Migration**: `GET /api/v1/objectives/{objective}` returns plans with their items (`api-projects`).

### Requirement: Sprint Filter Scopes The List
**Reason**: Sprints are retired (R20).
**Migration**: None; the daily next task comes from `api-daily`.

### Requirement: List And Detail Resource Shapes Are Pinned
**Reason**: Issue fields (type, priority, story points, column, sprint, labels, assignee, comments) are removed.
**Migration**: Replaced by `Item Detail Shape Is Pinned` (ADDED below).

### Requirement: Not Found Non-Disclosure
**Reason**: Membership-based non-disclosure is moot for a single owner; the uniform 404 set is now stated in `Key Resolution Fails Closed Uniformly`.
**Migration**: See the MODIFIED `Key Resolution Fails Closed Uniformly` and `api-projects` `Not Found And Non-Disclosure`.

### Requirement: Archived Project Issues Are Not Found
**Reason**: Archiving is replaced by the objective lifecycle.
**Migration**: Items of a retired objective are not found (covered by `api-projects` `Not Found And Non-Disclosure`); items of a closed objective resolve read-only.

### Requirement: Ability Boundary Enforced On Both Routes
**Reason**: The protected routes are removed.
**Migration**: Replaced by `Item Routes Enforce The Mobile Ability` (ADDED below), same rule.

### Requirement: Serialization Does Not Cause N+1 Queries
**Reason**: The issue serialization it guarded is removed.
**Migration**: Replaced by `Item Serialization Does Not Cause N+1 Queries` (ADDED below).

## ADDED Requirements

### Requirement: Item Detail Shape Is Pinned

`GET /api/v1/objectives/{objective}/items/{item}` MUST expose exactly: `id`, `key`, `kind`, `title`,
`description`, `two_minute_version`, `state`, `target_date`, `plan` (`{id, title}`), `prerequisites` and
`unlocks` (each `[{key, title, state}]`), `completed_at`, `evidence` (milestones only, else `null`),
`updated_at`.

#### Scenario: The detail embeds prerequisites and unlocks

- **GIVEN** an item with one prerequisite and two dependents
- **WHEN** its detail is requested
- **THEN** `prerequisites` SHALL hold one entry and `unlocks` two, each with key, title and state

### Requirement: Checking An Item Through The API

`POST /api/v1/objectives/{objective}/items/{item}/check` MUST mark an available or active task `done` and
respond `200` with the item detail shape plus `unlocked` (items that became available). Checking a
locked item MUST respond `422`. Checking a milestone MUST require a non-empty `evidence` string, else
`422`. Checking an already-done item MUST respond `200` unchanged (idempotent).

#### Scenario: Checking a task reports what it unlocked

- **GIVEN** task A is available and task B depends only on A
- **WHEN** A is checked through the API
- **THEN** the response SHALL be `200` with A `done` and `unlocked` containing B

#### Scenario: Checking a locked item is rejected

- **GIVEN** a locked task
- **WHEN** it is checked through the API
- **THEN** the response SHALL be `422`

### Requirement: Starting An Item Through The API

`POST /api/v1/objectives/{objective}/items/{item}/start` MUST apply the `now-focus` rule exactly as the web
does: the item becomes the single active item, a previously active item becomes `available` (or `locked`)
and its open focus session is closed, and a new focus session is opened. It MUST respond `200` with the
`GET /api/v1/now` shape (`is_active: true`, `focus_started_at` set). Starting the already-active item MUST
respond `200` unchanged, keeping its original `focus_started_at`. Starting a locked, done or retired item
MUST respond `422` in Spanish (retired resolves `404` per the key-resolution rule). It MUST honor
`Idempotency-Key` (see `api-daily`).

#### Scenario: Starting a second item releases the first

- **GIVEN** task A is active and task B is available
- **WHEN** B is started through the API
- **THEN** the response SHALL be `200` with `data.key` B and `is_active: true`
- **AND** A SHALL be `available` with its focus session closed

#### Scenario: Starting a locked item is rejected

- **GIVEN** a locked task
- **WHEN** it is started through the API
- **THEN** the response SHALL be `422`

### Requirement: Replacing The 2-Minute Version Through The API

`POST /api/v1/objectives/{objective}/items/{item}/two-minute` with `{"text": "..."}` (1–255 chars) MUST
replace the item's 2-minute version with source `owner`, keeping the previous version in the item's
2-minute history — the non-AI "estoy trabado" result defined in `now-focus`. It MUST respond `200` with the
item detail shape; empty text MUST respond `422` in Spanish; a done item MUST respond `422`. It MUST honor
`Idempotency-Key` (see `api-daily`).

#### Scenario: The previous 2-minute version is kept in history

- **GIVEN** an item whose 2-minute version is "abrir el editor"
- **WHEN** the API replaces it with "escribir el título"
- **THEN** the item detail SHALL show "escribir el título"
- **AND** the history SHALL contain "abrir el editor"

### Requirement: Item Routes Enforce The Mobile Ability

Item routes MUST sit behind `auth:sanctum` + `abilities:mobile`, with `401` + `WWW-Authenticate: Bearer`
for no token and `403` with the Spanish body for a token lacking `mobile`.

#### Scenario: An mcp-only token is rejected, a missing token challenges

- **GIVEN** a bearer token with ability `['mcp']` only, and separately no `Authorization` header
- **WHEN** an item endpoint is called
- **THEN** the first response SHALL be `403` and the second SHALL be `401` with `WWW-Authenticate: Bearer`

### Requirement: Item Serialization Does Not Cause N+1 Queries

Serializing items (including the key accessor and prerequisite/unlock lists) MUST NOT issue a query count
proportional to the number of items.

#### Scenario: Query count is identical for 1 and 5 items

- **GIVEN** an objective with 1 item, and separately with 5 items each with prerequisites
- **WHEN** its detail is requested with lazy-loading prevention enabled
- **THEN** the total query count SHALL be identical
