# Habit Tracking Specification

> **Reconstructed spec (2026-07-24).** This project ran T-7..T-14 with Engram as the artifact store,
> so `openspec/` never materialized. Requirements below were reconstructed from verified sources only:
> the test suite (`tests/Feature/Habit*.php`, `tests/Feature/Mcp/McpHabitTools*`,
> `tests/Browser/HabitFlowTest.php` — 71 verified scenarios), the registered routes in
> `routes/web.php`, and the Eloquent models. Each requirement cites its verifying test file.
> Behavior that could not be traced to a test was omitted rather than guessed.

Habits are per-user, timezone-anchored trackers with three recurrence modes and streak metrics.

## Requirements

### Requirement: Habit Types And Recurrence Modes

The system MUST support exactly two habit types: yes/no and quantitative. A quantitative habit MAY
carry a unit and a daily target; a yes/no habit MUST NOT. The system MUST support three recurrence
modes: daily, specific weekdays, and times per week. Fields that do not apply to the chosen type or
recurrence MUST be dropped rather than stored.

*Verified by: `tests/Feature/HabitTest.php`, `tests/Feature/HabitControllerTest.php`
("a habit can be created for every type and recurrence combination", "fields that do not apply to the
chosen type or recurrence are dropped").*

#### Scenario: Inapplicable fields are dropped on create

- GIVEN a habit is created as yes/no
- WHEN the payload also carries a unit and a daily target
- THEN those fields SHALL NOT be persisted

#### Scenario: Switching type clears stale fields

- GIVEN a quantitative habit with a unit and a daily target
- WHEN it is updated to yes/no
- THEN the unit and daily target SHALL be cleared

#### Scenario: Weekdays are stored as ISO weekday numbers

- GIVEN a habit recurring on specific weekdays
- WHEN it is persisted
- THEN the weekdays SHALL be cast to an array of ISO weekday numbers

### Requirement: The Habit Day Is Anchored To UTC-6, Not UTC

The system MUST attribute every entry to the UTC-6 calendar day, independently of the UTC date.
Entries falling on either UTC side of the same UTC-6 day MUST accumulate into one habit day. Entries
MUST be stamped with the current UTC timestamp automatically.

*Verified by: `tests/Feature/HabitEntryLoggingTest.php` ("an entry at 23:30 utc-6 lands on the utc-6
day even though it is already the next utc day", "entries on both utc sides of the same utc-6 day
accumulate into one habit day", "entries are stamped with the current utc timestamp automatically").*

#### Scenario: A late-evening entry stays on its UTC-6 day

- GIVEN it is 23:30 in UTC-6, which is already the next calendar day in UTC
- WHEN the user logs an entry
- THEN the entry SHALL be attributed to the UTC-6 day
- AND NOT to the next UTC day

#### Scenario: Entries across the UTC boundary merge into one day

- GIVEN two entries fall on opposite UTC sides of the same UTC-6 day
- WHEN both are logged
- THEN they SHALL accumulate into a single habit day

### Requirement: Entries Accumulate With A Persisted Real Percent

The system MUST accumulate partial entries into the day and persist the real completion percent. A
day MUST be considered completed exactly at the target, and the percent MAY exceed 100. A yes/no
habit MUST complete with a single check-in and MUST NOT take an amount. A quantitative habit MUST
require a positive integer amount, and a string amount MUST be cast (matching what real form
submissions send). Increments MUST be read from the locked row, never from stale in-memory models.

*Verified by: `tests/Feature/HabitEntryLoggingTest.php` (15 scenarios, notably "partial entries
accumulate into the day and the real percent is persisted", "the day is completed exactly at the
target and the percent can exceed 100", "a string amount is cast, matching what real form submissions
send", "increments are read from the locked row, not from stale in-memory models").*

> The string-cast scenario exists because of a real production bug found by the browser E2E during
> T-14: quantitative entries were saving 1 instead of the typed amount.

#### Scenario: Partial entries accumulate and overshoot is preserved

- GIVEN a quantitative habit with a daily target
- WHEN the user logs several partial amounts that exceed the target
- THEN the amounts SHALL accumulate into the day
- AND the persisted percent SHALL exceed 100

#### Scenario: A string amount is cast to a number

- GIVEN a form submission sends the amount as a string
- WHEN the entry is logged
- THEN the amount SHALL be cast to its numeric value
- AND NOT collapsed to 1

#### Scenario: Concurrent increments read the locked row

- GIVEN a habit day row already exists
- WHEN a new entry increments it
- THEN the increment SHALL be computed from the locked database row
- AND NOT from a stale in-memory model

#### Scenario: A quantitative habit rejects a non-positive amount

- GIVEN a quantitative habit
- WHEN an entry is logged without a positive integer amount
- THEN the request MUST be rejected with a validation error

### Requirement: Planned-Versus-Actual Delta

When a habit has a planned time, the system MUST record the planned-versus-actual delta in UTC-6
minutes on the first entry of the day. An entry earlier than planned MUST record a negative delta.
Habits without a planned time MUST keep a null delta.

*Verified by: `tests/Feature/HabitEntryLoggingTest.php` ("the first entry of the day records the
planned-vs-actual delta in utc-6 minutes", "an entry earlier than the planned time records a negative
delta", "habits without a planned time keep a null delta").*

#### Scenario: An early entry records a negative delta

- GIVEN a habit with a planned time
- WHEN the first entry of the day is logged before that time
- THEN the delta SHALL be negative, expressed in UTC-6 minutes

### Requirement: Streaks Are Recurrence-Aware

The system MUST compute the current and best streak according to the habit's recurrence mode. A
habit with no history MUST report zero streaks. A still-pending today MUST NOT break any streak. The
best streak MUST remember the longest historical run even after a break.

- **Daily**: counts consecutive completed days ending today; a missed day breaks it; a partial day
  below the target breaks it.
- **Specific weekdays**: skips unscheduled days without breaking; a missed scheduled day breaks it.
- **Times per week**: accumulates recorded days across fulfilled weeks; breaks ONLY when a week
  closes under quota; empty days in the in-progress week never break it; a recorded but uncompleted
  day still counts toward the weekly quota.

*Verified by: `tests/Feature/HabitMetricsTest.php` (18 scenarios).*

#### Scenario: A pending today does not break a streak

- GIVEN a habit whose today is scheduled but not yet completed
- WHEN the streak is computed
- THEN the current streak SHALL NOT be broken

#### Scenario: A partial day below target breaks the daily streak

- GIVEN a daily quantitative habit
- WHEN a past day was recorded below its target
- THEN the daily streak SHALL break at that day
- AND the best streak SHALL still report the longest prior run

#### Scenario: Unscheduled days do not break a weekday streak

- GIVEN a habit scheduled only on specific weekdays
- WHEN unscheduled days pass with no entries
- THEN the streak SHALL NOT break

#### Scenario: A times-per-week streak breaks only when the week closes under quota

- GIVEN a habit with a weekly quota
- WHEN the current week is still in progress and under quota
- THEN the streak SHALL NOT break
- AND it SHALL break only once that week closes under quota

### Requirement: Completion For A Period Respects The Schedule

The system MUST compute completion for a period as completed days over expected days. For a daily
habit the denominator is calendar days; for specific weekdays it MUST count only scheduled days.

*Verified by: `tests/Feature/HabitMetricsTest.php` ("completion for period on a daily habit is
completed days over calendar days", "completion for period on specific weekdays only expects the
scheduled days").*

#### Scenario: Weekday habits only expect scheduled days

- GIVEN a habit scheduled on specific weekdays
- WHEN completion for a period is computed
- THEN the denominator SHALL count only the scheduled days in that period

### Requirement: Archiving Is Reversible And Blocks New Entries

The system MUST support archiving and reactivating a habit by toggling `archived_at`. An archived
habit MUST reject new entries. There MUST be no destroy route: habits are archived, never deleted.

*Verified by: `tests/Feature/HabitControllerTest.php` ("the owner can archive and reactivate a habit",
"there is no destroy route for habits"), `tests/Feature/HabitEntryLoggingTest.php` ("an archived habit
rejects new entries").*

#### Scenario: An archived habit rejects entries

- GIVEN an archived habit
- WHEN the user tries to log an entry
- THEN the request MUST be rejected

#### Scenario: Habits cannot be destroyed

- GIVEN any habit
- WHEN a destroy route is attempted
- THEN no such route SHALL exist

### Requirement: Habits Are Strictly Per-User

Every habit operation MUST be scoped to the authenticated owner. Acting on another user's habit MUST
return not found, never a permission error that reveals existence. Guests MUST be redirected to login.

*Verified by: `tests/Feature/HabitControllerTest.php`, `tests/Feature/HabitEntryLoggingTest.php`,
`tests/Feature/HabitTodayViewTest.php` ("a user cannot ...another user's habit", "guests are
redirected to login ...").*

#### Scenario: Another user's habit is not found

- GIVEN a habit belonging to a different user
- WHEN the authenticated user tries to read, update, archive, or log against it
- THEN the response MUST be not found

#### Scenario: Guests are redirected to login

- GIVEN an unauthenticated visitor
- WHEN they request any habit page or action
- THEN they SHALL be redirected to login

### Requirement: The Today View Lists Only Active Scheduled Habits

The today view MUST list only active habits scheduled for the current UTC-6 day. The full listing
MUST include archived habits.

*Verified by: `tests/Feature/HabitTodayViewTest.php` ("the today view only lists active habits
scheduled for the current utc-6 day"), `tests/Feature/Mcp/McpHabitTools*` ("today-habits lists only
habits scheduled for the current utc-6 day", "list-habits returns every habit including archived ones").*

#### Scenario: Archived habits are absent from today

- GIVEN an archived habit that would otherwise be scheduled today
- WHEN the today view is rendered
- THEN that habit SHALL NOT appear
