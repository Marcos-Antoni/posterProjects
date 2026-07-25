# Issues Specification

> **Reconstructed spec (2026-07-24).** Sources: `tests/Feature/Issue*.php` (60 verified scenarios),
> `routes/web.php`, and `app/Models/Issue.php`. Each requirement cites its verifying test file.
> Behavior not traceable to a test was omitted rather than guessed.

## Requirements

### Requirement: Issue Type And Priority Are Closed Enums

The system MUST expose exactly four issue types and five priorities, both backed by string values and
cast to enums. Priority values MUST increase from highest to lowest urgency, so sorting ascending
yields highest-urgency first.

*Verified by: `tests/Feature/IssueTest.php` ("issue type exposes exactly the four expected cases",
"issue priority exposes exactly the five expected cases", "sorting priority values ascending yields
highest-to-lowest order").*

#### Scenario: Sorting by priority ascending puts the most urgent first

- GIVEN issues across all five priorities
- WHEN they are sorted by priority value ascending
- THEN the order SHALL run from highest to lowest urgency

### Requirement: Issue Numbers Are Sequential And Scoped Per Project

The system MUST allocate issue numbers sequentially per project, starting at one. A number MUST be
unique within its project and MAY be reused in a different project. Allocation MUST NEVER return
duplicates across concurrent calls. The public key MUST combine the project key and the issue number.

*Verified by: `tests/Feature/IssueTest.php` ("issue number must be unique per project", "the same
issue number can be reused across different projects", "key accessor combines the project key and the
issue number"), `tests/Feature/ProjectTest.php` ("allocate next issue number never returns duplicate
numbers across many calls").*

#### Scenario: Numbers never collide under repeated allocation

- GIVEN a project allocating many issue numbers in sequence
- WHEN allocation is invoked repeatedly
- THEN every returned number SHALL be unique within that project

### Requirement: The Hierarchy Is Exactly One Level Deep

The system MUST reject any parent assignment that would create a second level: a parent that already
has a parent, an issue that already has children, an issue as its own parent, and a parent from
another project. Clearing the parent MUST NOT run hierarchy validation.

*Verified by: `tests/Feature/IssueHierarchyValidationTest.php` (6 scenarios).*

#### Scenario: A sub-task cannot become a parent

- GIVEN an issue that already has a parent
- WHEN another issue tries to set it as its parent
- THEN the assignment MUST be rejected

#### Scenario: An issue with children cannot receive a parent

- GIVEN an issue that already has children
- WHEN a parent is assigned to it
- THEN the assignment MUST be rejected

#### Scenario: Clearing the parent always succeeds

- GIVEN any issue with a parent
- WHEN the parent is cleared
- THEN hierarchy validation SHALL NOT run
- AND the update SHALL succeed

### Requirement: Deep Links Are Refresh-Safe And Fail Closed

The system MUST serve an issue deep link as a full page with the board rendered behind it, so a
browser refresh works. Every malformed or unauthorized key MUST return 404 rather than an error or a
leak: a key with no dash, a non-numeric suffix, a nonexistent number, a key whose prefix does not
match the URL project key, a non-member request, and an archived project.

*Verified by: `tests/Feature/IssueShowTest.php` (9 scenarios).*

#### Scenario: A refresh on a deep link keeps the board behind the issue

- GIVEN a member opens an issue URL directly
- WHEN the page renders
- THEN the issue SHALL be shown with the board behind it

#### Scenario: A malformed key returns 404 instead of erroring

- GIVEN an issue key with no dash or with a non-numeric suffix
- WHEN the deep link is requested
- THEN the response status SHALL be 404

#### Scenario: A key whose prefix mismatches the URL project returns 404

- GIVEN an issue key whose project prefix differs from the project key in the URL
- WHEN the deep link is requested
- THEN the response status SHALL be 404

### Requirement: The Issue Payload Is Complete

The issue payload MUST include labels, assignee, reporter, parent, children, and comments.

*Verified by: `tests/Feature/IssueShowTest.php` ("the issue payload includes labels, assignee,
reporter, parent, children and comments").*

#### Scenario: A single request carries all related data

- GIVEN a member opens an issue
- WHEN the payload is built
- THEN it SHALL include labels, assignee, reporter, parent, children, and comments

### Requirement: Quick-Add Creates A Task With Safe Defaults

Quick-add MUST create an issue as a Task with Medium priority, require a title, allocate the next
sequential number, append it to the bottom of the target column, and assign the sprint from the
currently viewed filter. It MUST reject a board column or sprint belonging to another project, and a
parent that is already a sub-task. Non-members MUST be rejected.

*Verified by: `tests/Feature/IssueControllerTest.php` (10 scenarios).*

#### Scenario: A quick-added issue lands at the bottom of the column

- GIVEN a column with existing issues
- WHEN a member quick-adds an issue to it
- THEN the new issue SHALL be appended below the existing ones

#### Scenario: Quick-add inherits the viewed sprint

- GIVEN the board is filtered to a sprint
- WHEN a member quick-adds an issue
- THEN the issue SHALL be assigned to that sprint

#### Scenario: A column from another project is rejected

- GIVEN a board column belonging to a different project
- WHEN quick-add targets it
- THEN the request MUST be rejected

### Requirement: Dragging Reorders Without Reassigning The Sprint

Moving an issue MUST support both changing column and reordering within a column. A drag MUST NEVER
change the issue's `sprint_id`. Moving out of a column MUST close the gap left behind; moving into a
column MUST insert the issue at the target position between existing issues. Ordering MUST be scoped
to `(board_column_id, sprint_id)`, so another sprint sharing the column is not disturbed. The system
MUST reject a target column from another project, an issue from another project (404), a negative
position, and non-member requests.

*Verified by: `tests/Feature/IssueMoveTest.php` (11 scenarios).*

#### Scenario: A drag never changes the sprint

- GIVEN an issue assigned to a sprint
- WHEN it is dragged to a different column
- THEN its `sprint_id` SHALL remain unchanged

#### Scenario: Leaving a column closes the gap

- GIVEN an issue in the middle of a column
- WHEN it moves to another column
- THEN the remaining issues SHALL close the positional gap

#### Scenario: Reordering respects the column-and-sprint scope

- GIVEN two sprints share a board column
- WHEN issues are reordered within one sprint
- THEN the ordering of the other sprint SHALL NOT be disturbed

#### Scenario: A negative position is rejected

- GIVEN a move request with a negative target position
- WHEN it is validated
- THEN the request MUST be rejected

### Requirement: Members Can Update Issue Fields

An authenticated member MUST be able to update the title, description, type, and priority. Guests
MUST be redirected to login.

*Verified by: `tests/Feature/IssueUpdateTest.php` (14 scenarios).*

#### Scenario: A member updates the type and priority

- GIVEN a member views an issue
- WHEN they submit a new type and priority
- THEN both SHALL be persisted
