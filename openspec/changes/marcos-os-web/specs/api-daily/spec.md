## Purpose

API v1 endpoints for the mobile daily loop (R15, R16): progress first, then one next task with its
2-minute version and what it unlocks, plus quick capture — the server contract for `marcos-os-mobile`.

## ADDED Requirements

### Requirement: The Progress Summary Comes First

`GET /api/v1/progress` MUST return, for the UTC-6 calendar: `yesterday` and `this_week` (ISO week so far),
each with `items_done`, `milestones_done`, `habits_shown_up`, `habits_scheduled`, and per identity
statement `votes_cast` / `votes_possible`, plus `streaks` (`[{habit_id, name, streak_current,
streak_state}]`), plus a `days` array for the current ISO week: one entry per calendar day from Monday
through today inclusive (never future days), ascending, each with `date` (`YYYY-MM-DD` in
America/Guatemala, identical to the UTC-6 habit day), `items_done` (items completed that day),
`habits_done` (scheduled habits shown up that day: completed or 2-minute version logged),
`habits_scheduled` (active habits scheduled that day), and `shown_up` (`true` when `items_done > 0` or
`habits_done > 0`). Days with no activity MUST appear with zeros and `shown_up: false`, never omitted. It
MUST NOT include missed counts as debt or any composite score.

#### Scenario: Yesterday is computed on the UTC-6 day

- **GIVEN** a task checked at 23:30 UTC-6 yesterday (already today in UTC)
- **WHEN** `GET /api/v1/progress` is called
- **THEN** the task SHALL count in `yesterday.items_done`

#### Scenario: The week is broken down per day up to today

- **GIVEN** today is Wednesday (America/Guatemala), one task was checked Monday, nothing happened Tuesday,
  and one of two scheduled habits was shown up today
- **WHEN** `GET /api/v1/progress` is called
- **THEN** `days` SHALL hold exactly three entries dated Monday, Tuesday and Wednesday in ascending order
- **AND** Monday SHALL report `items_done: 1` and `shown_up: true`
- **AND** Tuesday SHALL report zeros and `shown_up: false`
- **AND** Wednesday SHALL report `habits_done: 1`, `habits_scheduled: 2` and `shown_up: true`

#### Scenario: A late-evening check lands on its Guatemala day

- **GIVEN** a task checked at 23:30 America/Guatemala on Monday (already Tuesday in UTC)
- **WHEN** `GET /api/v1/progress` is called later that week
- **THEN** the task SHALL count in Monday's `items_done`, not Tuesday's

### Requirement: The Next Task Endpoint Returns Exactly One Task

`GET /api/v1/now` MUST return the same single task the web Now screen shows (active, else suggested) with
`key`, `title`, `two_minute_version`, `objective` (`{key, title}`), `unlocks` (`[{key, title}]`),
`is_active` (boolean: `true` when it is the active item, `false` when it is only suggested) and
`focus_started_at` (ISO 8601 UTC timestamp of the open focus session, `null` when suggested), or
`{"data": null}` when nothing is available. It MUST NEVER return a list of tasks.

#### Scenario: The endpoint matches the web Now screen

- **GIVEN** an active task A
- **WHEN** `GET /api/v1/now` is called
- **THEN** `data.key` SHALL equal A's key
- **AND** `data.is_active` SHALL be `true` and `data.focus_started_at` SHALL equal A's open focus session start

#### Scenario: A suggested task carries no focus clock

- **GIVEN** no active task and one available task B
- **WHEN** `GET /api/v1/now` is called
- **THEN** `data.key` SHALL equal B's key, `data.is_active` SHALL be `false` and `data.focus_started_at` SHALL be `null`

### Requirement: Capture From Mobile

`POST /api/v1/captures` MUST accept `{"text": "..."}` (1–500 chars), store it with source `mobile`, and
respond `201` with `{id, text, created_at}`; invalid text MUST respond `422` in Spanish. The endpoint MUST
honor an optional `Idempotency-Key` header as defined in `Idempotency-Key Deduplicates Mobile Writes`.

#### Scenario: An empty capture is rejected

- **GIVEN** a request with empty text
- **WHEN** `POST /api/v1/captures` is called
- **THEN** the response SHALL be `422` with a Spanish message

### Requirement: Idempotency-Key Deduplicates Mobile Writes

`POST /api/v1/captures`, `POST /api/v1/habits/{habit}/increment`, `POST /api/v1/habits/{habit}/decrement`,
`POST /api/v1/habits/{habit}/two-minute`, `POST .../items/{item}/check`, `POST .../items/{item}/start` and
`POST .../items/{item}/two-minute` MUST accept an optional `Idempotency-Key` header (1–128 printable ASCII
characters). The dedupe window MUST be **24 hours** from the first request with that key, scoped to the
owner and the route. Within the window, a replay with the same key and the same request body MUST NOT
repeat the side effect and MUST return the stored status code and body of the first response, with header
`Idempotent-Replayed: true`. A replay with the same key and a different body MUST respond `422` in Spanish
without side effects. A request whose first attempt with the same key is still being processed MUST respond
`409` in Spanish. Only `2xx` and `422` first responses are stored; a `5xx` first response MUST NOT be stored,
so the client MAY retry with the same key. Requests without the header MUST behave exactly as before. The
header and window MUST be documented in `openapi/v1.json`.

#### Scenario: A replayed capture is stored once

- **GIVEN** a capture sent with `Idempotency-Key: k1` that returned `201`
- **WHEN** the same request with `Idempotency-Key: k1` is sent again within 24 hours
- **THEN** only one capture SHALL exist
- **AND** the response SHALL be the original `201` body with `Idempotent-Replayed: true`

#### Scenario: A reused key with a different body is rejected

- **GIVEN** a capture sent with `Idempotency-Key: k1` and text "a"
- **WHEN** a capture with `Idempotency-Key: k1` and text "b" is sent within 24 hours
- **THEN** the response SHALL be `422` and no second capture SHALL exist

#### Scenario: A key older than the window is treated as new

- **GIVEN** a capture sent with `Idempotency-Key: k1` 25 hours ago
- **WHEN** the same request is sent again
- **THEN** a new capture SHALL be created

### Requirement: Daily Endpoints Share The API Contract

All daily endpoints MUST sit behind `auth:sanctum` + `abilities:mobile`, MUST use the `api-auth` JSON
error contract, MUST be documented in `openapi/v1.json` under a `Daily` tag, and MUST NOT grow their query
count with the number of habits or items involved.

#### Scenario: An mcp-only token cannot read progress

- **GIVEN** a bearer token with ability `['mcp']` only
- **WHEN** `GET /api/v1/progress` is called
- **THEN** the response SHALL be `403` with the documented Spanish body
