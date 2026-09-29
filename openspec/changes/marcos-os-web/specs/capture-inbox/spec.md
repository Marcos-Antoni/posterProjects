## Purpose

A quick-capture inbox that takes any idea in one step without assigning priority or structure, and a
triage flow that later turns captures into items, plans, objectives or habits, or retires them.

## ADDED Requirements

### Requirement: Capture Takes One Field And Assigns Nothing

Capturing MUST require only a non-empty text (max 500 characters) and MUST record its source (`web`,
`mobile`, `ai`) and a UTC timestamp. A capture MUST NOT carry priority, objective, plan or date at capture
time, and MUST NOT appear on the Now screen or in any objective (capture ≠ priority).

#### Scenario: A capture never enters the Now screen

- **GIVEN** a new capture "llamar al dentista"
- **WHEN** the Now screen renders
- **THEN** the capture SHALL NOT be shown or suggested as the Now task

### Requirement: Capture Is Reachable In One Step From Every Web Screen

Every authenticated web screen MUST expose a capture entry (keyboard shortcut and a visible control) that
saves without leaving the current screen.

#### Scenario: Capturing from the graph keeps the graph open

- **GIVEN** the owner is on the global graph
- **WHEN** they capture a text with the shortcut
- **THEN** the capture SHALL be saved
- **AND** the graph SHALL remain open

### Requirement: Triage Converts Or Retires Captures

The inbox screen MUST list untriaged captures oldest first and MUST offer per capture: convert to item
(choosing objective, plan and 2-minute version), convert to habit, convert to a new objective draft, or
retire (with reason, see `retirement`). A converted capture MUST keep a link to what it became and MUST
leave the untriaged list.

#### Scenario: Converting a capture creates an item with a 2-minute version

- **GIVEN** an untriaged capture
- **WHEN** the owner converts it to a task in plan P with a 2-minute version
- **THEN** a new task SHALL exist in P
- **AND** the capture SHALL be marked triaged with a link to that task
