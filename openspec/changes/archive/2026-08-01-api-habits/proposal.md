# Proposal: Today's Habits Read + Tap API (`api-habits`)

## Intent

The owner logs habits where he does them — walking, in the kitchen, in bed — not at a desk. Today the
only write path is a web form (`routes/web.php:64-74`), so every habit is logged retroactively or not
at all. This change ships the phone's whole habit surface: the list of habits due today, and a `+1` /
`-1` tap pair.

It is also the **first write endpoint in `/api/v1`** — all eleven shipped operations are GET
(`routes/api.php:12-34`) — and the first backend **decrement** anywhere: neither the web nor the MCP
tools can undo a log.

## Scope

### In Scope

- `GET /api/v1/habits/today` — active habits scheduled for the current UTC-6 day, each with its
  day aggregate flattened in.
- `POST /api/v1/habits/{habit}/increment` — +1, always, for every habit type.
- `POST /api/v1/habits/{habit}/decrement` — -1 against the day aggregate only.
- `habit_days.peak_amount` (new column + backfill) — the day's high-water mark, which is what keeps
  `completed` derivable (Decision 4).
- `Habit::decrementToday()`; two extra lines of peak maintenance in `Habit::recordEntry()`.
- `App\Http\Resources\HabitTodayResource`, `Api\V1\HabitController`, `Api\V1\HabitEntryController`.
- `openapi/v1.json` delta: 3 operations, 1 tag, 3 schemas.
- `peak_amount` propagated to the web Inertia payload and the MCP `TodayHabits` payload — **data
  only, no rendering change** (Decision 8).

### Out of Scope

- Creating, editing, archiving, or unarchiving habits from the phone. Web-only, unchanged.
- Manual amounts. There is no `amount` parameter anywhere in this change; both endpoints take an
  **empty body**.
- Offline logging, queueing, retry-on-reconnect. The phone requires connectivity.
- Editing past days. Both writes operate on the current UTC-6 day only.
- Streaks, charts, history, `GET /api/v1/habits/{habit}`.
- Deleting `habit_entries` rows. The ledger is append-only and stays that way.
- A "corregido" badge in the web or MCP UI (Decision 8 ships the field, defers the pixels).

## Capabilities

### New Capabilities

- `api-habits`: the `/api/v1/habits` surface — the today-list resource shape, the tap pair, the
  sticky-completion contract on the wire, and its 404/422 non-disclosure set.

### Modified Capabilities

- `habits` (`openspec/specs/habits/spec.md`): the requirement **"Entries Accumulate With A Persisted
  Real Percent"** (`:66-77`) currently states a day "MUST be considered completed exactly at the
  target". That becomes false. The delta must restate completion as a **high-water-mark** rule and
  add the decrement behaviour. Every other habits requirement is untouched — notably the UTC-6
  anchoring (`:43-51`), archiving (`:181-195`), per-user scoping (`:202-221`), and the today view
  (`:223-237`), all of which this change mirrors rather than alters.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | **Two zero-body action endpoints**: `POST /api/v1/habits/{habit}/increment` and `.../decrement`. Not `POST /entries` + `DELETE /entries`, not one endpoint with a `direction` parameter. | `DELETE` would be a lie: decrement never removes a ledger row (clarify, `exploration.md:149,286`). A `direction` enum buys one route and costs a FormRequest, a Spanish message, and a `"direction":"sideways"` 422 branch — validation surface invented to describe two buttons. The codebase already pairs state-transition verbs this way (`POST habits/{habit}/archive` / `unarchive`, `routes/web.php:64-74`), and `/api/v1` already has a non-resourceful action POST (`POST api/v1/logout`, `routes/api.php:26`). Symmetric names also let the two buttons in the mockup (`exploration.md:243-264`) map 1:1 with no client branching. |
