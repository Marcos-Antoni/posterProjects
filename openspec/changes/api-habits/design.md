# Design: Today's Habits Read + Tap API (`api-habits`)

## Technical Approach

Three routes in the existing `auth:sanctum` + `abilities:mobile` group, two thin `Api\V1`
controllers, and **one** resource assembler shared by all three actions so the write responses are
shape-identical to a list element by construction rather than by convention (proposal Decision 2).

The domain change is a single new column, `habit_days.peak_amount`, and a redefinition of `completed`
from *derived-from-accumulator* to **monotone, raised by the high-water mark**. Both the increment and
the decrement write `completed` through the same expression. `recordEntry()` gains peak maintenance;
`decrementToday()` is new and never touches `habit_entries`.

---

## Architecture Decisions

### D-1 — `completed` is monotone in code, not only recomputable in SQL

**Choice**: both writers set `$day->completed = $day->completed || $day->peak_amount >= $target;`

**Alternatives**: (a) `peak_amount >= $target` alone, as the proposal's Decision 4 states;
(b) leave `recordEntry()`'s `$accumulated >= $target` untouched, as the proposal's "+2 additive lines".

**Rationale**: **(b) is a bug the proposal did not catch.** With target 5, accumulated 5 and
`completed = true`, three decrements leave accumulated 2. The next `+1` tap re-evaluates
`2 >= 5` → false and **un-completes a completed day from an increment** — the exact streak loss the
whole change exists to prevent. `recordEntry()`'s `completed` line MUST change; the honest count is
**+2 lines and 1 modified line**, not "+2 additive".

(a) fails on a target raised *after* the migration: a day completed at peak 1 whose habit later moves
to target 20 flips to `false` on the next tap. The `||` makes that impossible for any row, at any
time, regardless of what the backfill did.

The old behavior is a strict subset of the new one for every pre-change row: before this change the
accumulator was monotone, so `peak == accumulated` and `completed` could only be true when a prior
accumulated already met the target. The one behavioral delta is target-raised-mid-day, where the day
now stays completed — which is the requested sticky rule. `HabitMetricsTest` and
`HabitEntryLoggingTest` must be re-run to confirm no scenario pins the old flip.

### D-2 — Backfill is clamped to preserve `completed`, and it is idempotent

**Choice**: one Postgres statement (see *Migration* below):
`peak_amount = GREATEST(peak_amount, accumulated_amount, CASE WHEN completed THEN <target> ELSE 0 END)`.

**Alternatives**: plain `UPDATE habit_days SET peak_amount = accumulated_amount` (proposal Decision 4).

**Rationale**: the plain copy is correct *only* if every habit's `daily_target` is unchanged since the
day was written. `PATCH habits/{habit}` can raise `daily_target` or flip `habit_type`
(`HabitController.php:176-182`), so production almost certainly holds rows with `completed = true` and
`accumulated_amount < current target`. On those rows the plain copy leaves `peak < target`, and
`decrementToday()`'s first write **erases the completion** — the failure mode the column was added to
prevent, shipped by the migration itself.

**What it does to a `completed = true` row**: raises `peak_amount` to at least the habit's current
target, so `completed ⟺ peak_amount >= target` holds for every legacy row the moment the migration
finishes. For the overwhelming majority (target unchanged) `accumulated_amount >= target` already
holds, `GREATEST` returns `accumulated_amount`, and the statement is byte-identical to the plain copy.

**Accepted cost, stated**: on the clamped minority, `peak_amount > SUM(habit_entries of that day)`.
The clamp cannot repair the opposite direction — `completed = false` with `accumulated >= a lowered
target` — but that direction only ever *grants* completion on a recompute, never erases it, so it is
fail-safe and is left alone.

**The clamp and D-1 are both required and neither replaces the other**: the clamp fixes what exists at
migration time (a data problem, fixed in data); `||` fixes what can be created afterwards (a runtime
problem, fixed in code).

### D-3 — The ledger cross-check the proposal specified is wrong

**Choice**: pin `accumulated_amount <= peak_amount` (always) and `peak_amount <= SUM(entries)`
(always, forward rows). Assert **equality** with the ledger only on days with no decrement.

