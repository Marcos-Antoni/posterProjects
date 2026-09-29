## RENAMED Requirements

- FROM: `### Requirement: Issue Numbers Are Sequential And Scoped Per Project`
- TO: `### Requirement: Item Numbers Are Sequential And Scoped Per Objective`

## MODIFIED Requirements

### Requirement: Item Numbers Are Sequential And Scoped Per Objective

The former Issue entity SHALL become the **Item**: a checkable Task or Milestone. The system MUST allocate
item numbers sequentially per objective, starting at one. A number MUST be unique within its objective and
MAY be reused in a different objective. Allocation MUST NEVER return duplicates across concurrent calls.
The public key MUST combine the objective key and the item number (for example `SALUD-7`). A number MUST
NEVER be reallocated, even after the item is retired or moved to another plan of the same objective.

#### Scenario: Numbers never collide under repeated allocation

- **GIVEN** an objective allocating many item numbers in sequence
- **WHEN** allocation is invoked repeatedly, including concurrently
- **THEN** every returned number SHALL be unique within that objective

#### Scenario: A retired item's number is not reused

- **GIVEN** an objective whose item `SALUD-3` was retired
- **WHEN** a new item is created in that objective
- **THEN** it SHALL NOT receive number 3

## REMOVED Requirements

### Requirement: Issue Type And Priority Are Closed Enums
**Reason**: Bug/story/epic types and five-level priorities are JIRA concepts; priority is expressed by the weekly priority and the Now task, not by a per-item field.
**Migration**: Replaced by the `kind` of an item (`Items Are Tasks Or Milestones`) and by `reviews` (weekly priority).

### Requirement: The Hierarchy Is Exactly One Level Deep
**Reason**: The parent/child issue hierarchy is replaced by the Objective → Plan → Item tree.
**Migration**: Structure is given by plans (`plans`); ordering between items is given by dependencies (`unlock-graph`).

### Requirement: Deep Links Are Refresh-Safe And Fail Closed
**Reason**: The deep link rendered the board behind the issue; the board is retired.
**Migration**: Replaced by `Item Deep Links Are Refresh-Safe And Fail Closed` (ADDED below), which keeps the same 404 rules.

### Requirement: The Issue Payload Is Complete
**Reason**: Labels, assignee, reporter, parent, children and comments no longer exist.
**Migration**: Replaced by `The Item Payload Is Complete` (ADDED below).

### Requirement: Quick-Add Creates A Task With Safe Defaults
**Reason**: Quick-add targeted board columns and sprints, both retired.
**Migration**: Fast entry goes to the capture inbox (`capture-inbox`); items are added to a plan with `Adding An Item Requires A Title And A 2-Minute Version`.

### Requirement: Dragging Reorders Without Reassigning The Sprint
**Reason**: Column/sprint drag ordering is retired with the board and sprints.
**Migration**: Items keep a manual position within their plan; execution order comes from dependencies (`unlock-graph`).

### Requirement: Members Can Update Issue Fields
**Reason**: Type and priority fields are removed and there are no members.
**Migration**: Replaced by `The Owner Can Edit Item Fields` (ADDED below).

## ADDED Requirements

### Requirement: Items Are Tasks Or Milestones

An item MUST have exactly one `kind`: `task` or `milestone`. Every item MUST belong to exactly one plan,
and through it to exactly one objective. A milestone marks a meaningful point of a plan and MUST be
completed with recorded evidence (see `reviews`); a task is completed with a single check.

#### Scenario: A task is completed with a single check

- **GIVEN** an available task
- **WHEN** the owner checks it
- **THEN** its state SHALL become `done` with a UTC completion timestamp

#### Scenario: A milestone cannot be completed without evidence

- **GIVEN** an available milestone
- **WHEN** the owner tries to complete it with empty evidence
- **THEN** the request MUST be rejected with a Spanish validation message

### Requirement: Adding An Item Requires A Title And A 2-Minute Version

Creating an item MUST require a title and a **2-minute version**: a physical, concrete action that can be
done in about two minutes and that starts the item (Atomic Habits two-minute rule). The item MUST be
appended at the end of its plan's manual order and MUST receive the next item number of its objective.

