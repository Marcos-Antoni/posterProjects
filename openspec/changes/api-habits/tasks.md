# Tasks: Today's Habits Read + Tap API (`api-habits`)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~880 (design corrects proposal's ~860: +factory closure, +migration test, +un-completion RED test) |
| 400-line budget risk | High (~2.2x) |
| Chained PRs recommended | No |
| Suggested split | Single PR, four atomic commits (1→2→3→4) |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Migration + `Habit` model changes, no route | PR 1 (commit 1) | `php artisan test --compact --filter=HabitEntryLoggingTest` | `tests/Browser/HabitFlowTest.php` (must be green before any API code exists) | Revert commit; column stays additive, no route registered, `ApiContractTest` unaffected |
| 2 | Read endpoint `GET /habits/today` | PR 1 (commit 2) | `php artisan test --compact --filter=ApiHabitsTest` | N/A — no UI change this commit | Revert commit; drops route+resource+OpenAPI op together |
| 3 | Write endpoints `increment`/`decrement` | PR 1 (commit 3) | `php artisan test --compact --filter=ApiHabitsTest` | N/A — API-only | Revert commit; `habit_days` rows already decremented keep `completed=true` (proposal Rollback §2), no repair needed |
| 4 | `peak_amount` into web + MCP payloads | PR 1 (commit 4) | `php artisan test --compact --filter="HabitTodayViewTest\|McpHabitTools"` | `npm run build` then manual load of `/habits` | Revert commit; field removal, no schema change |

## Phase 0: Spec Artifact Gap

- [x] 0.1 Create `openspec/changes/api-habits/specs/habits/spec.md` — MODIFIED delta restating "Entries Accumulate With A Persisted Real Percent" as high-water-mark completion (`completed OR peak_amount >= target`) plus decrement behavior; source: proposal "Modified Capabilities", design D-1/D-2.
- [x] 0.2 Create `openspec/changes/api-habits/specs/api-habits/spec.md` — ADDED delta for the new `api-habits` capability: today-list shape, tap pair, sticky-completion contract, 404/422 non-disclosure set; source: proposal "New Capabilities" + "Today resource" table.

## Phase 1: Migration + Model (Commit 1)