**Rationale**: proposal Decision 4 asserts `peak_amount` *always equals* the day's entry sum. Counter-
example: `+1, -1, +1` leaves `peak = 1` and `SUM(entries) = 2`. Written as specified, that test goes
red on the first decrement-then-increment fixture. `accumulated <= peak` is the strong, cheap,
always-true invariant and it needs no join to the ledger.

### D-4 — One assembler, two call sites, shape identity by construction

**Choice**: `HabitTodayResource::forHabit(Habit $habit, Carbon $today): self` resolves the day row from
the eager-loaded `days` relation and computes `week_recorded_days`. `today()` maps it over the
collection; both write actions call it on the single habit after `$habit->load([...])`.

**Alternatives**: separate `HabitEntryResource` for writes; a test asserting the two shapes match.

**Rationale**: Decision 2's claim ("the client splices the response over the row it tapped") is a
*structural* guarantee if there is one class and one method, and a *hope* if it is two classes plus a
test. The acceptance test still runs, but it verifies a tautology instead of guarding a divergence.

### D-5 — No route-model binding on `{habit}`

**Choice**: `int $habit` path parameter, resolved as
`$request->user()->habits()->whereNull('archived_at')->whereKey($habit)->firstOrFail()`.

**Rationale**: implicit binding resolves globally and would need a second authorization step, giving
two failure modes. One `firstOrFail()` collapses unknown / foreign / archived into one
`ModelNotFoundException`, rewritten to `404 {"message":"Recurso no encontrado."}` by
`bootstrap/app.php:76-82` — which already strips the `App\Models\Habit` FQCN. Proposal Decision 9,
`api-board-sprints` Decision 9.

### D-6 — `decrementToday(): HabitDay`, throwing, not `?HabitDay`

**Choice**: throw `ValidationException::withMessages(['habit' => 'No hay nada que descontar hoy.'])`
from inside the transaction; return a non-nullable `HabitDay`.

**Rationale**: the proposal's `?HabitDay` pushes the 422 decision to the controller and gives a second
caller (MCP, later) the chance to interpret `null` as success. Throwing inside `DB::transaction`
also rolls back, guaranteeing "the row is unchanged" for free. Signature revised from the proposal.

---

## Data Flow

```
POST /api/v1/habits/{habit}/decrement
  │
  ├─ auth:sanctum → abilities:mobile          401 / 403
  ├─ HabitEntryController::decrement()
  │    └─ user->habits()->whereNull(archived_at)->whereKey()->firstOrFail()   404
  ├─ Habit::decrementToday()  ─── DB::transaction ────────────────┐
  │    entry_date = Habit::todayLocalDate()->toDateString()       │
  │    days()->where(entry_date)->lockForUpdate()->first()        │
  │    null or accumulated < 1  → ValidationException  422  (rollback)
  │    accumulated -= 1                                           │
  │    completion_percent = round(accumulated / target * 100)     │
  │    completed = completed || peak_amount >= target   ← D-1     │
  │    peak_amount UNCHANGED                                      │
  │    save()                                                     │
  └────────────────────────────────────────────────────────────── COMMIT
  ├─ $habit->load(['days' => whereBetween(weekStart, today)])
  └─ HabitTodayResource::forHabit($habit, $today)   →  200 {"data": {...}}
```

### Sequence — two concurrent decrements at `accumulated = 1`

```
      Tx A                          Postgres row (acc=1, peak=1, completed=t)      Tx B
       │                                        │                                   │
  BEGIN│                                        │                              BEGIN│
   SELECT..FOR UPDATE ───────────────────────► [lock granted to A]                  │
       │                                        │◄─── SELECT..FOR UPDATE ───────────│
       │                                   [B blocks]                               │
   acc = 1-1 = 0                                │                                   │
   completed = t || (peak 1 >= 1) = TRUE        │                                   │
   UPDATE; COMMIT ──────────────────────────► acc=0 ─── [lock released] ───────────►│
       │                              READ COMMITTED re-evaluates under EvalPlanQual│
       │                                        │                        B sees acc=0│
       │                                        │             throw ValidationException
       │                                        │                     ROLLBACK ──► 422
```

Result: `0`, never `-1`. This is the identical mechanism `recordEntry()` already relies on
(`Habit.php:116-127`); Laravel's default Postgres isolation is READ COMMITTED, under which
`SELECT ... FOR UPDATE` re-reads the row after the lock is granted.

