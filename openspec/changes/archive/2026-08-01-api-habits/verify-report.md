# Verify Report: `api-habits`

**Mode**: Full spec-driven verification (proposal + design + specs + tasks + apply-progress read; source inspected directly at commit `3a95a8f`)
**Verdict**: **PASS**

## Test Evidence

- `php artisan test --compact` (full suite, PostgreSQL): **539/539 passed, 1793 assertions, 0 failures** (run live during this verify session).
- `php artisan test --compact tests/Browser/HabitFlowTest.php` standalone: **2/2 passed**.
- `git diff 6519c4d..3a95a8f -- tests/Browser/HabitFlowTest.php`: **empty** — the file was never touched in this change's commit range. Not weakened; still the pre-change regression.
- `php artisan route:list --path=api`: 3 habits routes registered (`GET habits/today`, `POST habits/{habit}/increment`, `POST habits/{habit}/decrement`), matching `openapi/v1.json` paths at `openapi/v1.json:803,852,916`. `tests/Feature/ApiContractTest.php:52-92` enforces this bidirectionally and passed.

## 12-Point Property Verification

1. **CONFIRMED** — Migration backfill is the clamped `GREATEST(...)` expression, not a plain copy. `database/migrations/2026_08_01_152144_add_peak_amount_to_habit_days_table.php:31-44` joins `habits` and, for `completed` rows, raises `peak_amount` to at least the habit's *current* target (`GREATEST(1, COALESCE(habits.daily_target, 0))` for quantitative, `1` for yes/no). Byte-identical to `design.md:202-215`. Proven with a target-raised-after-recording fixture in `tests/Feature/HabitDaysPeakAmountBackfillTest.php:48-62` (`peak_amount` clamps to `20`, `completed` stays `true`).

2. **CONFIRMED** — Idempotency is explicitly tested: `tests/Feature/HabitDaysPeakAmountBackfillTest.php:95-107` runs the exact statement twice and asserts the second run produces the same `peak_amount` (`20`) as the first. `GREATEST` including the current `peak_amount` (first argument, `:34` in the migration) is what buys this.

3. **CONFIRMED** — `completed` is monotone (`$day->completed = $day->completed || $day->peak_amount >= $target`) in **both** paths: increment at `app/Models/Habit.php:139`, decrement at `app/Models/Habit.php:185`. Regression-pinned at the model layer by `tests/Feature/HabitEntryLoggingTest.php:227-244` (`+1, -1, +1` on a yes/no habit stays `completed: true`) and at the HTTP layer by `tests/Feature/ApiHabitsTest.php:241-254`.

4. **CONFIRMED** — The zero floor is application code under the row lock, not a schema assumption: `app/Models/Habit.php:174-176` throws before any write when `$day === null || $day->accumulated_amount < 1`, with a doc comment at `:161-163` explicitly stating `unsignedInteger` resolves to plain `int4` on Postgres. Tested at `tests/Feature/HabitEntryLoggingTest.php:246-263` (throws, row byte-unchanged via before/after `DB::table()->sole()` comparison) and at the API layer, `tests/Feature/ApiHabitsTest.php:256-265` (`422`, accumulated unchanged).

5. **CONFIRMED** — `decrementToday(): HabitDay` (non-nullable return type), `app/Models/Habit.php:167`. Throws `ValidationException` inside `DB::transaction`, guaranteeing rollback. No `?HabitDay` anywhere in the shipped code.

6. **CONFIRMED, and the boundary test asserts the right thing.** Both `recordEntry()` (`Habit.php:114-115`, via `$entry->logged_at->clone()->setTimezone(...)`) and `decrementToday()` (`Habit.php:170`, via `self::todayLocalDate()`) resolve through `Config::string('habits.timezone')`/`todayLocalDate()` (`Habit.php:345-348`) — never `now()`/`today()` in app timezone. The test at `tests/Feature/ApiHabitsTest.php:301-318` travels to 05:30 UTC (23:30 UTC-6), increments, travels to 05:31 UTC (23:31 UTC-6), decrements, and asserts **both** `$habit->days()->count() === 1` *and* `$day->entry_date->toDateString() === '2026-07-10'` *and* `$response->assertJsonPath('data.date', '2026-07-10')`. This is exactly the two-part binding design.md calls load-bearing (`design.md:332-336`) — count-alone or date-alone would each pass under a weaker regression; both together do not.

7. **CONFIRMED — the correct invariants shipped, not the proposal's wrong ones.** `tests/Feature/HabitEntryLoggingTest.php:265-295` pins `accumulated_amount <= peak_amount` (always) and `peak_amount <= SUM(entries)` (always), with an explicit `+1, -1, +1` counter-example proving `peak_amount (1) != SUM(entries) (2)` after a decrement (`:287-294`, using `->not->toBe($ledgerSum())`). The proposal's original "`peak_amount` always equals SUM" claim is not what's tested — the design-corrected version is.

8. **CONFIRMED** — `database/factories/HabitDayFactory.php:29` uses `'peak_amount' => fn (array $attributes): int => $attributes['accumulated_amount']`, a closure resolving against `create()`/`state()` overrides, exactly as design D-4/commit-1 boundary specifies.

