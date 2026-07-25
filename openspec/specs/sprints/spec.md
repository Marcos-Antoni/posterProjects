# Sprints Specification

> **Reconstructed spec (2026-07-24).** Sources: `tests/Feature/SprintControllerTest.php`,
> `tests/Feature/SprintTest.php` (15 verified scenarios), `routes/web.php`, `app/Models/Sprint.php`.

## Requirements

### Requirement: A Sprint Has A Name, A Date Range, And An Optional Goal

A sprint MUST persist a name and a start and end date, stored as date instances. The goal MUST be
optional.

*Verified by: `tests/Feature/SprintTest.php` ("sprint goal is nullable", "sprint stores start and end
dates as carbon instances"), `tests/Feature/SprintControllerTest.php` ("the goal is optional when
creating a sprint").*

#### Scenario: A sprint is created without a goal

- GIVEN the owner submits a sprint with only a name and dates
- WHEN it is created
- THEN the sprint SHALL persist with a null goal

### Requirement: The End Date May Equal But Never Precede The Start Date

The system MUST reject a sprint whose end date is before its start date, on both create and update.
An end date equal to the start date MUST be accepted.

*Verified by: `tests/Feature/SprintControllerTest.php` ("the end date must not be before the start
date", "the end date may equal the start date", "updating a sprint validates the end date is not
before the start date").*

#### Scenario: An inverted range is rejected

- GIVEN a sprint payload whose end date precedes its start date
- WHEN it is validated on create or update
- THEN the request MUST be rejected with a validation error

#### Scenario: A single-day sprint is valid

- GIVEN a sprint whose end date equals its start date
- WHEN it is created
- THEN the request SHALL succeed

### Requirement: Sprint Management Is Owner-Only

Creating, updating, and deleting a sprint MUST be restricted to the project owner. Non-owners MUST be
rejected. Guests MUST be redirected to login for every sprint action. Creating a sprint MUST require a
name, a start date, and an end date.

*Verified by: `tests/Feature/SprintControllerTest.php` ("a non-owner cannot create a sprint", "a
non-owner cannot update a sprint", "creating a sprint requires a name", "creating a sprint requires
start_date and end_date", "guests are redirected to login for every sprint action").*

#### Scenario: A non-owner cannot create a sprint

- GIVEN an authenticated member who does not own the project
- WHEN they attempt to create a sprint
- THEN the request MUST be rejected
