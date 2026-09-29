## Purpose

Defines the no-delete retirement protocol for tasks, milestones, plans, habits and objectives, and the
Retired view used to spot patterns in how Marco approaches things.

## ADDED Requirements

### Requirement: Nothing Is Deleted

The product MUST NOT offer any delete operation for objectives, plans, items, habits, captures or
reviews through web, API or MCP. Removing an element from the active system MUST happen only through
retirement.

#### Scenario: No delete action exists for items

- **GIVEN** any task or milestone
- **WHEN** the owner looks for a delete action on any surface
- **THEN** none SHALL exist, only "retirar"

### Requirement: Retiring Requires A Written Reason And A Content Decision

Retiring any element MUST require a written reason (at least 10 characters) and one content decision:
**move** (its children or dependents go to a chosen target in the tree), **split** (it is replaced by two
or more new smaller elements the owner writes in the same flow), or **archive as-is** (children are
retired together with the same reason). For a leaf item without children or dependents, **archive as-is**
MUST be the default. The retirement MUST be atomic: either the element and its content decision are
applied together, or nothing changes.

#### Scenario: Retiring without a reason is rejected

- **GIVEN** an available task
- **WHEN** the owner retires it with an empty reason
- **THEN** the request MUST be rejected with a Spanish validation message

#### Scenario: Splitting replaces a task with smaller ones

- **GIVEN** a task that feels too big
- **WHEN** the owner retires it with reason "demasiado grande" and the decision "split" into two new tasks
- **THEN** the original task SHALL be retired
- **AND** the two new tasks SHALL be created in the same plan, inheriting its prerequisites and dependents

#### Scenario: Moving a plan's items to another plan

- **GIVEN** a plan with three available tasks
- **WHEN** the owner retires the plan with the decision "move" to another plan of the same objective
- **THEN** the plan SHALL be retired
- **AND** the three tasks SHALL belong to the target plan with their numbers unchanged

### Requirement: Retired Elements Are Hidden

A retired element MUST be absent from the Now screen, navigation, objective trees, graphs, today habit
list, API list responses and MCP list tools. It MUST be visible only in the Retired view and through
`retired-view`.

#### Scenario: A retired task disappears from the graph

- **GIVEN** a retired task
- **WHEN** the per-objective graph is rendered
- **THEN** the task SHALL NOT appear as a node

### Requirement: The Retired View Surfaces Patterns

The Retired view MUST list every retired element with its kind, title, objective, reason, content
decision, age at retirement (days between creation and retirement) and retirement date. It MUST group or
filter by kind, by objective and by retirement month, and MUST show simple counts per reason keyword and
the median age at retirement per kind, so Marco can notice patterns (for example "most tasks are retired
within 3 days as 'demasiado grande'"). It MUST NOT present these figures as a score or failure rate.

#### Scenario: Retirements are grouped by kind

- **GIVEN** two retired tasks and one retired habit
- **WHEN** the Retired view is filtered by kind "tarea"
- **THEN** only the two tasks SHALL be listed with their reasons and ages

### Requirement: A Retired Element Can Be Restored

The owner MUST be able to restore a retired element to its previous non-retired state, provided its
parent is not retired. The retirement record MUST be kept as history. Restoring MUST recompute dependency
states.

#### Scenario: Restoring under a retired parent is refused

- **GIVEN** a retired task whose plan is also retired
- **WHEN** the owner tries to restore the task alone
- **THEN** the request MUST be rejected, asking to restore the plan first
