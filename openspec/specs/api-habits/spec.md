# API Habits Specification

## Purpose

The `/api/v1/habits` surface the `posterMobile` Flutter client uses to read and log habits away from
a desk: the today-list resource shape, the one-tap increment/decrement pair, the sticky-completion
contract as it appears on the wire, and its 404/422 non-disclosure set.

## Requirements

### Requirement: The Today List Returns A Flattened, Complete Habit Shape

`GET /api/v1/habits/today` MUST return the authenticated owner's active habits scheduled for the
current UTC-6 day, as a bare `{"data": [...]}` collection ordered by name, with no pagination
envelope. Each element MUST expose exactly 12 fields: `id`, `date`, `name`, `habit_type`, `unit`,
`target`, `accumulated_amount`, `completion_percent`, `completed`, `peak_amount`, `times_per_week`,
`week_recorded_days`. `target` MUST be server-computed (`max(1, daily_target)` for quantitative,
`1` for yes/no) rather than exposing the raw `daily_target`. When a habit has no recorded day yet,
`accumulated_amount`, `completion_percent`, `peak_amount` MUST be `0` and `completed` MUST be
`false` — flattened, never `null` and never omitted. `week_recorded_days` MUST be `null` for every
habit except `TimesPerWeek` recurrence. Archived habits and habits not scheduled for the current
UTC-6 day MUST be absent from the list. The request MUST require a bearer token with the `mobile`
ability; the query cost MUST NOT grow with the number of habits returned.

*Verified by: `tests/Feature/ApiHabitsTest.php` (exact-shape assertions via `toBe()`, ordering,
no-day-row flattening, archived/unscheduled exclusion, `week_recorded_days` null except
`TimesPerWeek`, and a query-count assertion under `Model::preventLazyLoading()` at 1 and 5 habits).*

#### Scenario: A habit with no entries today is flattened, not null

- GIVEN an active habit scheduled for today with no recorded day yet
- WHEN `GET /api/v1/habits/today` is requested
- THEN that habit's element SHALL report `accumulated_amount: 0`, `completion_percent: 0`,
  `completed: false`, `peak_amount: 0`
- AND none of those fields SHALL be `null` or absent

#### Scenario: Archived and unscheduled habits are absent

- GIVEN an archived habit and a habit not scheduled for the current UTC-6 day
- WHEN `GET /api/v1/habits/today` is requested
- THEN neither habit SHALL appear in the response

### Requirement: One-Tap Increment And Decrement, Shape-Identical To The List

`POST /api/v1/habits/{habit}/increment` MUST add 1 to the current UTC-6 day's accumulated amount for
every habit type — a tap is always +1, never a client-supplied amount. `POST
/api/v1/habits/{habit}/decrement` MUST subtract 1 from the current UTC-6 day's accumulated amount,
without un-completing an already-completed day and without going below zero. Both endpoints MUST
accept an empty request body and MUST respond `200` with a `data` object whose keys and values are
identical in shape to the matching element of the today list — the client MUST be able to splice the
response directly over the row it tapped. Neither operation is idempotent and neither MUST be
documented as such.

Both endpoints anchor the current day through the same expression: `Config::string('habits.timezone')`
(`Etc/GMT+6`), never the application's default timezone. `recordEntry()` (the increment path) and
`decrementToday()` (the decrement path) are deliberately two independent call sites of that
expression rather than a shared helper, so a day-boundary regression at either site is only caught by
a test that exercises both paths within the same minute window — not by construction.

*Verified by: `tests/Feature/ApiHabitsTest.php` (yes/no +1, quantitative ×N taps, decrement, sticky
completion, the shape-identity assertion between an `increment` response and the matching `today`
list element, and the UTC-6 23:30/23:31 boundary test that increments then decrements one minute
apart and asserts both requests land on the same `habit_days` row — the test that binds
`recordEntry()`'s and `decrementToday()`'s two independent day-anchor call sites together).*

#### Scenario: A tap always adds exactly one

- GIVEN a quantitative habit with target 3 and accumulated amount 1
- WHEN `POST /api/v1/habits/{habit}/increment` is called with an empty body
- THEN the accumulated amount SHALL become 2
- AND the response body SHALL match the shape of the corresponding `today` list element

#### Scenario: A decrement at zero is rejected with a validation error

- GIVEN a habit day with accumulated amount 0
- WHEN `POST /api/v1/habits/{habit}/decrement` is called
- THEN the response SHALL be `422` with body `{"errors":{"habit":[...]}}`
- AND the accumulated amount SHALL remain 0

### Requirement: Unknown, Foreign, And Archived Habits Are Indistinguishable

Every `/api/v1/habits/*` endpoint MUST resolve `{habit}` scoped to the authenticated owner via a
single non-disclosing lookup. An unknown habit id, a habit belonging to another user, and an
archived habit MUST all produce the byte-identical `404 {"message":"Recurso no encontrado."}` body,
with no `App\Models\Habit` substring anywhere in the response. A request without a valid bearer token
MUST receive `401` with a `WWW-Authenticate: Bearer` header. A valid token lacking the `mobile`
ability (e.g. an `mcp`-only token) MUST receive `403`.

*Verified by: `tests/Feature/ApiHabitsTest.php` (401 + `WWW-Authenticate`, `mcp`-only token → 403,
and the identical-404 assertion across unknown/foreign/archived habit ids on both the read and write
halves).*

#### Scenario: An archived habit resolves to the same 404 as an unknown one

- GIVEN a habit archived by its owner
- WHEN the owner calls `POST /api/v1/habits/{habit}/increment` against it
- THEN the response SHALL be `404 {"message":"Recurso no encontrado."}`
- AND SHALL be byte-identical to the response for a nonexistent habit id

#### Scenario: An unauthenticated request receives a bearer challenge

- GIVEN no bearer token is presented
- WHEN `GET /api/v1/habits/today` is requested
- THEN the response SHALL be `401`
- AND the `WWW-Authenticate` header SHALL start with `Bearer`
