## REMOVED Requirements

### Requirement: Tools Enforce The Same Authorization As The Web
**Reason**: Its concrete rules (owner-only column and sprint mutations, project membership for issues, comments and labels) target retired domains.
**Migration**: Replaced by `Tools Enforce Web Authorization And The AI Tier` (ADDED below), which keeps the habit isolation rules verbatim.

### Requirement: Scoped Lookups Reject Cross-Project Identifiers
**Reason**: Sprint and board-column identifiers no longer exist.
**Migration**: Replaced by `Scoped Lookups Reject Cross-Objective Identifiers` (ADDED below).

### Requirement: Tools Mirror Web Side Effects Exactly
**Reason**: The listed side effects (create-issue quick-add, delete-sprint, board column cascades) belong to retired tools.
**Migration**: Replaced by `Tools Mirror Web Side Effects Exactly For The Marcos OS Tool Set` (ADDED below).

### Requirement: Read Views Match The Web Views
**Reason**: `board-view`, `backlog-view` and `calendar-view` are retired with their screens.
**Migration**: Replaced by `Read Views Match The Marcos OS Screens` (ADDED below). Skills reading issues (for example `foco-hoy`) MUST switch to `now-view`.

## ADDED Requirements

### Requirement: The Tool Set Matches The Marcos OS Domain

The MCP server MUST expose exactly the Marcos OS tool set and MUST NOT expose any tool of a retired
domain (board, backlog, sprint, label, comment, calendar, project trash or force delete, issue move).
The tool set MUST cover: objectives (list, show), items (show, check, uncheck, shrink-step), capture
(capture, list-inbox), habits (list, show, today, log entry, log 2-minute version), views (now-view,
objective-graph, global-graph, retired-view, progress-summary), proposals (propose-change,
list-proposals, apply-change) and tree negotiation (propose-tree-layer, show-negotiation). Every tool
description MUST state its AI tier (`minor`, `major-proposal`, `major-apply`, or `read`).

#### Scenario: Retired tools are not listed

- **GIVEN** an authenticated MCP client
- **WHEN** it lists the server tools
- **THEN** no tool named `board-view`, `create-sprint`, `create-label`, `create-comment`,
  `calendar-view`, `force-delete-project` or `move-issue` SHALL be listed

### Requirement: Tools Enforce Web Authorization And The AI Tier

Every MCP tool MUST apply the identical authorization and validation rules as its web counterpart, and
additionally the tier rules of `ai-operations`: `minor` tools apply directly; `major-proposal` tools MUST
only create a pending proposal and MUST NOT change the domain; `major-apply` tools MUST refuse unless a
valid permission grant covers the target. Habits keep strict per-user isolation: another user's habit
MUST resolve as not found, and a retired habit MUST reject entries. A tool MUST NOT offer a path around a
web restriction (for example checking a locked item).

#### Scenario: Another user's habit is not found through MCP

- **GIVEN** a habit belonging to a different user
- **WHEN** a tool targets it
- **THEN** the result MUST be not found

#### Scenario: A retired habit rejects entries through MCP

- **GIVEN** a retired habit
- **WHEN** `log-habit-entry` targets it
- **THEN** the call MUST be rejected, matching the web behavior

#### Scenario: A major change without a grant only creates a proposal

- **GIVEN** no active permission grant
- **WHEN** the AI calls `propose-change` to retire a task
- **THEN** a pending proposal SHALL be created
- **AND** the task SHALL remain unchanged

#### Scenario: A locked item cannot be checked through MCP

- **GIVEN** a locked task
- **WHEN** `check-item` targets it
- **THEN** the call MUST be rejected, matching the web behavior

### Requirement: Scoped Lookups Reject Cross-Objective Identifiers

Tools accepting an item key, plan id or dependency MUST resolve it scoped to the target objective and
MUST reject an identifier belonging to another objective, except for dependency tools, which MAY link items
across objectives explicitly (see `unlock-graph`).

#### Scenario: A plan id from another objective is refused

- **GIVEN** a plan belonging to objective `DINERO`
- **WHEN** a tool targeting objective `SALUD` passes that plan id
- **THEN** the call MUST be rejected

### Requirement: Tools Mirror Web Side Effects Exactly For The Marcos OS Tool Set

A tool's side effects MUST match its web counterpart: `check-item` recomputes dependents and returns what
it unlocked; `capture` appends to the inbox without assigning priority or structure; `shrink-step`
replaces only the 2-minute version of the current step (keeping the previous one in history); `apply-change`
performs exactly the proposed operation and writes the same audit entry as the web acceptance.

#### Scenario: Checking through MCP reports unlocked items

- **GIVEN** task B depends only on task A
- **WHEN** `check-item` checks A
- **THEN** the result SHALL list B as unlocked, exactly as the web would

### Requirement: Read Views Match The Marcos OS Screens

`now-view` MUST return the same single active or suggested task the Now screen shows (with its 2-minute
version and what it unlocks); `objective-graph` and `global-graph` MUST return the same nodes and edges as
the web graphs; `retired-view` MUST return the same entries and reasons as the Retired view;
`progress-summary` MUST return the same yesterday/this-week figures as `api-daily`. Every read view MUST
include the absolute web URL of the resource.

#### Scenario: now-view matches the Now screen

- **GIVEN** an active Now task
- **WHEN** `now-view` is called
- **THEN** it SHALL return that task, its 2-minute version and the items it unlocks