| 2 | **Both writes return `200` with a body byte-shape-identical to one element of the today list.** Not `201`. | `201` is for a created, addressable resource; the ledger entry has no `GET` and never will (out of scope), so a `Location` header would point nowhere. Returning the *list element* shape means the client splices the response over the row it tapped with zero special-casing and never refetches the list. That symmetry is also why `date` is a per-element field (Decision 3). |
| 3 | **`date` (the UTC-6 day the operation landed on) is a field on every element, not top-level `meta`.** | `api-board-sprints` Decision 8 established that a non-pagination `meta` breaks the published client rule that `meta` present means paginated. Per-element costs ~10 bytes × ~10 habits and makes each element self-describing — which is load-bearing at the rollover: a tap at 00:01 UTC-6 returns a `date` that differs from the list's, so the client can detect the day flip instead of splicing yesterday's progress onto today's row. |
| 4 | **New column `habit_days.peak_amount`** (`unsignedInteger`, default 0, backfilled `= accumulated_amount`). `completed` is now derived as `peak_amount >= target`. `accumulated_amount` may drop; `peak_amount` never does. | This is the answer to the invariant the owner's decision 5 breaks. Without it, `completed` is simply not recomputable and any maintainer writing a nightly recompute silently erases legitimate streaks. **A code comment is the weakest available guard and is rejected as the primary one** — it is invisible to whoever writes that job against the table. A column converts an un-recomputable flag into a recomputable one under a new, explicit rule, and it is *honest data*: "the most that was logged that day". Backfill is provably correct because before this change the accumulator was monotonic. Independent cross-check: `peak_amount` must always equal the SUM of that day's `habit_entries` (decrement never writes to the ledger), so a test pins the two sources against each other and catches drift in either. |
| 5 | **`completion_percent` tracks `accumulated_amount` and DOES drop.** It is not frozen at peak. | Exactly one invariant moves, not two. `percent = round(accumulated / target * 100)` stays true, and the spec's documented character for this field is "the real completion percent — kept as recorded, under or over 100" (`openspec/specs/habits/spec.md:66-77`, `app/Models/HabitDay.php:12-16`). Freezing it would silently redefine the >100 overshoot as "highest ever overshoot", a different quantity, and would make the evolution chart (`HabitController::show()`) plot a number that contradicts the accumulator on the same row. **A `0%` + `completed: true` row does not read as broken once `peak_amount: 1` is on the same row explaining it** — that is precisely what Decision 4 buys. Accepted cost: a corrected day plots below 100% on the chart while counting as completed. |
| 6 | **Decrement does NOT reuse `Habit::recordEntry()`.** New `Habit::decrementToday(): ?HabitDay`, anchored on `Habit::todayLocalDate()` (`app/Models/Habit.php:304-307`). | Reuse is impossible by construction: `recordEntry()` opens by creating a `HabitEntry` (`Habit.php:108-111`), which decrement must not do. `todayLocalDate()` is **not** a hand-rolled `now()` — it is the sanctioned read-side anchor already driving `HabitController::today()`, `TodayHabits`, `ShowHabit`, and every streak computation, and both it and `recordEntry()` evaluate the identical `now()->setTimezone(Config::string('habits.timezone'))->toDateString()`. Divergence is pinned by test, not by hope: log at 23:30 UTC-6 then decrement at 23:31 and assert **one** row. `recordEntry()` is deliberately **not** refactored to share a private helper — it is the highest-risk method in the domain (15 pinned scenarios) and a structural refactor there to prevent a divergence a test already catches is a bad trade. Residual risk stated in the table below. |
| 7 | **Decrement's concurrency is stricter than increment's**: `SELECT ... FOR UPDATE` inside a transaction, but **no `insertOrIgnore`** — a missing day row is a rejection, not a row to create. Two concurrent decrements at `accumulated = 1` serialize; the second re-reads 0 and 422s. | Mirrors `Habit.php:116-127` minus the claim step. **The zero floor MUST live in application code**: `accumulated_amount` is declared `unsignedInteger` (`database/migrations/2026_07_23_022328_create_habit_days_table.php:19`), but Laravel's Postgres grammar drops `unsigned` — on the test and production database there is **no DB-level floor**, and `-1` would store silently. Known benign gap: `lockForUpdate()->first()` on a nonexistent row takes no lock, so a concurrent increment may create it mid-flight; decrement rejects and the client retries. |
| 8 | **Web UI and MCP get `peak_amount` in their payloads, but no rendering change.** | They read the same aggregate and were not written expecting `accumulated 0 / completed true`. Today they would render "0 / 1" with a completed badge — *true*, but unexplainable without the high-water mark. Shipping the field (~6 lines across `HabitController::todayProgress()` `:89-105`, `Mcp/Tools/Habits/TodayHabits.php:75`, and the TS type at `resources/js/components/habits/today-habit-card.tsx:31`) makes the row explainable to every consumer. The "cumplido (corregido)" badge is deliberately deferred: it is UI polish for a state the owner creates by mis-tapping, and it would drag the browser suite into a scope that is otherwise API-only. |
| 9 | **Archived, foreign, and unknown habits all resolve to `404`** via one owner-scoped `->whereNull('archived_at')->firstOrFail()`. The web's `422` for archived is deliberately **not** mirrored. | One `firstOrFail()`, one failure mode, so the response cannot branch on the reason — the same non-disclosure rule `api-board-sprints` Decision 9 established. An archived habit is absent from the today list, so from the phone it does not exist, and `404` drives the correct client reaction (drop the row) for all three cases. The spec's "an archived habit MUST reject new entries" (`spec.md:181-195`) is satisfied; only the status code differs from the web, which is already true of every `/api/v1` authorization outcome. |
| 10 | **The single `422` is decrement-at-zero**, raised as `ValidationException::withMessages(['habit' => 'No hay nada que descontar hoy.'])`. | The clarified requirement is explicit that the API rejects rather than silently succeeds (`exploration.md:217-222`), so idempotent-success is ruled out. The `habit` error key matches the web's convention for the same class of state error (`StoreHabitEntryRequest.php:52-60`). `409` is not used anywhere in this API and would be a new code in the error contract for no gain. |
| 11 | **The numeric-cast scar becomes irrelevant on this surface, and is left exactly where it is.** | Both endpoints have empty bodies and call `recordEntry(1)` with a literal, so `is_numeric()`-then-cast (`HabitEntryController.php:20-23`, `LogHabitEntry.php:51-53`) is never reached from `/api/v1`. It is **not** removed: the web form still submits typed strings, and `tests/Browser/HabitFlowTest.php:64-102` is the regression that caught it. No `amount` on the API means the bug class cannot recur there at all. |