9. **CONFIRMED** — `recordEntry()` (`Habit.php:106-151`) and `decrementToday()` (`Habit.php:167-190`) compute the day anchor independently (`$entry->logged_at->clone()->setTimezone(...)` vs. `self::todayLocalDate()`); no shared private helper exists. The 15+ pinned `HabitEntryLoggingTest` scenarios ride on `recordEntry()` unchanged in structure.

10. **CONFIRMED** — `php artisan route:list --path=api` output matches `openapi/v1.json` paths exactly: `today`, `{habit}/increment`, `{habit}/decrement` (openapi/v1.json:803, 852, 916). `{habit}` is a plain typed int path parameter resolved manually (`HabitEntryController.php:46-53`, `HabitController.php` uses no `{habit}` at all) — no Eloquent route-model binding anywhere in this surface (design D-5). `ApiContractTest` (`tests/Feature/ApiContractTest.php:52-67`) enforces bidirectional parity and passed live.

11. **CONFIRMED** — `git diff 6519c4d..3a95a8f -- tests/Browser/HabitFlowTest.php` is empty (file untouched), and it passes 2/2 standalone. Its blast-radius exposure (`recordEntry()`'s two added lines) did not require editing the test itself, and the test's own assertions (`25/20`, `125%`, `Cumplido`) hold under both the old and new `completed` expression per design.md:390-392 — verified true by the passing run.

12. **CONFIRMED** — `peak_amount` propagated to both consumers with no rendering change: `app/Http/Controllers/HabitController.php:103` (web Inertia `todayProgress()`), `app/Mcp/Tools/Habits/TodayHabits.php:89` (MCP), `resources/js/components/habits/today-habit-card.tsx:22` (TS type only, no JSX diff). A `0/1 completed` row is explainable because `peak_amount: 1` ships on the same payload, per design's stated intent (`design.md:360-364`).

## Additional Assessment

- **Migration rollback**: `down()` (`database/migrations/2026_08_01_152144_add_peak_amount_to_habit_days_table.php:50-55`) is a single `dropColumn('peak_amount')` — no FK, no index, no dependent constraint. Clean by inspection; the column is additive-only and never altered elsewhere. Documented ordering is accurate: filename timestamp `2026_08_01_152144` sorts after `2026_07_23_022328_create_habit_days_table.php`, and RefreshDatabase-driven test runs (539/539 passing, including the backfill tests that seed and re-query the column) exercise `up()` successfully on every test run.
- **Endpoint documentation parity**: no endpoint documented-but-unreachable or reachable-but-undocumented — `ApiContractTest` passed live, proving bidirectional match at commit time.
- **TDD interruption**: `apply-progress.md`'s self-corrected Phase 2 ordering slip (controller/route written before the RED test, reverted, redone) is a process note, not a code-state concern. Verified independently via direct source read (not apply-progress's narrative): `HabitController.php::today()`, the route registration, and `HabitTodayResource`/`HabitTodayCollection` are all present, correctly wired, and pass 539/539 including the 8 read-half `ApiHabitsTest` scenarios that directly exercise this code path. No half-finished edit found.
- **Superseded success criterion documented, not silently dropped**: `tasks.md:82` (task 5.3) explicitly records that the proposal's literal "`peak_amount` equals SUM of `habit_entries` for every fixture, including after decrements" is a deliberate supersession by design D-3's proven-correct invariant, not an implementation gap. This matches direct inspection of the shipped test (property 7 above).

## Design/Proposal Deviations (all correctly resolved in design's favor)

Per the task's stated authority rule (design corrects proposal in six places — D-1 through D-6), all six corrections were verified as implemented, not just documented:
- D-1 (monotone `||` completed, not a bare `peak_amount >= target`): shipped, `Habit.php:139,185`.
- D-2 (clamped `GREATEST` backfill, not a plain copy): shipped, migration `:31-44`.
- D-3 (corrected invariant, not "peak == SUM always"): shipped, `HabitEntryLoggingTest.php:265-295`.
- D-4 (single assembler, shape identity by construction): shipped, `HabitTodayResource::forHabit()` used by all three actions.
- D-5 (no route-model binding): shipped, manual `firstOrFail()` scoping.
- D-6 (`decrementToday(): HabitDay`, throwing, not `?HabitDay`): shipped, `Habit.php:167`.

## Issues

None CRITICAL. None WARNING. None SUGGESTION beyond what apply-progress already self-flagged and closed (the two success-criteria coverage gaps closed in task 5.3, independently confirmed present in `ApiHabitsTest.php:213-225,241-254`).

## Task Completion

All 38 tasks in `tasks.md` marked `[x]`. Spot-verified against source for every task touching production code (Phase 0 spec deltas present on disk; Phase 1 migration/model; Phase 2 read endpoint; Phase 3 write endpoints; Phase 4 web/MCP propagation) — all match the checked-off description.

## Final Verdict: PASS

No CRITICAL, no WARNING, no SUGGESTION. All 12 pinned properties CONFIRMED against live source and a live 539/539 test run, not apply-progress's self-report. Safe to proceed to archive.