- [x] 1.1 Modify `database/factories/HabitDayFactory.php`: add `'peak_amount' => fn (array $a): int => $a['accumulated_amount']`. Prerequisite — without it every existing `HabitDay::factory(['accumulated_amount' => N])` fixture violates `accumulated <= peak` and this commit cannot go green.
- [x] 1.2 RED — migration-backfill test: seed `habit_days` incl. one `completed=true` row whose habit's `daily_target` was raised after recording; run the raw backfill statement; assert `completed ⟺ peak_amount >= target` for every row.
- [x] 1.3 GREEN — create `database/migrations/2026_08_01_000000_add_peak_amount_to_habit_days_table.php`: `$table->unsignedInteger('peak_amount')->default(0)->after('accumulated_amount')` + exact clamp: `UPDATE habit_days SET peak_amount = GREATEST(habit_days.peak_amount, habit_days.accumulated_amount, CASE WHEN habit_days.completed THEN CASE WHEN habits.habit_type = 'quantitative' THEN GREATEST(1, COALESCE(habits.daily_target, 0)) ELSE 1 END ELSE 0 END) FROM habits WHERE habits.id = habit_days.habit_id`. Do not paraphrase this expression. Comment: `unsignedInteger` resolves to plain `int4` on Postgres — declares intent, enforces nothing.
- [x] 1.4 Modify `app/Models/HabitDay.php`: `@property int $peak_amount`, add to fillable/docblock.
- [x] 1.5 RED — `+1, -1, +1` un-completion regression on a yes/no habit: expect `completed: true` throughout. Fails on the proposal's original 2-line change; write this first.
- [x] 1.6 RED — zero-floor model test: `Habit::decrementToday()` at `accumulated_amount: 0` throws `ValidationException`; re-read the row and assert it is byte-unchanged (rollback proof).
- [x] 1.7 RED — invariant test: `accumulated_amount <= peak_amount` always; `peak_amount <= SUM(habit_entries)` always; assert **equality with the ledger only on decrement-free days** — NOT unconditional equality (corrects proposal Decision 4's cross-check; counter-example `+1,-1,+1` → `peak=1`, `SUM=2`).
- [x] 1.8 RED — two independent `Habit::query()->firstOrFail()` instances both decrement from `accumulated_amount: 1`; assert final value `0`, never `-1` (mirrors `HabitEntryLoggingTest.php:122-139`).
- [x] 1.9 RED — decremented-to-zero completed day survives `currentStreak()` recomputation unchanged.
- [x] 1.10 GREEN — modify `app/Models/Habit.php::recordEntry()` (`:137`): add peak maintenance (`$day->peak_amount = max($day->peak_amount, $accumulated)`), then CHANGE the `completed` assignment (not append) from `$day->completed = $accumulated >= $target;` to `$day->completed = $day->completed || $day->peak_amount >= $target;`. Honest count: +2 lines, 1 modified line.
- [x] 1.11 GREEN — add `Habit::decrementToday(): HabitDay`: `DB::transaction`, `days()->where('entry_date', ...)->lockForUpdate()->first()`, throw `ValidationException::withMessages(['habit' => 'No hay nada que descontar hoy.'])` when `$day === null || $day->accumulated_amount < 1`, else `accumulated_amount -= 1`, `completion_percent` recomputed, `completed = $day->completed || $day->peak_amount >= $target`, `peak_amount` untouched, `save()`. Non-nullable return — no caller can read `null` as success.
- [x] 1.12 Run all of 1.2, 1.5–1.9 to green; then run `HabitMetricsTest` (18 scenarios) and full `HabitEntryLoggingTest` (15) explicitly. If any scenario pins the old non-monotone flip, STOP — do not edit the test, report to the owner instead.
- [x] 1.13 Run `tests/Browser/HabitFlowTest.php` and confirm green — must pass with no route registered, isolating any browser failure to the model change alone.
- [x] 1.14 `vendor/bin/pint --dirty --format agent`.

## Phase 2: Read Endpoint (Commit 2)

- [x] 2.1 Create `app/Http/Resources/HabitTodayResource.php`: `forHabit(Habit $habit, Carbon $today): self`, the 12 fields (incl. `peak_amount`), flattened zeros when no day row, `week_recorded_days` only for `TimesPerWeek`.
- [x] 2.2 Create `app/Http/Resources/HabitTodayCollection.php`: `$collects = HabitTodayResource::class`, bare `{"data": [...]}`, no `meta`.
- [x] 2.3 RED — `ApiHabitsTest` read half: exact 12-field shape via `toBe()`; ordering by name; no-day-row flattening; archived/unscheduled excluded; `week_recorded_days` null except `TimesPerWeek`; query count identical at 1 and 5 habits under `Model::preventLazyLoading()`; 401 + `WWW-Authenticate: Bearer`; `mcp`-only token → 403.
- [x] 2.4 GREEN — create `app/Http/Controllers/Api/V1/HabitController.php::today()`; eager-load `days` for the current window.
- [x] 2.5 Add route `GET /api/v1/habits/today` in `routes/api.php`, registered before any `{habit}` route.
- [x] 2.6 Update `openapi/v1.json`: `Habits` tag, `listTodayHabits` operation, `TodayHabit`/`TodayHabitCollectionResponse`/`TodayHabitResponse` schemas (all 3 schemas land here, per commit boundary).
- [x] 2.7 Run `ApiContractTest`; `vendor/bin/pint --dirty --format agent`.

## Phase 3: Write Endpoints (Commit 3)

- [x] 3.1 RED — `ApiHabitsTest` write half: yes/no `+1`; quantitative `×N` taps; decrement; sticky completed; zero rejection returns `422 {"errors":{"habit":[...]}}`; unknown/foreign/archived habit → identical `404` with no `App\Models\Habit` substring.
- [x] 3.2 RED — shape-identity assertion: `increment` response `data` `array_keys()` and values equal the matching `GET today` list element (D-4's acceptance test).
- [x] 3.3 RED — 23:30/23:31 UTC-6 boundary, via HTTP: increment at 23:30 UTC-6 then decrement at 23:31 UTC-6 must land on the same `habit_days` row (`count() === 1`) AND `entry_date === '2026-07-10'`. Binds `recordEntry()`'s and `decrementToday()`'s two independent day-anchor call sites (`Habit.php:113`, `:304-307`) — the only thing that does, since neither is refactored to share a helper.
- [x] 3.4 GREEN — create `app/Http/Controllers/Api/V1/HabitEntryController.php`: `increment()`, `decrement()`. Resolve habit via `$request->user()->habits()->whereNull('archived_at')->whereKey($habit)->firstOrFail()` — no route-model binding (D-5). Both call `HabitTodayResource::forHabit()` after `$habit->load([...])` and return `200`.
- [x] 3.5 Add routes `POST /api/v1/habits/{habit}/increment` and `.../decrement`, `->whereNumber('habit')`.
- [x] 3.6 Update `openapi/v1.json`: `incrementHabit`, `decrementHabit` operations (no `requestBody`, empty body by contract); reuse existing error schemas.
- [x] 3.7 Run `ApiContractTest`; run full `php artisan test --compact`; `vendor/bin/pint --dirty --format agent`.

## Phase 4: Web/MCP Propagation (Commit 4)

- [x] 4.1 Modify `app/Http/Controllers/HabitController.php` (`:99-104`, `todayProgress()`): add `peak_amount` to the Inertia payload.
- [x] 4.2 Modify `app/Mcp/Tools/Habits/TodayHabits.php` (`:85-90`): add `peak_amount` to the tool response.
- [x] 4.3 Modify `resources/js/components/habits/today-habit-card.tsx` (`:18-23`): add `peak_amount: number` to `HabitTodayProgress`. No JSX change.
- [x] 4.4 RED then GREEN — update `HabitTodayViewTest` and `McpHabitTools*` shape assertions to include `peak_amount`.
- [x] 4.5 `npm run build`; `vendor/bin/pint --dirty --format agent`.

## Phase 5: Final Regression

- [x] 5.1 Run full `php artisan test --compact` — must be 100% green (baseline 507/507; new tests add to the count).
- [x] 5.2 Re-run `tests/Browser/HabitFlowTest.php` standalone — confirm still green after all four commits.
- [x] 5.3 Confirm every success-criteria item in `proposal.md` is covered by a passing test; note any gap before archive. **Gap found and closed**: the "3 increments, target 5, `completed: false`" and "decrement preserves `peak_amount`/`currentStreak()`" scenarios were not yet asserted at the API layer — added `ApiHabitsTest` coverage. **One criterion is a deliberate, documented supersession, not a gap**: proposal's "`peak_amount` equals SUM of `habit_entries` for every fixture, including after decrements" is the exact claim design D-3 proves wrong (`+1,-1,+1` → `peak=1`, `SUM=2`); the corrected invariant (`accumulated <= peak <= SUM`, equality only on decrement-free days) is tested instead per design, which is authoritative over the proposal.