## Today resource — field by field

Bare `{"data": [ ... ]}`, unpaginated, `ORDER BY name` (matching `HabitController.php:57`).

| Field | Type | Why |
|---|---|---|
| `id` | int | The tap endpoints' path parameter. Numeric, per clarify. |
| `date` | string (`Y-m-d`) | Decision 3 — the UTC-6 day, per element. |
| `name` | string | Rendered. |
| `habit_type` | `yes_no` \| `quantitative` | The only field that lets the client pick a widget without inferring it from `target`/`unit`. |
| `unit` | string\|null | `"3 / 8 vasos"`. |
| `target` | int | **Server-computed** `max(1, daily_target)` for quantitative, `1` for yes/no (`Habit.php:129-131`). Ships instead of `daily_target` so the phone never re-derives the two-branch rule. |
| `accumulated_amount` | int | `0` when no day row exists — flattened, never `null`. |
| `completion_percent` | int | Decision 5. `0` when no row. |
| `completed` | bool | Sticky. `false` when no row. |
| `peak_amount` | int | Decision 4 — makes a `0 / completed:true` row explainable on the wire. |
| `times_per_week` | int\|null | Denominator of `"2 de 4 días esta semana"`. |
| `week_recorded_days` | int\|null | Null unless `TimesPerWeek`, mirroring `HabitController.php:72-74`. |

