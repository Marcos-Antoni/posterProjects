# Projects And Trash Specification

> **Reconstructed spec (2026-07-24).** Sources: `tests/Feature/ProjectController*.php`,
> `tests/Feature/ProjectTrashTest.php`, `tests/Feature/ProjectTest.php` (20 verified scenarios),
> `routes/web.php`, `app/Models/Project.php`. Each requirement cites its verifying test file.

## Requirements

### Requirement: Projects Are Keyed And Owned

A project MUST have a unique key and MUST belong to an owner. It MUST maintain a next-issue-number
counter that allocates sequential integers starting at one, and MUST NEVER return duplicates across
repeated calls.

*Verified by: `tests/Feature/ProjectTest.php` ("project key must be unique", "project belongs to an
owner via the users table", "allocate next issue number returns sequential integers starting at one",
"allocate next issue number never returns duplicate numbers across many calls").*

#### Scenario: Issue number allocation is collision-free

- GIVEN a project
- WHEN the next issue number is allocated many times
- THEN every value SHALL be unique and sequential

### Requirement: Membership Is A Distinct, Deduplicated Relation

A user MAY be attached to a project as a member, and MUST NOT be attachable twice. A project MUST
expose all of its members, and a user MUST expose all projects they belong to.

*Verified by: `tests/Feature/ProjectTest.php` ("a user can be attached to a project as a member", "the
same user cannot be attached to a project twice").*

#### Scenario: Double membership is prevented

- GIVEN a user already attached to a project
- WHEN the same user is attached again
- THEN the duplicate SHALL be prevented

### Requirement: The Sidebar Shows Only The User's Active Projects

The sidebar project list MUST include only projects the authenticated user is a member of, excluding
archived ones, and MUST be empty for guests.

*Verified by: `tests/Feature/ProjectTest.php` ("sidebarProjects only includes projects the
authenticated user is a member of, excluding archived ones", "sidebarProjects is empty for guests").*

#### Scenario: Archived projects leave the sidebar

- GIVEN a member of an archived project
- WHEN the sidebar is built
- THEN that project SHALL NOT appear

### Requirement: Archiving Is Owner-Only And Reversible

Only the owner MUST be able to archive a project, and it MUST disappear from their index. Only the
owner MUST be able to restore it, and it MUST reappear. Non-owner members and guests MUST be rejected
for both.

*Verified by: `tests/Feature/ProjectTrashTest.php` ("the owner can archive a project and it disappears
from their index", "a non-owner member cannot archive a project", "the owner can restore an archived
project and it reappears in their index", "a non-owner member cannot restore an archived project", "a
guest cannot archive a project").*

#### Scenario: A non-owner member cannot archive

- GIVEN an authenticated member who does not own the project
- WHEN they attempt to archive it
- THEN the request MUST be rejected

### Requirement: The Trash Is Owner-Scoped And Reports Contents

The trash page MUST list only the authenticated owner's archived projects, MUST report how many issues
and sprints each one holds, and MUST render as a full page with the built Vite assets. Guests MUST NOT
view it.

*Verified by: `tests/Feature/ProjectTrashTest.php` ("the trash page lists only the authenticated
owner's archived projects", "the trash listing includes how many issues and sprints each archived
project has", "a guest cannot view the trash page").*

#### Scenario: The trash reports each project's contents

- GIVEN an owner with archived projects
- WHEN the trash page renders
- THEN each entry SHALL show its issue and sprint counts

### Requirement: Force Delete Cascades But Never Touches Users

Force deleting an archived project MUST be owner-only and MUST cascade to delete all of its child
rows, while NEVER deleting user records.

*Verified by: `tests/Feature/ProjectTrashTest.php` ("a non-owner member cannot force delete a project",
"force deleting an archived project cascades to delete all of its child rows, but never touches users").*

#### Scenario: A cascade stops at the users table

- GIVEN an archived project with issues, sprints, columns, labels, and comments
- WHEN the owner force deletes it
- THEN all child rows SHALL be deleted
- AND no user record SHALL be affected
