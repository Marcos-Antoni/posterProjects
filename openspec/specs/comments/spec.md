# Comments Specification

> **Reconstructed spec (2026-07-24).** Sources: `tests/Feature/CommentManagementTest.php`,
> `tests/Feature/CommentTest.php` (14 verified scenarios), `routes/web.php`, `app/Models/Comment.php`.

## Requirements

### Requirement: Members Post Non-Empty Comments

A project member MUST be able to post a comment on an issue. An empty comment MUST be rejected.
Non-members MUST be rejected, and guests MUST be redirected to login.

*Verified by: `tests/Feature/CommentManagementTest.php` ("a member can post a comment on an issue",
"posting an empty comment is rejected", "a non-member cannot post a comment", "guests are redirected
to login when posting a comment").*

#### Scenario: An empty comment is refused

- GIVEN a member submits a comment with no body
- WHEN it is validated
- THEN the request MUST be rejected

### Requirement: Only The Author May Edit Or Delete A Comment

Editing and deleting a comment MUST be restricted to its author. Another member MUST be rejected, and
**the project owner MUST also be rejected for comments they did not author**. Authorship is the only
grant; ownership does not override it.

*Verified by: `tests/Feature/CommentManagementTest.php` ("the author can edit their own comment", "the
author can delete their own comment", "another member cannot edit someone else's comment", "another
member cannot delete someone else's comment", "the project owner cannot edit a comment they did not
author", "the project owner cannot delete a comment they did not author").*

#### Scenario: The project owner cannot edit someone else's comment

- GIVEN a comment authored by a member
- WHEN the project owner attempts to edit or delete it
- THEN the request MUST be rejected

#### Scenario: The author edits their own comment

- GIVEN a comment authored by the authenticated member
- WHEN they edit it
- THEN the update SHALL succeed

### Requirement: Comments Are Bound To Their Issue

A comment MUST belong to its issue and its author, and an issue MUST expose its comments. A comment
addressed through a different issue MUST resolve to 404 through scoped route bindings.

*Verified by: `tests/Feature/CommentTest.php` ("a comment belongs to its issue and author", "an issue
exposes its comments"), `tests/Feature/CommentManagementTest.php` ("a comment from another issue 404s
via scoped bindings").*

#### Scenario: A mismatched issue-comment pair is not found

- GIVEN a comment belonging to issue A
- WHEN it is addressed through issue B's URL
- THEN the response status SHALL be 404
