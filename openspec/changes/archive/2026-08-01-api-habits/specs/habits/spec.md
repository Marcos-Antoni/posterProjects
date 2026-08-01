# Habit Tracking Specification — Delta (`api-habits`)

## MODIFIED Requirements

### Requirement: Entries Accumulate With A Persisted Real Percent

The system MUST accumulate partial entries into the day and persist the real completion percent. A
day's completion is a **high-water mark, not a live recomputation**: `completed` becomes `true` the
moment the day's accumulated amount first reaches the target, and it MUST NOT revert to `false`
afterward for any reason, including a later decrement or a target raised on the habit after the day
was recorded. The persisted `peak_amount` column tracks the highest `accumulated_amount` the day has
ever reached; `completed` is true whenever it was already true OR `peak_amount >= target`. The percent
MAY exceed 100 and tracks `accumulated_amount` (not `peak_amount`), so it MAY drop on a decrement even
while `completed` stays `true`. A yes/no habit MUST complete with a single check-in and MUST NOT take
an amount. A quantitative habit MUST require a positive integer amount, and a string amount MUST be
cast (matching what real form submissions send). Increments MUST be read from the locked row, never
from stale in-memory models.

The system MUST support decrementing a habit day's accumulated amount by one, correcting an
over-tap. A decrement MUST NOT reduce `accumulated_amount` below zero: at zero, a decrement MUST be
rejected rather than silently no-op. A decrement MUST NOT lower `peak_amount`, and MUST NOT be able
to un-complete a day that already reached its target (sticky completion, above). A decrement MUST
NOT write to the `habit_entries` ledger — the ledger is an append-only log of actions, not of
results, so the entry count and the day's final accumulated amount MAY disagree after a decrement.
Decrements MUST be evaluated against the row locked inside the same transaction, so two concurrent
decrements against an accumulated amount of `1` MUST leave `0`, never `-1`.

*Verified by: `tests/Feature/HabitEntryLoggingTest.php` (accumulation, the string-amount cast, the
locked-row read, decrement, the zero floor, concurrent decrements, sticky completion surviving a
`currentStreak()` recomputation, and the `peak_amount <= SUM(habit_entries)` /
`accumulated_amount <= peak_amount` invariants), the migration-backfill test (`peak_amount` clamp
preserves `completed` for legacy rows whose target changed after the day was recorded).*

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

#### Scenario: A decrement corrects an over-tap without erasing history

- GIVEN a quantitative habit with target 5 and accumulated amount 3
- WHEN the accumulated amount is decremented
- THEN the accumulated amount SHALL become 2
- AND the `habit_entries` ledger SHALL be unchanged

#### Scenario: Decrementing a completed day does not un-complete it

- GIVEN a habit day already completed, with accumulated amount at or above target
- WHEN the accumulated amount is decremented
- THEN the day SHALL remain `completed: true`
- AND the current streak SHALL be unaffected

#### Scenario: A decrement is rejected at zero

- GIVEN a habit day with accumulated amount 0
- WHEN a decrement is attempted
- THEN the operation SHALL be rejected
- AND the accumulated amount SHALL remain 0

#### Scenario: Two concurrent decrements from one never go negative

- GIVEN a habit day with accumulated amount 1
- WHEN two decrements are evaluated concurrently against the locked row
- THEN the final accumulated amount SHALL be 0
- AND never -1
