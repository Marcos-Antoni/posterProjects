## RENAMED Requirements

- FROM: `### Requirement: Projects Are Keyed And Owned`
- TO: `### Requirement: Objectives Are Keyed And Owned By The Single Owner`

## MODIFIED Requirements

### Requirement: Objectives Are Keyed And Owned By The Single Owner

The former Project entity SHALL become the **Objective**, the root entity and the map of Marcos OS. An
objective MUST have a unique uppercase key (2–10 letters), a title, and an optional identity statement
("I am someone who ..."), and MUST belong to exactly one owner — the single user of the system. It MUST
maintain a next-item-number counter that allocates sequential integers starting at one for its tasks and
milestones, and MUST NEVER return duplicates across repeated or concurrent calls. There MUST be no
membership, sharing, invitation, or assignment of objectives to anyone else (R19: nothing external).

#### Scenario: Issue number allocation is collision-free

- **GIVEN** an objective
- **WHEN** the next item number is allocated many times, including concurrently
- **THEN** every value SHALL be unique and sequential within that objective

#### Scenario: A duplicate key is rejected

- **GIVEN** an existing objective with key `SALUD`
- **WHEN** the owner creates another objective with key `SALUD`
- **THEN** the request MUST be rejected with a Spanish validation message

#### Scenario: Another user's objective is not found

- **GIVEN** an objective owned by a different user record
- **WHEN** the authenticated owner requests it by key
- **THEN** the response MUST be 404, never 403

## REMOVED Requirements

### Requirement: The Sidebar Shows Only The User's Active Projects
**Reason**: Projects become objectives with a richer lifecycle (active / closed / retired) instead of a binary archived flag.
**Migration**: Replaced by `Navigation Shows Only Active Objectives` (ADDED below).

### Requirement: Archiving Is Owner-Only And Reversible
**Reason**: Soft-delete archiving is replaced by closing (finished, with a learning review) and retiring (abandoned, with a reason); owner-only checks are moot in a single-owner system.
**Migration**: Replaced by `Objectives Are Closed Or Retired, Never Deleted` (ADDED below) and the `retirement` capability.

### Requirement: Membership Is A Distinct, Deduplicated Relation
**Reason**: Marcos OS is single-user with nothing external (R19); membership has no meaning.
**Migration**: Ownership is the only relation (`Objectives Are Keyed And Owned By The Single Owner`). The `project_members` table is not recreated after the clean-slate reset.

### Requirement: The Trash Is Owner-Scoped And Reports Contents
**Reason**: The trash implied eventual deletion; nothing is deleted anymore (R13).
**Migration**: Retired objectives are shown in the Retired view (`retirement`).

### Requirement: Force Delete Cascades But Never Touches Users
**Reason**: Nothing is deleted (R13).
**Migration**: Use retirement (`retirement`). The only destructive operation left in the product is the one-time guarded clean-slate reset (`legacy-data-export`).

## ADDED Requirements

### Requirement: The Objective Screen Shows Its Whole Tree

The objective screen MUST show, for one objective: its 5-point plan and control map (see
`control-plan`), its plans in order, each plan's milestones and tasks with their state and 2-minute
version, and the habits hanging from it. Retired descendants MUST be hidden. The screen MUST link to the
per-objective graph (`unlock-graph`).

#### Scenario: Retired items are hidden from the tree

- **GIVEN** an objective whose plan holds one done task, one available task and one retired task
- **WHEN** the owner opens the objective screen
- **THEN** the done and available tasks SHALL be shown
- **AND** the retired task SHALL NOT be shown

### Requirement: Objectives Are Created Only Through An Accepted Negotiation Or A Complete Control Plan

An objective MUST NOT become `active` unless its 5-point plan is complete (see `control-plan`). It MAY be
created directly by the owner with a complete 5-point plan, or MUST otherwise come from an accepted tree
negotiation (see `tree-negotiation`).

#### Scenario: An objective without a metric cannot be activated

- **GIVEN** an objective form with outcome, deadline, risks and contingency but no metric
- **WHEN** the owner submits it for activation
- **THEN** the request MUST be rejected with a Spanish validation message naming the missing point

### Requirement: Navigation Shows Only Active Objectives

The navigation objective list MUST include only the authenticated owner's objectives in the `active`
state, ordered by the owner's manual order, and MUST be empty for guests. Closed and retired objectives
MUST NOT appear in navigation; closed objectives are reachable from the reviews history and retired ones
only from the Retired view.

#### Scenario: Closed and retired objectives leave navigation

- **GIVEN** the owner has one active, one closed and one retired objective
- **WHEN** navigation is built
- **THEN** only the active objective SHALL appear

### Requirement: Objectives Are Closed Or Retired, Never Deleted

An objective MUST have exactly one lifecycle state: `draft` (only inside a tree negotiation, see
`tree-negotiation`), `active`, `closed` (finished, with a learning review — see `reviews`), or `retired`
(abandoned through the retirement protocol — see `retirement`). Closing MUST trigger the habit
continuation decision defined in `reviews`. Retiring MUST follow the retirement protocol. A closed
objective MAY be reopened to `active`; a retired objective MAY be restored through the retirement
protocol. There MUST be no delete or force-delete operation on objectives through the web, API or MCP.

#### Scenario: Closing an objective requires the learning review

- **GIVEN** an active objective
- **WHEN** the owner closes it
- **THEN** the objective SHALL move to `closed` only after the learning review is submitted
- **AND** the owner SHALL be asked which linked habits continue

#### Scenario: No delete route exists

- **GIVEN** any objective
- **WHEN** a delete or force-delete request is attempted on web, API or MCP
- **THEN** no such route or tool SHALL exist
