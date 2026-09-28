## Purpose

Defines how an objective tree is negotiated: the AI proposes the first layer, Marco edits, they iterate,
and only a mutually accepted tree is persisted into the domain.

## ADDED Requirements

### Requirement: A Negotiation Starts From Marco's Intent

The owner MUST be able to start a negotiation with a free-text intent. The negotiation MUST hold a draft
tree (objective, 5-point plan, control map, plans, items with 2-minute versions, dependencies, habits)
that is stored separately from the domain; nothing in a draft MUST appear in navigation, the Now screen,
graphs, API or MCP domain tools.

#### Scenario: A draft does not leak into the domain

- **GIVEN** an open negotiation with a drafted objective
- **WHEN** the owner opens navigation and the Now screen
- **THEN** the drafted objective and its items SHALL NOT appear

### Requirement: The AI Proposes One Layer At A Time

The AI MUST propose only the next layer of the tree per round (first the objective and its 5-point plan,
then plans, then items and habits for one plan), through `propose-tree-layer` (MCP) or the bridge. Each
round MUST be recorded with its proposal, the owner's edits and a round number.

#### Scenario: The first round proposes only the first layer

- **GIVEN** a new negotiation with an intent
- **WHEN** the AI proposes round 1
- **THEN** the draft SHALL contain the objective and its 5-point plan only, with no plans or items

### Requirement: Marco Edits The Draft Freely

Between rounds the owner MUST be able to edit, reorder, add or remove any draft node. Removing a draft
node MUST NOT require the retirement protocol, because drafts are not part of the domain.

#### Scenario: Removing a draft plan needs no reason

- **GIVEN** a draft with two proposed plans
- **WHEN** the owner removes one
- **THEN** it SHALL be removed from the draft without asking for a reason

### Requirement: The Tree Is Persisted Only On Mutual Acceptance

The draft MUST be persisted into the domain only when the owner marks it accepted AND the latest AI round
has no pending questions (the AI marked it agreed). Persistence MUST be atomic, MUST validate every rule
of the domain (complete 5-point plans, 2-minute versions, acyclic dependencies), and MUST report every
violation back into the draft instead of persisting partially.

#### Scenario: An invalid draft is not persisted partially

- **GIVEN** an accepted draft where one item has no 2-minute version
- **WHEN** persistence runs
- **THEN** nothing SHALL be persisted
- **AND** the draft SHALL show the missing 2-minute version on that item

### Requirement: Negotiations Can Be Paused And Abandoned

A negotiation MUST survive across sessions and MAY be abandoned by the owner, which marks it abandoned
and keeps it in history.

#### Scenario: A paused negotiation resumes at its last round

- **GIVEN** a negotiation at round 3 left yesterday
- **WHEN** the owner reopens it
- **THEN** it SHALL show the round-3 draft with all edits