### Sequence — decrement racing an increment on a day with no row yet

```
      Decrement                                                          Increment
  BEGIN│                                                                     │BEGIN
   SELECT..FOR UPDATE where entry_date=D ──► 0 rows: NO LOCK TAKEN           │
       │                                                                      │
       │                              ◄─── INSERT ON CONFLICT DO NOTHING ─────│
       │                              ◄─── SELECT..FOR UPDATE (row exists) ───│
   day === null                                                    acc=0+1=1 │
   throw ValidationException                                        COMMIT ──►│
   ROLLBACK ──► 422                                                           │
```

Benign and stated in proposal Decision 7: a `FOR UPDATE` matching zero rows takes no lock, so the
decrement cannot see a row created after its `SELECT`. It rejects; the client retries and succeeds.
Deliberately not fixed with `insertOrIgnore` — creating a day row in order to refuse to decrement it
would write a `0/0/false` aggregate for a day the owner never logged, corrupting
`completionForPeriod()` (which counts *any* recorded day for `TimesPerWeek`, `Habit.php:279`).

---

## Migration

`database/migrations/2026_08_01_000000_add_peak_amount_to_habit_days_table.php`

```php
public function up(): void
{
    Schema::table('habit_days', function (Blueprint $table) {
        $table->unsignedInteger('peak_amount')->default(0)->after('accumulated_amount');
    });

    // Postgres has no unsigned integers: Laravel's grammar silently drops the
    // modifier and this resolves to int4, exactly like `accumulated_amount`
    // (2026_07_23_022328:19). The declaration documents intent; it enforces
    // NOTHING. The zero floor is application code under the row lock.
    //
    // Backfill (idempotent — `GREATEST` with the current value):
    //  1. accumulated_amount is the true high-water mark for every pre-change
    //     row, because the accumulator was monotone until this migration.
    //  2. The `completed` clamp repairs rows whose habit's daily_target was
    //     raised (or whose habit_type flipped) after the day was recorded.
    //     Without it those rows carry peak < target and the first decrement
    //     erases their completion. See design D-2.
    DB::statement(<<<'SQL'
        UPDATE habit_days
        SET peak_amount = GREATEST(
            habit_days.peak_amount,
            habit_days.accumulated_amount,
            CASE WHEN habit_days.completed THEN
                CASE WHEN habits.habit_type = 'quantitative'
                     THEN GREATEST(1, COALESCE(habits.daily_target, 0))
                     ELSE 1 END
            ELSE 0 END
        )
        FROM habits
        WHERE habits.id = habit_days.habit_id
    SQL);
}

public function down(): void
{
    Schema::table('habit_days', function (Blueprint $table) {
        $table->dropColumn('peak_amount');
    });
}
```

`UPDATE ... FROM` and `GREATEST` are Postgres syntax. `phpunit.xml:29` pins `DB_CONNECTION=pgsql`, so
tests and production share the grammar; a SQLite fallback would need `MAX()` and is deliberately not
written. `down()` is lossy only for the clamped minority — every other row's `peak_amount` is
rebuildable from the intact `habit_entries` ledger. Preferred rollback stays *revert the code, keep
the column* (proposal Rollback §3).

---

## File Changes