**Deliberately omitted**, with reasons: `daily_target` (superseded by `target`); `recurrence_type` and
`weekdays` (the list is already filtered to today — the phone renders no schedule);
`planned_delta_minutes` (analytics, out of scope); `planned_time` (not in the mockup; additive later);
`can_decrement` (derivable as `accumulated_amount > 0` — a single comparison against a field already
on the wire, and a second source of truth that could disagree with its sibling); `archived_at` (an
archived habit is never in this response); `updated_at` (every other resource's `updated_at`
identifies one row's mutation, but this element merges two rows, so the value would be ambiguous and
a client caching on it would miss wrongly).

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `database/migrations/*_add_peak_amount_to_habit_days_table.php` | New | Column + backfill `UPDATE habit_days SET peak_amount = accumulated_amount` |
| `app/Models/HabitDay.php` | Modified | `@property`, `#[Fillable]` |
| `app/Models/Habit.php` | Modified | `recordEntry()` +2 lines of peak maintenance; new `decrementToday()` |
| `app/Http/Resources/HabitTodayResource.php` | New | The 12 fields above |
| `app/Http/Controllers/Api/V1/HabitController.php` | New | `today()` |
| `app/Http/Controllers/Api/V1/HabitEntryController.php` | New | `increment()`, `decrement()` |
| `routes/api.php` | Modified | +3 routes in the existing `auth:sanctum` + `abilities:mobile` group; `{habit}` constrained `whereNumber` so `habits/today` can never collide with a future `habits/{habit}` |
| `openapi/v1.json` | Modified | +1 tag, +3 operations, +3 schemas, all `bearerAuth` |
| `app/Http/Controllers/HabitController.php`, `app/Mcp/Tools/Habits/TodayHabits.php`, `resources/js/components/habits/today-habit-card.tsx` | Modified | `peak_amount` added to the payload (Decision 8), no rendering change |
| `tests/Feature/ApiHabitsTest.php` | New | The API surface |
| `tests/Feature/HabitEntryLoggingTest.php` | Modified | Decrement, boundary, concurrency, peak/ledger agreement |
| `tests/Feature/HabitTodayViewTest.php`, `tests/Feature/Mcp/McpHabitTools*` | Modified | Shape assertions gain `peak_amount` |
| `app/Http/Requests/StoreHabitEntryRequest.php`, `HabitPolicy.php`, `bootstrap/app.php` | **Unchanged** | Web validation and the error contract are reused as-is |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A future batch job recomputes `completed` from `accumulated_amount` and erases legitimate streaks | **High** without a guard | Decision 4's column makes `completed` recomputable again (`peak_amount >= target`); the modified `habits` spec requirement states the rule; a test asserts a decremented-to-zero completed day survives a `currentStreak()` recomputation |
| Decrement's day anchor drifts from `recordEntry()`'s, splitting one UTC-6 day across two rows | Med | Decision 6: `todayLocalDate()`, plus a boundary test that logs at 23:30 UTC-6 and decrements at 23:31 asserting one row. **Residual risk stated honestly**: the timezone conversion still exists at two call sites in `Habit.php` (`:113`, `:306`); only the test binds them |
| `accumulated_amount` goes negative | Med | No DB floor exists on Postgres (Decision 7). Guarded in `decrementToday()` under the row lock, tested at 0 and under two concurrent decrements from 1 |
| The migration runs on a table the web and MCP read live | Low | Additive nullable-free column with a default; no existing column is altered or dropped; backfill is a single `UPDATE` and idempotent |
| Touching `recordEntry()` regresses the web write path | Med | The change is two additive lines and does not touch the amount handling. `tests/Browser/HabitFlowTest.php` plus 15 `HabitEntryLoggingTest` scenarios are the net, and `openspec/config.yaml:43-44` makes running the browser suite mandatory here |
| Unbounded taps grow `habit_entries` without limit; no per-endpoint throttle is added | Low | Same exposure the web form already has; bounded by a per-user `mobile` token. Flagged as an open question below rather than silently throttled |
| A corrected day plots below 100% on the web evolution chart while reading as completed | Low | Accepted and documented (Decision 5). Alternative was freezing the percent, which breaks the >100 overshoot semantics |

## Rollback Plan

Three layers, in the order they would be undone.

**1. Routes and API code.** Reverting the branch deletes both `Api\V1` controllers, the resource, and
the three routes, and restores `openapi/v1.json`. `ApiContractTest` re-greens automatically because
the revert removes routes and documentation in the same commit — which is also why commits cannot
split by layer.

