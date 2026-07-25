# Labels Specification

> **Reconstructed spec (2026-07-24).** Sources: `tests/Feature/LabelControllerTest.php`,
> `tests/Feature/IssueLabelManagementTest.php` (24 verified scenarios), `routes/web.php`,
> `app/Models/Label.php`.

## Requirements

### Requirement: Label Names Are Unique Per Project

A label name MUST be unique within its project and MAY be reused in a different project. A duplicate
name in the same project MUST fail with a friendly Spanish validation message, never as a raw SQL
exception.

*Verified by: `tests/Feature/LabelControllerTest.php` ("label name is unique per project", "the same
label name can be reused across different projects", "creating a duplicate label name in the same
project fails with a friendly Spanish error, not a SQL exception").*

#### Scenario: A duplicate name yields a readable error

- GIVEN a project already has a label named `bug`
- WHEN a member creates another label named `bug` in that project
- THEN the response SHALL carry a Spanish validation message
- AND no SQL exception SHALL surface

#### Scenario: Two projects may share a label name

- GIVEN two different projects
- WHEN each creates a label named `bug`
- THEN both SHALL be valid

### Requirement: Attaching A Label Is Idempotent And Project-Scoped

A member MUST be able to attach an existing label to an issue. Attaching the same label twice MUST be
idempotent and MUST NOT error. A label from another project MUST be rejected. A label not attached to
the addressed issue MUST resolve to 404 through scoped route bindings.

*Verified by: `tests/Feature/IssueLabelManagementTest.php` ("attaching the same label twice is
idempotent and does not error", "attaching a label from another project is rejected", "a label not
attached to another issue 404s via scoped bindings").*

#### Scenario: Re-attaching the same label changes nothing

- GIVEN an issue that already carries a label
- WHEN the same label is attached again
- THEN the request SHALL succeed without error
- AND the label SHALL appear exactly once

#### Scenario: A label from another project is refused

- GIVEN a label belonging to a different project
- WHEN a member attaches it to an issue
- THEN the request MUST be rejected

### Requirement: Label Actions Require Membership

Creating, attaching, and detaching labels MUST require project membership. Non-members MUST be
rejected for all three. Guests MUST be redirected to login for every label action.

*Verified by: `tests/Feature/LabelControllerTest.php` ("a non-member cannot create a label"),
`tests/Feature/IssueLabelManagementTest.php` ("a non-member cannot attach a label", "a non-member
cannot detach a label", "guests are redirected to login for the label management actions").*

#### Scenario: A non-member cannot detach a label

- GIVEN an authenticated user who is not a project member
- WHEN they attempt to detach a label from one of its issues
- THEN the request MUST be rejected