#### Scenario: An item without a 2-minute version is rejected

- **GIVEN** the owner adds an item with a title only
- **WHEN** the request is validated
- **THEN** it MUST be rejected with a Spanish validation message asking for the 2-minute version

### Requirement: Item State Is Derived From Dependencies And Checks

An item MUST be in exactly one state: `locked` (at least one prerequisite not done), `available` (all
prerequisites done, not started), `active` (it is the current Now task, see `now-focus`), `done`, or
`retired` (see `retirement`). `locked` and `available` MUST be derived from dependencies (see
`unlock-graph`), never set manually. A locked item MUST NOT be checked or made active.

#### Scenario: A locked item cannot be checked

- **GIVEN** task B depends on task A, and A is not done
- **WHEN** the owner tries to check B
- **THEN** the request MUST be rejected
- **AND** B SHALL remain `locked`

#### Scenario: Completing the last prerequisite makes an item available

- **GIVEN** task B depends only on task A
- **WHEN** A is checked
- **THEN** B SHALL become `available`

### Requirement: A Checked Item Can Be Unchecked Without Penalty

The owner MUST be able to uncheck a `done` task (a mis-tap). Unchecking MUST recompute the states of
its dependents; a dependent that was `active` MUST return to `available` or `locked` without losing its
focus history.

#### Scenario: Unchecking relocks a dependent

- **GIVEN** task B depends on task A, A is done and B is available
- **WHEN** the owner unchecks A
- **THEN** B SHALL become `locked`

### Requirement: Item Deep Links Are Refresh-Safe And Fail Closed

The system MUST serve an item deep link (`/objectives/{KEY}/items/{KEY-N}`) as a full page so a browser
refresh works. Every malformed or unauthorized key MUST return 404 rather than an error or a leak: a key
with no dash, a non-numeric suffix, a nonexistent number, a key whose prefix does not match the URL
objective key, another user's objective, and a retired item outside the Retired view.

#### Scenario: A malformed key returns 404 instead of erroring

- **GIVEN** an item key with no dash or with a non-numeric suffix
- **WHEN** the deep link is requested
- **THEN** the response status SHALL be 404

#### Scenario: A key whose prefix mismatches the URL objective returns 404

- **GIVEN** an item key whose objective prefix differs from the objective key in the URL
- **WHEN** the deep link is requested
- **THEN** the response status SHALL be 404

### Requirement: The Item Payload Is Complete

The item payload MUST include: key, kind, title, description, 2-minute version, state, plan
(id and title), objective (key and title), optional target date, prerequisites and dependents (key,
title, state), completion timestamp, and milestone evidence when present.

#### Scenario: A single request carries all related data

- **GIVEN** the owner opens an item with prerequisites and dependents
- **WHEN** the payload is built
- **THEN** it SHALL include the plan, the objective, the prerequisites and the dependents with their states

### Requirement: The Owner Can Edit Item Fields

The authenticated owner MUST be able to update the title, description, 2-minute version, optional target
date, plan (only within the same objective) and manual position of an item. The 2-minute version MUST NOT
be cleared. Guests MUST be redirected to login.

#### Scenario: Moving an item to a plan of another objective is rejected

- **GIVEN** an item of objective `SALUD`
- **WHEN** the owner sets its plan to a plan of objective `DINERO`
- **THEN** the request MUST be rejected
- **AND** cross-objective relocation SHALL only happen through the retirement "move" decision

### Requirement: Target Dates Are Never Rendered As Failure

An item MAY carry an optional target date. A target date in the past MUST NOT change the item's state,
MUST NOT be rendered in red or with failure wording, and MUST NOT generate any notification. It MAY be
shown neutrally (for example "fecha objetivo pasada — ¿la ajustamos?").

#### Scenario: A past target date is shown neutrally

- **GIVEN** an available task whose target date was yesterday
- **WHEN** it is rendered on any screen
- **THEN** it SHALL NOT use the danger color token or overdue wording