| File | Action | Description |
|---|---|---|
| `database/migrations/*_add_peak_amount_to_habit_days_table.php` | Create | Column + clamped idempotent backfill (D-2) |
| `app/Models/HabitDay.php` | Modify | `@property int $peak_amount`, `#[Fillable]` entry, docblock |
| `database/factories/HabitDayFactory.php` | Modify | `'peak_amount' => fn (array $a): int => $a['accumulated_amount']` — a closure, so every existing `factory(['accumulated_amount' => N])` call site stays consistent without editing any of them |
| `app/Models/Habit.php` | Modify | `recordEntry()`: `+peak_amount = max(...)`, `completed` line → `||` form (D-1). New `decrementToday(): HabitDay` |
| `app/Http/Resources/HabitTodayResource.php` | Create | 12 fields + `forHabit()` assembler (D-4) |
| `app/Http/Resources/HabitTodayCollection.php` | Create | `ResourceCollection`, `$collects = HabitTodayResource::class` — bare `{"data": [...]}`, no `meta` |
| `app/Http/Controllers/Api/V1/HabitController.php` | Create | `today()` |
| `app/Http/Controllers/Api/V1/HabitEntryController.php` | Create | `increment()`, `decrement()` |
| `routes/api.php` | Modify | 3 routes; `today` registered **before** any `{habit}` route; `->whereNumber('habit')` |
| `openapi/v1.json` | Modify | +1 tag, +3 operations, +3 schemas |
| `app/Http/Controllers/HabitController.php` `:99-104` | Modify | `peak_amount` into `todayProgress()` |
| `app/Mcp/Tools/Habits/TodayHabits.php` `:85-90` | Modify | same |
| `resources/js/components/habits/today-habit-card.tsx` `:18-23` | Modify | `peak_amount: number` on `HabitTodayProgress`. No JSX change |
| `tests/Feature/ApiHabitsTest.php` | Create | The API surface |
| `tests/Feature/HabitEntryLoggingTest.php` | Modify | Decrement, zero floor, boundary, stale-model race, peak invariants |
| `tests/Feature/HabitTodayViewTest.php`, `tests/Feature/Mcp/McpHabitTools*` | Modify | Shape assertions gain `peak_amount` |
| `app/Http/Requests/StoreHabitEntryRequest.php`, `HabitPolicy.php`, `bootstrap/app.php` | **Unchanged** | Error contract reused verbatim |

---

## Interfaces / Contracts

### `Habit::decrementToday()`

```php
/**
 * Subtract one from the current UTC-6 day's aggregate. Never writes to
 * `habit_entries`: the ledger is a log of actions, not of results.
 *
 * The day is claimed by NOTHING — a missing row is a rejection, not a
 * row to create, so this is `recordEntry()`'s lock discipline minus the
 * `insertOrIgnore` claim step.
 *
 * The zero floor lives here and ONLY here: `accumulated_amount` is
 * declared `unsignedInteger` but resolves to a plain `int4` on Postgres,
 * so the database would store `-1` without complaining.
 *
 * @throws ValidationException when today has no row, or it is already 0.
 */
public function decrementToday(): HabitDay
{
    return DB::transaction(function (): HabitDay {
        $entryDate = self::todayLocalDate()->toDateString();

        $day = $this->days()->where('entry_date', $entryDate)->lockForUpdate()->first();

        if ($day === null || $day->accumulated_amount < 1) {
            throw ValidationException::withMessages(['habit' => 'No hay nada que descontar hoy.']);
        }

        $target = $this->habit_type === HabitType::Quantitative ? max(1, (int) $this->daily_target) : 1;
        $accumulated = $day->accumulated_amount - 1;

        $day->accumulated_amount = $accumulated;
        $day->completion_percent = (int) round($accumulated / $target * 100);
        $day->completed = $day->completed || $day->peak_amount >= $target;   // sticky — D-1
        $day->save();                                                        // peak_amount untouched

        return $day;
    });
}
```

`< 1` rather than `=== 0` is deliberate: it is also the repair path if a `-1` ever reaches the table
through some other door.

### Day anchor — the boundary test is the only binding

`recordEntry()` computes `$entry->logged_at->clone()->setTimezone(Config::string('habits.timezone'))->toDateString()`
(`Habit.php:113-114`); `decrementToday()` computes `Habit::todayLocalDate()->toDateString()`
(`Habit.php:304-307`). These are the same expression — `todayLocalDate()` round-trips through
`Carbon::parse(...toDateString())`, which is identity on the date string — but **nothing in the code
enforces that they stay the same.** `recordEntry()` is deliberately not refactored to share a helper
(proposal Decision 6: 15 pinned scenarios, the highest-risk method in the domain).

The binding, and the only one:

```php
test('an increment at 23:30 utc-6 and a decrement at 23:31 land on the same utc-6 day', function () {
    // BOTH instants are already 2026-07-11 in UTC and both are 2026-07-10 in UTC-6.
    $this->travelTo(Carbon::parse('2026-07-11 05:30:00', 'UTC'));   // 23:30 UTC-6
    $this->withToken($token)->postJson("/api/v1/habits/{$habit->id}/increment");

    $this->travelTo(Carbon::parse('2026-07-11 05:31:00', 'UTC'));   // 23:31 UTC-6
    $response = $this->withToken($token)->postJson("/api/v1/habits/{$habit->id}/decrement");

    expect($habit->days()->count())->toBe(1);                       // they agree
    $day = $habit->days()->firstOrFail();
    expect($day->entry_date->toDateString())->toBe('2026-07-10')    // and they agree on UTC-6
        ->and($day->accumulated_amount)->toBe(0);
    $response->assertOk()->assertJsonPath('data.date', '2026-07-10');
});
```

