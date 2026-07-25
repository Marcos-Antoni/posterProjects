# Backlog Specification

> **Reconstructed spec (2026-07-24).** Source: `tests/Feature/BacklogControllerTest.php`
> (7 verified scenarios) and `routes/web.php`.

## Requirements

### Requirement: The Backlog Lists Sprints With Their Rollups

The backlog MUST render as a full page with the built Vite assets and the project header. Sprints MUST
be returned with their goal, date range, and story point sum.

*Verified by: `tests/Feature/BacklogControllerTest.php` ("the backlog renders as a full page with the
built Vite assets", "a member can view the backlog with the project header", "sprints are returned with
their goal, date range, and story point sum").*

#### Scenario: Each sprint carries its story point sum

- GIVEN sprints holding issues with story points
- WHEN the backlog renders
- THEN each sprint SHALL report the sum of its issues' story points

### Requirement: Unassigned Issues Belong To The Backlog Section

Issues without a sprint MUST appear in the backlog section and MUST NOT be listed under any sprint.

*Verified by: `tests/Feature/BacklogControllerTest.php` ("issues without a sprint appear in the backlog
section, not under any sprint").*

#### Scenario: A sprintless issue sits in the backlog

- GIVEN an issue with no sprint assigned
- WHEN the backlog renders
- THEN the issue SHALL appear in the backlog section only

### Requirement: Backlog Access Requires Membership And An Active Project

Guests MUST be redirected to login. A non-member MUST NOT view the backlog. An archived project's
backlog MUST return 404.

*Verified by: `tests/Feature/BacklogControllerTest.php` ("guests are redirected to login when visiting
the backlog", "a non-member cannot view the backlog", "an archived project backlog returns 404").*

#### Scenario: An archived project has no backlog

- GIVEN an archived project
- WHEN a member requests its backlog
- THEN the response status SHALL be 404
