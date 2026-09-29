## Purpose

Lets Marco, and only Marco, send a request from the web to an AI CLI (Claude Code) running on the VPS and
see its answer asynchronously, treating it as remote execution with strict authentication, scoping and limits.

## ADDED Requirements

### Requirement: Only The Authenticated Owner Can Trigger A Bridge Run

A bridge run MUST only be created from an authenticated web session of the owner with a valid CSRF token
and a password confirmation within the last 15 minutes (see `auth`). It MUST NOT be triggerable from
`/api/v1`, from `/mcp`, from any token, or from a GET request.

#### Scenario: An API token cannot trigger the bridge

- **GIVEN** a valid `mobile` or `mcp` bearer token
- **WHEN** any request tries to create a bridge run with it
- **THEN** no bridge run SHALL be created

### Requirement: Runs Use Allowlisted Templates, Never Raw Commands

A bridge run MUST reference one allowlisted template (for example `negotiate-layer`, `shrink-step`,
`weekly-review-draft`, `free-question`) plus the owner's text as data. The web MUST NOT send, and the
bridge MUST NOT execute, any shell command, flag or path supplied by the request. The CLI MUST run with
its tools restricted to the Poster MCP tools of the allowlisted template and without shell or filesystem
write access.

#### Scenario: Shell metacharacters are treated as text

- **GIVEN** the owner's text contains `; rm -rf /`
- **WHEN** the run executes
- **THEN** the text SHALL reach the CLI only as prompt data
- **AND** no shell SHALL interpret it

#### Scenario: An unknown template is rejected

- **GIVEN** a request naming a template not in the allowlist
- **WHEN** it is submitted
- **THEN** it MUST be rejected with `422` and no run SHALL be queued

### Requirement: Requests To The Host Are Signed And Replay-Protected

Every request from the application to the host-side bridge MUST carry an HMAC-SHA256 signature over the
body, a timestamp and a single-use nonce. The host MUST reject a bad signature, a timestamp older than 60
seconds, or a reused nonce. The host service MUST listen only on a local socket or loopback interface,
never on a public interface.

#### Scenario: A replayed request is rejected by the host

- **GIVEN** a previously accepted signed request
- **WHEN** it is sent again with the same nonce
- **THEN** the host MUST reject it

### Requirement: Runs Are Limited In Concurrency, Rate, Time And Size

At most one bridge run MUST execute at a time; further runs MUST queue (max 3 queued, beyond that
rejected). The owner MUST be limited to 20 runs per hour. Each run MUST be killed after 5 minutes and its
output truncated at 64 KB. A global kill switch MUST disable run creation immediately.

#### Scenario: The kill switch disables creation

- **GIVEN** the bridge is disabled by the kill switch
- **WHEN** the owner submits a run
- **THEN** no run SHALL be queued
- **AND** the web SHALL explain that the AI bridge is off

#### Scenario: A long run is terminated

- **GIVEN** a run still executing after 5 minutes
- **WHEN** the time limit is reached
- **THEN** the run SHALL be killed and marked `timeout`

### Requirement: The Bridge UX Is Asynchronous

Creating a run MUST return immediately with a run id in state `queued`. The web MUST show the run's state
(`queued`, `running`, `succeeded`, `failed`, `timeout`, `cancelled`) by polling, MUST let the owner cancel
a queued or running run, and MUST show the final output. Any domain change the CLI makes MUST go through
MCP and therefore through `ai-operations` tiers.

#### Scenario: The owner sees progress without waiting on the request

- **GIVEN** the owner submits a `shrink-step` run
- **WHEN** the request returns
- **THEN** the web SHALL show the run as `queued` and update it until it finishes

### Requirement: Every Run Is Audited

Every run MUST persist: template, owner text, timestamps, final state, exit code, truncated output, and
the ids of any proposals or changes it produced through MCP.

#### Scenario: A failed run keeps its record

- **GIVEN** a run whose CLI exits with a non-zero code
- **WHEN** it finishes
- **THEN** it SHALL be stored as `failed` with its exit code and output
