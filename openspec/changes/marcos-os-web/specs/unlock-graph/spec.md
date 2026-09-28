## Purpose

Models "completing A unlocks B" dependencies between tasks and milestones, keeps them acyclic, and
renders a per-objective graph and a global graph across objectives.

## ADDED Requirements

### Requirement: Dependencies Link Items As Prerequisites

A dependency MUST link a prerequisite item to a dependent item ("completing A unlocks B"). An item MAY
have any number of prerequisites and dependents. A dependency MAY cross objectives. Self-dependencies and
duplicate edges MUST be rejected. Creating or removing a dependency MUST immediately recompute the
`locked`/`available` state of the dependent (see `issues`).

#### Scenario: A duplicate edge is rejected

- **GIVEN** an existing dependency A → B
- **WHEN** A → B is created again
- **THEN** the request MUST be rejected

#### Scenario: A cross-objective dependency locks the dependent

- **GIVEN** task A in objective `DINERO` not done, and task B in objective `SALUD`
- **WHEN** the owner adds the dependency A → B
- **THEN** B SHALL become `locked`

### Requirement: The Dependency Graph Is Acyclic

The system MUST reject any dependency that would create a cycle, directly or transitively, and MUST
report the cycle path in the Spanish validation message.

#### Scenario: A transitive cycle is rejected

- **GIVEN** dependencies A → B and B → C
- **WHEN** the owner adds C → A
- **THEN** the request MUST be rejected with a message naming A, B and C

### Requirement: Retired Items Do Not Block

A retired prerequisite MUST NOT keep its dependents locked: when an item is retired, its outgoing edges
MUST be resolved by the retirement content decision (see `retirement`), and any dependent left only with
retired prerequisites MUST become `available`.

#### Scenario: Retiring the only prerequisite unlocks the dependent

- **GIVEN** task B depends only on task A
- **WHEN** A is retired with the decision "archive as-is"
- **THEN** B SHALL become `available`

### Requirement: The Per-Objective Graph Shows One Objective's Items

The per-objective graph MUST show every non-retired item of one objective as a node with its state and
kind, every dependency between them as an edge, and cross-objective prerequisites or dependents as
distinct external stub nodes linking to their objective. Nodes MUST be grouped by plan.

#### Scenario: An external prerequisite appears as a stub

- **GIVEN** task B in `SALUD` depends on task A in `DINERO`
- **WHEN** the `SALUD` graph is rendered
- **THEN** A SHALL appear as an external stub node linked to `DINERO`

### Requirement: The Global Graph Shows All Active Objectives

The global graph MUST show every active objective as a cluster, the non-retired items within each, and
every cross-objective edge. It MUST allow collapsing an objective to a single node and MUST highlight the
current Now task and the items it unlocks.

#### Scenario: Cross-objective edges are visible globally

- **GIVEN** a dependency from an item of `DINERO` to an item of `SALUD`
- **WHEN** the global graph is rendered
- **THEN** the edge SHALL be drawn between the two clusters

### Requirement: Completing An Item Plays An Unlock Animation

When an item is completed from any web screen, the graph view (or the Now screen's mini-graph) MUST play
an unlock animation on the completed node and on every node that became `available`. The animation MUST
respect the reduced-motion preference by degrading to a static highlight.

#### Scenario: Reduced motion shows a static highlight

- **GIVEN** the browser reports `prefers-reduced-motion: reduce`
- **WHEN** an item that unlocks another is completed
- **THEN** the unlocked node SHALL be highlighted without animation