**2. Rows the decrement path already wrote.** This is the part that matters, because it writes to a
table the web and MCP read. After a revert, some `habit_days` rows carry `accumulated_amount` below
the true ledger sum and `completed = true` below target. **No data repair is required and none should
be attempted.** The reverted code never decrements, so those rows simply stop diverging further;
`completed` stays `true`, so **no streak is lost by the revert itself**. The web today card renders
"0 / 1" with a completed badge — surprising, not wrong. The dangerous move would be a "cleanup" that
recomputes `completed` from `accumulated_amount`; that is the exact failure Decision 4 exists to
prevent, and it must not be run.

**3. The column.** `down()` drops `peak_amount`. That is the only lossy step, and it is **recoverable
without a backup**: `peak_amount` always equals the SUM of that day's `habit_entries`, so it can be
rebuilt from the intact ledger. Preferred rollback is therefore **revert the code, keep the column** —
an unused column costs nothing and preserves the explanation for every corrected row.

Partial rollback is available at commit granularity: reverting commits 3 and 4 leaves the read
endpoint shipped and removes only the writes.

## Dependencies

- **None new.** No Composer or npm package. Builds on `api-token-auth`, merged.

## Delivery Forecast

Estimated **~860 changed lines** against the 400-line review budget.

- **Decision needed before apply: No** — `size:exception` is pre-authorized for this session.
- **Chained PRs recommended: No** (one PR, four atomic commits).
- **400-line budget risk: High** (~2.15×).

| # | Scope | ~Lines |
|---|---|---|
| 1 | Migration + backfill; `HabitDay` metadata; `recordEntry()` peak maintenance; `Habit::decrementToday()`; `HabitEntryLoggingTest` additions (decrement, zero floor, UTC-6 boundary, concurrent decrement, peak-vs-ledger agreement, sticky completion survives a streak recompute) | ~230 |
| 2 | `HabitTodayResource`; `Api\V1\HabitController::today()`; route; OpenAPI tag + `listTodayHabits` + schemas; `ApiHabitsTest` read half (exact shape via `toBe()`, ordering, no-day-row flattening, archived/unscheduled exclusion, `week_recorded_days`, N+1, 401/403) | ~330 |
| 3 | `Api\V1\HabitEntryController`; 2 routes; OpenAPI ×2 operations; `ApiHabitsTest` write half (yes/no +1, quantitative ×N taps, decrement, sticky completed, zero rejection, 404 ×3, response-shape identity with a list element, date rollover) | ~250 |
| 4 | `peak_amount` into the web Inertia payload, `TodayHabits`, and the TS type; shape assertions updated in `HabitTodayViewTest` and `McpHabitTools*` | ~50 |

Commit 1 registers no route, so `ApiContractTest` is unaffected and the suite stays green on its own.
Commits 2 and 3 each pair route + document + test and cannot be split further. Every commit leaves
`php artisan test --compact` green. Run `vendor/bin/pint --dirty --format agent` before each.
`npm run build` is required for commit 4 only (TS type).

If the exception is reversed, the chain stacks cleanly: 1 → 2 → 3 → 4, sharing only `routes/api.php`
and `openapi/v1.json` between 2 and 3.

## E2E Acceptance

Feature-level HTTP in `tests/Feature/ApiHabitsTest.php`, following the `ApiProjectsTest` convention:
a real bearer token driving real requests, never `actingAs`, so guard → ability → owner scoping →
resource is exercised end to end. The acceptance test that matters is the **tap-response identity**:
`GET /habits/today`, `POST .../increment`, and assert the write's `data` is shape-identical to the
matching list element — Decision 2's whole claim, executed.

