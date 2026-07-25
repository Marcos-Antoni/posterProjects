# MCP Server Specification

> **Reconstructed spec (2026-07-24).** Sources: `tests/Feature/Mcp/*` (66 verified scenarios),
> `tests/Browser/McpTokenFlowTest.php`, `routes/web.php` (`settings/mcp-token`), and the documented
> flow at `llm-tech-wiki/projects/personal/posterProjects/flows/mcp-server.md`. Delivered by T-14
> (commits `5c08036..186f5e9`), verified in production.

Exposes the application to Claude as an MCP server over HTTP, with token auth and full parity with the
web authorization rules.

## Requirements

### Requirement: Access Requires A Valid Bearer Token

The MCP endpoint MUST reject any request without a token using a bearer challenge. An authenticated
client MUST be able to initialize and list the server tools. A revoked token MUST stop authenticating
immediately, with no grace period.

*Verified by: `tests/Feature/Mcp/*` ("an authenticated client can initialize against the mcp server",
"an authenticated client can list the server tools", "a request without a token is rejected with a
bearer challenge", "a revoked token stops authenticating immediately").*

#### Scenario: An unauthenticated request gets a bearer challenge

- GIVEN a client calls the MCP endpoint with no token
- WHEN the request is handled
- THEN the response SHALL be a 401 bearer challenge

#### Scenario: Revocation takes effect immediately

- GIVEN a client holding a previously valid token
- WHEN that token is revoked
- THEN the very next request MUST fail to authenticate

### Requirement: Exactly One Token Exists, And The Plain Text Is Shown Once

The token settings page MUST report that no token exists before one is generated. Generating a token
MUST store exactly one personal access token and MUST flash the plain text exactly once. Regenerating
MUST kill the previous token immediately. **The plain token MUST NEVER leak into the persistent page
prop.** Guests MUST be redirected to login.

*Verified by: `tests/Feature/Mcp/*` ("the page reports no token before one is generated", "generating a
token stores a single pat and flashes the plain text once", "regenerating kills the previous token
immediately", "the plain token never leaks into the persistent token prop"),
`tests/Browser/McpTokenFlowTest.php`.*

#### Scenario: The plain token never persists in page state

- GIVEN a token has just been generated
- WHEN the page renders
- THEN the plain text SHALL appear only in the one-time flash
- AND the persistent token prop SHALL NOT contain it

#### Scenario: Regenerating invalidates the old token at once

- GIVEN an existing token
- WHEN the user regenerates it
- THEN the previous token SHALL stop working immediately
- AND exactly one token SHALL remain stored

### Requirement: Tools Enforce The Same Authorization As The Web

Every MCP tool MUST apply the identical authorization rules as its web counterpart. Specifically:
owner-only for board column and sprint mutations; project membership for issue, comment, label, and
view access; strict per-user isolation for habits, where another user's habit MUST resolve as not
found. A tool MUST NOT offer a path around a web restriction.

*Verified by: `tests/Feature/Mcp/McpBoardColumnTools*` ("... is denied for a non-owner member" ×4),
`McpSprintTools*` ("... is denied for a member who is not the owner" ×2), `McpIssueTools*`
("create-issue is denied for a non-member"), `McpHabitTools*` ("... is not found for another user's
habit" ×3, "log-habit-entry rejects an archived habit, matching the web behavior"), `McpProjectTools*`
("board-view is denied for a non-member", "backlog-view is denied for a non-member").*

#### Scenario: A non-owner is denied a column mutation through MCP

- GIVEN an authenticated member who does not own the project
- WHEN they call a board column create, update, reorder, or delete tool
- THEN the call MUST be denied, exactly as the web route would

#### Scenario: Another user's habit is not found through MCP

- GIVEN a habit belonging to a different user
- WHEN a tool targets it
- THEN the result MUST be not found

#### Scenario: An archived habit rejects entries through MCP

- GIVEN an archived habit
- WHEN `log-habit-entry` targets it
- THEN the call MUST be rejected, matching the web behavior

### Requirement: Scoped Lookups Reject Cross-Project Identifiers

Tools accepting an identifier MUST resolve it scoped to the target project and MUST reject an
identifier belonging to another project.

*Verified by: `tests/Feature/Mcp/McpSprintTools*` ("update-sprint rejects a sprint_id belonging to
another project (scoped lookup)"), `McpBoardColumnTools*` ("update-board-column rejects a column
belonging to another project", "delete-board-column rejects a column belonging to another project").*

#### Scenario: A sprint id from another project is refused

- GIVEN a sprint belonging to a different project
- WHEN `update-sprint` is called with its id
- THEN the call MUST be rejected

### Requirement: Tools Mirror Web Side Effects Exactly

A tool's side effects MUST match its web counterpart, including ordering and cascade behavior:
`create-issue` quick-adds a Task at the bottom of the column; `update-issue` changes only the fields
sent; `delete-sprint` returns its issues to the backlog; `delete-board-column` reindexes remaining
positions and, when the column holds issues, requires a destination; `create-board-column` appends at
the end; `reorder-board-column` re-sequences every column.

*Verified by: `tests/Feature/Mcp/McpIssueTools*`, `McpSprintTools*`, `McpBoardColumnTools*`
(30 scenarios).*

#### Scenario: Deleting a sprint returns its issues to the backlog

- GIVEN a sprint holding issues
- WHEN `delete-sprint` is called by the owner
- THEN the sprint SHALL be removed
- AND its issues SHALL return to the backlog

#### Scenario: A partial update touches only the fields sent

- GIVEN an issue with several populated fields
- WHEN `update-issue` sends a subset of them
- THEN only those fields SHALL change

#### Scenario: Deleting a column with issues requires a destination

- GIVEN a column holding issues
- WHEN `delete-board-column` is called without a destination
- THEN the call MUST fail validation

### Requirement: Read Views Match The Web Views

`board-view` MUST show the project columns with their issues, `backlog-view` MUST show sprints plus the
unassigned backlog, and `calendar-view` MUST show only issues with a due date from the user's own
projects.

*Verified by: `tests/Feature/Mcp/McpProjectTools*` ("board-view shows the project columns with their
issues", "backlog-view shows sprints and the unassigned backlog", "calendar-view only shows issues with
a due date from the user's own projects").*

#### Scenario: calendar-view excludes issues without a due date

- GIVEN issues with and without due dates
- WHEN `calendar-view` is called
- THEN only issues with a due date SHALL be returned