Both assertions are load-bearing and neither is redundant. `count() === 1` alone would still pass if
**both** call sites regressed to UTC together (one row on `2026-07-11`); `entry_date === '2026-07-10'`
alone would still pass if only the decrement regressed and then 422'd. Together they pin agreement
*and* correctness. Chosen over 23:59/00:01 because a same-day pair proves the shared anchor, whereas a
rollover pair proves only that the two disagree — which is correct behavior, not a regression.

### Resource — exactly 12 fields

| Field | Type | Source |
|---|---|---|
| `id` | int | `$habit->id` |
| `date` | string `Y-m-d` | `$today->toDateString()` — per element, proposal Decision 3 |
| `name` | string | `$habit->name` |
| `habit_type` | `yes_no`\|`quantitative` | `$habit->habit_type->value` |
| `unit` | string\|null | `$habit->unit` |
| `target` | int | server-computed `max(1, daily_target)` / `1` |
| `accumulated_amount` | int | `(int) $day?->accumulated_amount` → `0` |
| `completion_percent` | int | `(int) $day?->completion_percent` → `0` |
| `completed` | bool | `(bool) $day?->completed` → `false` |
| `peak_amount` | int | `(int) $day?->peak_amount` → `0` |
| `times_per_week` | int\|null | `$habit->times_per_week` |
| `week_recorded_days` | int\|null | `$habit->days->count()` iff `TimesPerWeek`, else `null` |

Explicit `(int)`/`(bool)` casts on the four day fields: they are the only ones that can be absent, and
the contract is "flattened, never `null`". Every omission is already argued in the proposal
(`daily_target`, `recurrence_type`, `weekdays`, `planned_delta_minutes`, `planned_time`,
`can_decrement`, `archived_at`, `updated_at`).

**`peak_amount` is exposed, and it is the load-bearing field.** Without it a `0 / 1 · completed` row
is indistinguishable from corruption to every consumer — phone, MCP client, and future maintainer
alike. With it the row states its own history: *the most that was logged today was 1, which met the
target; the current count is 0 because it was corrected.* It is also the only field on the wire that
a client can use to render a "corregido" affordance later without a new endpoint.

---

## Blast Radius: Web and MCP

Both read the same aggregate and neither changes rendering (proposal Decision 8).

| Consumer | A sticky-completed row renders as | Change required |
|---|---|---|
| `resources/js/pages/habits/today` card | `0 / 1`, `0%`, **`Cumplido` badge shown** (`today-habit-card.tsx:41` reads `today?.completed`) | None. `peak_amount: 1` ships in the payload and explains it |
| `HabitController::show()` evolution chart | The corrected day plots at `0%` while `currentStreak()` still counts it | None. Accepted, proposal Decision 5 |
| MCP `TodayHabits` | `accumulated_amount: 0, completion_percent: 0, completed: true, peak_amount: 1` | Payload field only |
| MCP `LogHabitEntry` `:58-67` | Returns the day without `peak_amount` | **Noted, not changed** — out of scope; it is a write echo, not the today list |
| `currentStreak()` / `bestStreak()` / `completionForPeriod()` | Read stored `completed`, never recompute it | None — which is exactly why D-1's `\|\|` matters |

The only thing that must **not** happen is a batch job recomputing `completed` from
`accumulated_amount`. The spec delta states the rule as `completed OR peak_amount >= target`.

## Protecting `tests/Browser/HabitFlowTest.php`

It is the regression that caught the numeric-cast bug and `recordEntry()` gains lines here
(`openspec/config.yaml:43-44` makes running the browser suite mandatory on this change).

1. **Signature, amount parameter, and the `is_numeric()` cast path are untouched.** Only three lines
   inside the already-locked transaction change, all after `$accumulated` is computed.