**`tests/Browser/HabitFlowTest.php` is protected and MUST stay green.** It is the regression that
caught the numeric-cast bug, and this change enters its blast radius through exactly one door: the two
additive lines in `recordEntry()`. What protects it, concretely: (1) `recordEntry()`'s signature,
amount parameter, and cast path are untouched — only `peak_amount` is assigned; (2) the browser test
types a quantitative amount and asserts the accumulated value, so any regression in the cast or the
accumulation fails it; (3) `openspec/config.yaml:43-44` ("do not skip the browser suite when touching
forms that submit amounts") fires on this change and makes running it mandatory, not optional. The
suite is 507/507 green at `40bf4a5`; a red browser test blocks the merge.

## Success Criteria

- [ ] `GET /api/v1/habits/today` returns bare `{data}` with exactly the 12 documented fields per
      element, asserted with `toBe()` so a leaked field fails; ordered by name.
- [ ] A habit with no entries today appears with `accumulated_amount: 0`, `completion_percent: 0`,
      `completed: false`, `peak_amount: 0` — never `null`, never absent.
- [ ] Archived habits and habits not scheduled today are absent; `week_recorded_days` is `null`
      except for `TimesPerWeek`.
- [ ] One `increment` on a yes/no habit yields `accumulated_amount: 1`, `completed: true`; three
      `increment`s on a quantitative habit with target 5 yield `3`, `completed: false`.
- [ ] `decrement` on `accumulated 3 / target 5` yields `2`; `decrement` on a completed yes/no day
      yields `accumulated_amount: 0`, **`completed: true`**, `peak_amount: 1`, and
      `currentStreak()` is unchanged before and after.
- [ ] `decrement` at `accumulated_amount: 0` returns `422` with `{"errors":{"habit":[...]}}` and the
      row is unchanged; two concurrent decrements from `1` leave `0`, never `-1`.
- [ ] An `increment` at 23:30 UTC-6 followed by a `decrement` at 23:31 touch **one** `habit_days`
      row; the `date` field is the UTC-6 day, not the UTC one.
- [ ] `peak_amount` equals the SUM of that day's `habit_entries` for every fixture, including after
      decrements.
- [ ] Unknown id, another user's habit, and an archived habit all return
      `404 {"message":"Recurso no encontrado."}` with no `App\Models\Habit` substring; no token →
      `401` + `WWW-Authenticate: Bearer`; an `mcp`-only token → `403`.
- [ ] The `increment` response body is shape-identical to the matching `today` list element.
- [ ] Query count for `today` is identical with 1 habit and with 5, under
      `Model::preventLazyLoading()`.
- [ ] `ApiContractTest` passes: 3 new operations documented, all declaring `bearerAuth`.
- [ ] `tests/Browser/HabitFlowTest.php` is green, and `php artisan test --compact` is 100% green.

## Proposal question round

Interactive shaping was unavailable (`auto` mode; the owner explicitly asked not to be stopped, and
`sdd-clarify` already settled the product questions). These are the **new** questions this proposal
raised, each with the default it picked, recorded for correction before `sdd-spec`:

1. **`completion_percent` drops on decrement while `completed` stays true** (Decision 5). Default:
   percent follows the accumulator. The alternative — freezing it at the peak — would make the row
   internally consistent at the cost of redefining the >100 overshoot semantics and making the
   evolution chart plot a number that contradicts the accumulator. If the chart's honesty matters
   more than the overshoot semantics, this flips.
2. **A new column was preferred over a comment or a spec line alone** (Decision 4). Default: ship
   `peak_amount`. If the owner refuses a migration on `habit_days`, the fallback is that `completed`
   stays recomputable only from `habit_entries` via a UTC-6 date conversion in SQL — cheaper now,
   materially more dangerous later, and the row stops explaining itself.
3. **Archived habits return `404`, not the web's `422`** (Decision 9). Default: `404`, for
   non-disclosure and a single failure mode. If the phone should distinguish "archived" from "gone"
   to show a specific message, this needs a separate branch and a second code.
4. **No per-endpoint throttle is added.** Default: inherit the group's. Taps are legitimately bursty;
   a token already bounds abuse. If unbounded `habit_entries` growth is a concern, a `throttle:` name
   alongside `api-login` / `api-qr-redeem` is a one-line addition.
5. **The web and MCP get the field but not the badge** (Decision 8). Default: data now, pixels later.
   If a `0 / 1 · cumplido` card on the web is considered a bug rather than a curiosity, the badge
   belongs in this change and adds a browser-suite scope this proposal deliberately avoided.
