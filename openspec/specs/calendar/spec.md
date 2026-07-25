# Calendar Specification

> **Reconstructed spec (2026-07-24).** Source: `tests/Feature/CalendarControllerTest.php`
> (10 verified scenarios) and `routes/web.php`.

Shows issues by due date across the projects the user belongs to.

## Requirements

### Requirement: The Calendar Shows Only Issues The User May See

The calendar MUST show issues due in the requested month from the authenticated user's own projects,
including the project key so links can be built. It MUST exclude issues without a due date, issues from
projects the user is not a member of, and issues from an archived (soft-deleted) project — **even for a
former member**. Guests MUST be redirected to login.

*Verified by: `tests/Feature/CalendarControllerTest.php` ("a member sees issues due this month from
their own projects, with the project key to build links", "issues without a due date are excluded",
"issues from a project the user is not a member of are excluded", "issues from an archived
(soft-deleted) project are excluded even for a former member").*

#### Scenario: An archived project's issues disappear for a former member

- GIVEN a user who was a member of a now-archived project
- WHEN they open the calendar
- THEN issues from that project SHALL NOT appear

#### Scenario: Issues without a due date are not shown

- GIVEN issues with no due date
- WHEN the calendar renders
- THEN those issues SHALL be excluded

### Requirement: Month Selection Defaults Safely

The calendar MUST return only issues due within the requested month, excluding the months before and
after. With no `month` parameter it MUST default to the current month. An explicit `?month=` MUST
navigate to that month. A malformed `month` parameter MUST fall back to the current month instead of
erroring.

*Verified by: `tests/Feature/CalendarControllerTest.php` ("only issues due within the requested month
are returned, excluding the month before and after", "the response defaults to the current month when
no month param is given", "an explicit ?month= query param navigates to a different month", "a
malformed month query param falls back to the current month instead of erroring").*

#### Scenario: A malformed month parameter does not error

- GIVEN a request with an unparseable `month` value
- WHEN the calendar renders
- THEN it SHALL fall back to the current month
- AND SHALL NOT raise an error

#### Scenario: Adjacent months are excluded

- GIVEN issues due in the previous, requested, and next month
- WHEN the requested month is rendered
- THEN only that month's issues SHALL be returned
