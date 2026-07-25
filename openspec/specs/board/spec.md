# Board And Columns Specification

> **Reconstructed spec (2026-07-24).** Sources: `tests/Feature/Board*.php` (31 verified scenarios),
> `routes/web.php`, and `app/Models/BoardColumn.php`. Each requirement cites its verifying test file.

## Requirements

### Requirement: A New Project Materializes Default Columns

Creating a project with default columns MUST materialize `To Do`, `In Progress`, and `Done`, and MUST
auto-attach the owner as a member.

*Verified by: `tests/Feature/BoardColumnTest.php` ("creating a project with default columns
materializes To Do, In Progress and Done", "creating a project with default columns auto-attaches the
owner as a member").*

#### Scenario: Default columns and owner membership are created together

- GIVEN a new project is created with default columns
- WHEN creation completes
- THEN the columns `To Do`, `In Progress`, and `Done` SHALL exist in that order
- AND the owner SHALL be attached as a member

### Requirement: Column Position Is Unique Per Project

Board column position MUST be unique within a project and MAY repeat across projects. A project MUST
expose its columns ordered by position.

*Verified by: `tests/Feature/BoardColumnTest.php` ("board column position is unique per project", "the
same position can be reused across different projects").*

#### Scenario: Two projects may share a position number

- GIVEN two different projects
- WHEN each has a column at position 1
- THEN both SHALL be valid

### Requirement: The Board Defaults To The Active Sprint

The board MUST filter issues by an explicit sprint query parameter, including an explicit backlog
selection. With no parameter, it MUST default to the sprint whose date range contains today, and to
the backlog when no sprint is active.

*Verified by: `tests/Feature/BoardControllerTest.php` ("the board filters issues by an explicit sprint
query param, including backlog", "the board defaults to the sprint whose date range contains today",
"the board defaults to the backlog when no sprint is active").*

#### Scenario: Today's sprint is selected automatically

- GIVEN a sprint whose date range contains today
- WHEN a member opens the board without a sprint parameter
- THEN that sprint SHALL be selected

#### Scenario: With no active sprint the board shows the backlog

- GIVEN no sprint's date range contains today
- WHEN a member opens the board without a sprint parameter
- THEN the backlog SHALL be shown

### Requirement: The Board Renders As A Full Page With Ordered Content

The board MUST render as a full page with the built Vite assets. Columns MUST be ordered by position
and issues within a column by position. Each issue MUST carry type, priority, points, labels, and
assignee.

*Verified by: `tests/Feature/BoardControllerTest.php` ("the board renders as a full page with the
built Vite assets", "board columns include issues with type, priority, points, labels and assignee",
"issues within a column are ordered by position").*

#### Scenario: Issues appear ordered within each column

- GIVEN a column with several issues
- WHEN the board renders
- THEN the issues SHALL appear ordered by position

### Requirement: Only The Owner Manages Columns

Adding, renaming, reordering, and deleting a column MUST be restricted to the project owner. A
non-owner member MUST be rejected for all four. Guests MUST be redirected to login for every column
action. Adding a column MUST require a name. Reordering MUST NOT violate the unique position
constraint.

*Verified by: `tests/Feature/BoardColumnControllerTest.php` (15 scenarios).*

#### Scenario: A non-owner cannot modify the board structure

- GIVEN an authenticated member who is not the owner
- WHEN they try to add, rename, reorder, or delete a column
- THEN each request MUST be rejected

#### Scenario: Reordering preserves the unique position constraint

- GIVEN a board with columns at consecutive positions
- WHEN the owner reorders them
- THEN the operation SHALL complete without violating position uniqueness

### Requirement: Deleting A Column Requires A Destination When It Holds Issues

Deleting an empty column MUST succeed without a destination. Deleting a column that holds issues MUST
require a destination column, MUST move those issues to it, and then MUST delete the column. The
destination MUST belong to the same project and MUST NOT be the column being deleted.

*Verified by: `tests/Feature/BoardColumnControllerTest.php` ("the owner can delete an empty column
without a destination", "deleting a column with issues requires a destination column", "deleting a
column with issues moves them to the destination column and deletes the column", "the destination
column must belong to the same project", "the destination column cannot be the column being deleted").*

#### Scenario: Issues are relocated before the column disappears

- GIVEN a column holding issues
- WHEN the owner deletes it naming a valid destination column
- THEN the issues SHALL move to the destination
- AND the original column SHALL be deleted

#### Scenario: A column cannot be its own destination

- GIVEN a column holding issues
- WHEN the deletion names that same column as destination
- THEN the request MUST be rejected

### Requirement: Cross-Project And Archived Access Fails Closed

A column from another project MUST resolve to 404 for update, reorder, and destroy. A non-member MUST
NOT view the board. An archived project's board MUST return 404.

*Verified by: `tests/Feature/BoardColumnControllerTest.php` ("a column from another project resolves
to a 404 for update, reorder, and destroy"), `tests/Feature/BoardControllerTest.php` ("a non-member
cannot view the board", "an archived project board returns 404").*

#### Scenario: An archived project's board is gone

- GIVEN an archived project
- WHEN a member requests its board
- THEN the response status SHALL be 404
