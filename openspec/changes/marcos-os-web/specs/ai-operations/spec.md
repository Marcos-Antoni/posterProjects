## Purpose

Defines what the AI may do on its own (minor actions), what it may only propose (major actions), and how
Marco grants explicit, scoped, expiring permission for the AI to apply major edits — shared by MCP and the bridge.

## ADDED Requirements

### Requirement: AI Operations Are Classified Into Tiers

Every AI-initiated operation MUST be classified as exactly one tier:
- **minor** (applied directly): check or uncheck an item Marco explicitly named, log a habit entry or its
  2-minute version Marco named, capture to the inbox, shrink the current step's 2-minute version, update
  a metric current value Marco stated.
- **major** (proposal by default): create, retire or restore any element; change deadlines or target
  dates; add or remove dependencies; move items between plans; change habit levels or schedules; close an
  objective.
Read operations MUST NOT change anything. An operation not listed MUST be treated as major.

#### Scenario: An unlisted operation is treated as major

- **GIVEN** an AI call to rename a plan (not listed as minor)
- **WHEN** it is processed without a grant
- **THEN** a pending proposal SHALL be created and the plan SHALL remain unchanged

### Requirement: Major Operations Become Proposals

A major operation without a covering grant MUST create a pending proposal holding: the operation, its
exact payload, a human-readable Spanish summary, the AI's rationale, and the source (`mcp` or `bridge`).
The owner MUST be able to accept (apply exactly as proposed), edit-then-accept, or reject each proposal
from the web. Proposals MUST expire after 7 days as `expired` without applying. Accepting a proposal
whose target changed since creation MUST fail safely and ask the AI or the owner to re-propose.

#### Scenario: Accepting a stale proposal fails safely

- **GIVEN** a proposal to retire task A created before A was checked
- **WHEN** the owner accepts it
- **THEN** the proposal SHALL NOT apply
- **AND** the owner SHALL see that the target changed

### Requirement: Permission Grants Let The AI Apply Major Edits

The owner MUST be able to create a permission grant from the web (with recent password confirmation, see
`auth`) scoped to one objective or to everything, with a TTL (default 60 minutes, max 24 hours). While a
valid grant covers the target, the AI MAY apply major operations directly. At most one grant MUST be active
at a time; the owner MUST be able to revoke it immediately. Retirement applied by AI MUST still carry a
written reason and content decision.

#### Scenario: An expired grant is refused

- **GIVEN** a grant that expired one minute ago
- **WHEN** the AI calls `apply-change`
- **THEN** the call MUST be refused
- **AND** the AI SHALL be told to use `propose-change`

#### Scenario: A grant scoped to one objective does not cover another

- **GIVEN** an active grant scoped to objective `SALUD`
- **WHEN** the AI applies a major change to objective `DINERO`
- **THEN** the call MUST be refused

### Requirement: Every AI Change Is Audited

Every AI-applied change (minor, accepted proposal, or grant-applied) MUST write an audit entry with the
source, tier, operation, target, before and after snapshots of the changed fields, and the grant or
proposal id. The owner MUST be able to view the AI activity log on the web.

#### Scenario: A minor AI check is audited

- **GIVEN** the AI checks task A because Marco said so
- **WHEN** the check is applied
- **THEN** an audit entry SHALL record source, tier `minor`, and A's state before and after
