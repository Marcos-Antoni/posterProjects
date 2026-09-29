## MODIFIED Requirements

### Requirement: The Today List Returns A Flattened, Complete Habit Shape

`GET /api/v1/habits/today` MUST return the authenticated owner's active (non-retired) habits scheduled for
the current UTC-6 day, as a bare `{"data": [...]}` collection ordered by name, with no pagination
envelope. Each element MUST expose exactly 17 fields: the 12 legacy fields `id`, `date`, `name`,
`habit_type`, `unit`, `target`, `accumulated_amount`, `completion_percent`, `completed`, `peak_amount`,
`times_per_week`, `week_recorded_days`, plus `two_minute_version` (string), `shown_up` (boolean: completed
or 2-minute version logged today), `streak_current` (integer, tolerant streak), `streak_state`
(`ok` | `at_risk` | `restart`), and `objective_key` (string or `null`). `target` MUST be server-computed
(`max(1, daily_target)` for quantitative, `1` for yes/no). When a habit has no recorded day yet,
`accumulated_amount`, `completion_percent`, `peak_amount` MUST be `0` and `completed` and `shown_up` MUST
be `false` — flattened, never `null` and never omitted. `week_recorded_days` MUST be `null` for every habit
except `TimesPerWeek` recurrence. Retired habits and habits not scheduled for the current UTC-6 day MUST be
absent. The request MUST require a bearer token with the `mobile` ability; the query cost MUST NOT grow
with the number of habits returned. The legacy 12 fields MUST keep their names, types and semantics so an
unmodified posterMobile build keeps working.

#### Scenario: A habit with no entries today is flattened, not null

- **GIVEN** an active habit scheduled for today with no recorded day yet
- **WHEN** `GET /api/v1/habits/today` is requested
- **THEN** that habit's element SHALL report `accumulated_amount: 0`, `completion_percent: 0`,
  `completed: false`, `peak_amount: 0`, `shown_up: false`
- **AND** none of those fields SHALL be `null` or absent

#### Scenario: Archived and unscheduled habits are absent

- **GIVEN** a retired habit and a habit not scheduled for the current UTC-6 day
- **WHEN** `GET /api/v1/habits/today` is requested
- **THEN** neither habit SHALL appear in the response

#### Scenario: A single miss reports at_risk, not a broken streak

- **GIVEN** a daily habit with a 10-day run and a miss yesterday
- **WHEN** `GET /api/v1/habits/today` is requested before any entry today
- **THEN** its element SHALL report `streak_current: 10` and `streak_state: "at_risk"`

### Requirement: One-Tap Increment And Decrement, Shape-Identical To The List

`POST /api/v1/habits/{habit}/increment` MUST add 1 to the current UTC-6 day's accumulated amount for
every habit type — a tap is always +1, never a client-supplied amount. `POST
/api/v1/habits/{habit}/decrement` MUST subtract 1 from the current UTC-6 day's accumulated amount,
without un-completing an already-completed day and without going below zero. Both endpoints MUST
accept an empty request body and MUST respond `200` with a `data` object whose keys and values are
identical in shape to the matching element of the today list — the client MUST be able to splice the
response directly over the row it tapped. Without an `Idempotency-Key` header neither operation is
idempotent (each request is one tap). With an `Idempotency-Key` header both MUST follow `api-daily`'s
`Idempotency-Key Deduplicates Mobile Writes` (24-hour window, replay returns the stored response without a
second tap), so the mobile offline queue can retry safely; this MUST be documented in `openapi/v1.json`.
A replayed request MUST apply to the UTC-6 day of the first request, never to the day of the replay.

Both endpoints anchor the current day through the same expression: `Config::string('habits.timezone')`
(`Etc/GMT+6`), never the application's default timezone. `recordEntry()` (the increment path) and
`decrementToday()` (the decrement path) are deliberately two independent call sites of that
expression rather than a shared helper, so a day-boundary regression at either site is only caught by
a test that exercises both paths within the same minute window — not by construction.

#### Scenario: A tap always adds exactly one

- **GIVEN** a quantitative habit with target 3 and accumulated amount 1
- **WHEN** `POST /api/v1/habits/{habit}/increment` is called with an empty body
- **THEN** the accumulated amount SHALL become 2
- **AND** the response body SHALL match the shape of the corresponding `today` list element

#### Scenario: A decrement at zero is rejected with a validation error

- **GIVEN** a habit day with accumulated amount 0
- **WHEN** `POST /api/v1/habits/{habit}/decrement` is called
- **THEN** the response SHALL be `422` with body `{"errors":{"habit":[...]}}`
- **AND** the accumulated amount SHALL remain 0

#### Scenario: A retried increment with the same key taps once

- **GIVEN** an increment sent with `Idempotency-Key: t1` that returned `200` with accumulated amount 2
- **WHEN** the same increment with `Idempotency-Key: t1` is retried within 24 hours
- **THEN** the accumulated amount SHALL remain 2
- **AND** the response SHALL be the stored first response with `Idempotent-Replayed: true`

## ADDED Requirements

### Requirement: Logging The 2-Minute Version Through The API

`POST /api/v1/habits/{habit}/two-minute` MUST record that the 2-minute version was done today (UTC-6),
MUST be idempotent within the day (and MUST also honor `Idempotency-Key` per `api-daily`), MUST count as shown-up for the tolerant streak and identity votes, and
MUST respond `200` with the today-list element shape. It MUST share the non-disclosing 404 set of the
other habit endpoints and the `mobile` ability boundary, and MUST be documented in `openapi/v1.json`.

#### Scenario: A second 2-minute log on the same day changes nothing

- **GIVEN** the 2-minute version was already logged today
- **WHEN** `POST /api/v1/habits/{habit}/two-minute` is called again
- **THEN** the response SHALL be `200` with an unchanged element