2. **The test's own numbers are unaffected by D-1.** `+15` then `+10` on target 20 → `accumulated 25`,
   `peak 25`, `percent 125`, `completed = false || 25 >= 20 = true`. Its assertions `25 / 20`, `125%`,
   `Cumplido` (`HabitFlowTest.php:94-98`) hold under both the old and the new expression.
3. **The TS change is type-only** (`HabitTodayProgress` gains `peak_amount: number`), so `npm run build`
   is required for commit 4 but no JSX path changes.
4. Commit 1 registers no route and changes no payload, so the browser suite must be green *before* any
   API code exists — that isolates a browser failure to the model change with no API noise.

---

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Model | Decrement subtracts 1; sticky completed; percent drops; peak never drops | `HabitEntryLoggingTest`, direct model calls |
| Model | **Zero floor**: decrement at 0 → 422 and the row is byte-unchanged | `expect(fn () => $habit->decrementToday())->toThrow(ValidationException::class)` + re-read the row |
| Model | **Concurrency**: two decrements from 1 leave 0 | Two independent `Habit::query()->firstOrFail()` instances, mirroring `HabitEntryLoggingTest.php:122-139`. Stated in the test docblock: this simulates the race by holding a stale in-memory model; the suite has no second connection, and the real guarantee is the `FOR UPDATE` + READ COMMITTED re-read |
| Model | **Invariants**: `accumulated <= peak` and `peak <= SUM(entries)` after every mutation; equality with the ledger only on decrement-free days (D-3) | Assertion helper reused across scenarios |
| Model | `+1, -1, +1` on a yes/no habit leaves `completed: true` (D-1's un-completion bug) | New scenario — **this one must be written RED first**; it fails on the proposal's original 2-line change |
| Model | A decremented-to-zero completed day survives `currentStreak()` unchanged | `HabitEntryLoggingTest` |
| Migration | Backfill sets `peak = accumulated`; a `completed` row whose target was raised afterwards gets `peak = target` | Seed rows, raise `daily_target`, run the raw statement, assert `completed ⟺ peak >= target` for every row |
| Feature (HTTP) | Full surface with a **real bearer token, never `actingAs`** | `tests/Feature/ApiHabitsTest.php`, `ApiProjectsTest` convention |
| Feature | Exact 12-field shape via `toBe()`; ordering by name; flattened zeros; archived/unscheduled excluded; `week_recorded_days` null except `TimesPerWeek` | Read half |
| Feature | Query count identical with 1 and 5 habits under `Model::preventLazyLoading()` | `DB::listen` counter |
| Feature | 401 + `WWW-Authenticate: Bearer`; `mcp`-only token → 403; unknown / foreign / archived → identical 404 with no `App\Models\Habit` substring | Write + read halves |
| Feature | **Shape identity**: `POST increment` `data` `array_keys()` and values equal the matching `GET today` element | The acceptance test for D-4 |
| Feature | UTC-6 boundary 23:30 / 23:31 | The binding above |
| Contract | 3 operations documented, all `bearerAuth`, no orphans | `ApiContractTest` — passes automatically once `openapi/v1.json` lands in the same commit as the route |
| Browser | Untouched, must stay green | `php artisan test --compact` on the Browser suite after commit 1 and again at the end |

---

## OpenAPI Delta

**Tag** — `Habits`: *"Today's habit list and the one-tap increment/decrement pair, anchored to the
UTC-6 habit day. Creating, editing and archiving habits are web-only and are not exposed here."*

| Operation | `operationId` | Responses |
|---|---|---|
| `GET /api/v1/habits/today` | `listTodayHabits` | `200` `TodayHabitCollectionResponse`, `401`, `403` |
| `POST /api/v1/habits/{habit}/increment` | `incrementHabit` | `200` `TodayHabitResponse`, `401`, `403`, `404` |
| `POST /api/v1/habits/{habit}/decrement` | `decrementHabit` | `200` `TodayHabitResponse`, `401`, `403`, `404`, `422` |

Paths are written **with the `/api/v1` prefix and the leading slash**, matching every existing entry;
`ApiContractTest` strips the leading slash on both sides (`ApiContractTest.php:47`), so the two
directions only agree if the prefix is present. `{habit}` is declared `type: integer` to mirror
`whereNumber`.

**Schemas** — `TodayHabit` (the 12 fields, `required` on all 12, `nullable` on exactly `unit`,
`times_per_week`, `week_recorded_days`), `TodayHabitCollectionResponse`
(`{data: TodayHabit[]}`, no `meta` — an absent `meta` means the collection is complete), and
`TodayHabitResponse` (`{data: TodayHabit}`). Errors reuse the existing `UnauthenticatedResponse`
(`:1144`), `ForbiddenResponse` (`:1151`), `NotFoundResponse` (`:1122`) and `ValidationErrorResponse`
(`:1129`) — no new error schema. Both write operations declare **no `requestBody`**: the body is
empty by contract.

Neither write is idempotent, so neither may be documented as such; `increment` is the intended repeat
action and `decrement` is its inverse.

---

## Migration / Rollout

Single additive column with a default and a one-time idempotent backfill; no existing column is
altered or dropped, so the web and MCP keep reading the table throughout. No feature flag, no phased
rollout, no downtime step. Rollback follows the proposal's three layers unchanged — with the one
correction that dropping the column is lossy *only* for the clamped minority (D-2), since every other
row's `peak_amount` is rebuildable from `habit_entries`.

## Commit Boundaries

The proposal's four hold. **One revision and two additions:**

| # | Scope | Revision |
|---|---|---|
| 1 | Migration + backfill; `HabitDay` metadata; **`HabitDayFactory` closure default**; `recordEntry()` peak + sticky `completed`; `decrementToday()`; model tests incl. the migration-backfill test and the `+1,-1,+1` RED test | **+`HabitDayFactory`** — without it every existing `HabitDay::factory(['accumulated_amount' => 5])` fixture violates `accumulated <= peak` and commit 1 cannot be green. **+the migration test.** |
| 2 | `HabitTodayResource` + `HabitTodayCollection`; `Api\V1\HabitController::today()`; route; OpenAPI tag + `listTodayHabits` + 3 schemas; `ApiHabitsTest` read half | Unchanged. All 3 schemas land here so commit 3 adds operations only |
| 3 | `Api\V1\HabitEntryController`; 2 routes; 2 OpenAPI operations; `ApiHabitsTest` write half incl. shape identity and the 23:30/23:31 binding | Unchanged |
| 4 | `peak_amount` into the web payload, `TodayHabits`, the TS type; `HabitTodayViewTest` + `McpHabitTools*` shape assertions | Unchanged. Only commit needing `npm run build` |

Commit 1 registers no route, so `ApiContractTest` is unaffected; commits 2 and 3 each pair
route + document + test and cannot be split further. Commit 4 depends only on commit 1 and may be
resequenced to position 2 if the API work slips. Every commit leaves `php artisan test --compact`
green; run `vendor/bin/pint --dirty --format agent` before each. Run the Browser suite after commit 1
and again at the end.

Forecast is unchanged at **~880 lines** (the extra factory, migration test and un-completion test add
~20). `size:exception` is pre-authorized for this session.

- Decision needed before apply: **No**
- Chained PRs recommended: **No** (one PR, four atomic commits; the chain stacks 1→2→3→4 if reversed)
- 400-line budget risk: **High** (~2.2×)

## Threat Matrix

N/A — no routing-classification, shell, subprocess, VCS/PR automation, executable-file
classification, or process-integration boundary. The change adds two HTTP write routes inside an
existing authenticated middleware group; its risk surface is data integrity and authorization, both
covered above.

## Open Questions

- [ ] **D-1 changes `recordEntry()`'s `completed` expression, which the proposal did not scope.** The
      one behavioral delta is target-raised-mid-day: the day now stays completed instead of flipping
      to false. `HabitMetricsTest` (18 scenarios) and `HabitEntryLoggingTest` (15) must be run before
      commit 1 lands to confirm nothing pins the old flip. If something does, it is the test that is
      wrong, not the design — but the owner should see it.
- [ ] **D-2's clamp means `peak_amount > SUM(entries)` for legacy retro-target rows.** Accepted here as
      the lesser harm. If the owner would rather have a strictly honest high-water mark and accept
      that a handful of legacy rows are not recomputable, the clamp comes out and D-1's `||` carries
      the whole guarantee alone.
- [ ] **No per-endpoint throttle** (proposal question 4). Inherits the group's. A `throttle:api-habit-tap`
      name alongside `api-login` / `api-qr-redeem` is a one-line addition if unbounded
      `habit_entries` growth becomes a concern.
