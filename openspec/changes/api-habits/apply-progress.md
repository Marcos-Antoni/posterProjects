# Apply Progress: Today's Habits Read + Tap API (`api-habits`)

**Mode**: Strict TDD (RED → GREEN → REFACTOR)
**Status**: 38/38 tasks complete. Ready for verify.

## Session note

This session continued a run that died to an API error partway through Phase 1 (5/38 tasks done
on disk: 0.1, 0.2, 1.1, 1.2, 1.3, plus in-progress `app/Models/HabitDay.php` and
`tests/Feature/HabitEntryLoggingTest.php` edits that turned out to be complete, not half-written).
Baseline verified before resuming: `php artisan test --compact` → 513/513 (507 baseline + 6 from
the already-landed `HabitDaysPeakAmountBackfillTest`). Completed all remaining tasks (1.4 through
5.3) in this session.

**One self-correction during Phase 2**: I wrote `HabitTodayResource`, `HabitTodayCollection`, the
`HabitController::today()` GREEN implementation, and the route registration *before* writing the
RED test (`ApiHabitsTest` read half) — a violation of RED-before-GREEN. Caught before running any
other tests. Reverted `HabitController.php` (deleted) and the route registration, wrote the RED
test, confirmed all 8 assertions failed with `404` (route absent), then restored the controller and
route as the GREEN step and confirmed all 8 passed. No further deviations after this point.

## Completed Tasks — all 38, by phase

**Phase 0 — Spec Artifact Gap** (0.1, 0.2): delta specs at `openspec/changes/api-habits/specs/` —
already on disk from the prior session, verified present.

**Phase 1 — Migration + Model** (1.1–1.14): factory closure, backfill migration + test (prior
session); `HabitDay.php` peak_amount metadata (prior session, verified complete); 5 new RED tests
(+1,-1,+1 un-completion, zero-floor, invariants, concurrency, streak survival) written first and
confirmed failing (`Call to undefined method decrementToday()` / wrong assertions); GREEN —
`recordEntry()` peak maintenance + monotone `completed`, new `decrementToday()`; task 1.12's
required re-run of `HabitMetricsTest` (18) and full `HabitEntryLoggingTest` (22, incl. the 5 new)
— **all green, nothing pinned the old flip**; Browser suite green with no route registered; Pint
clean.

**Phase 2 — Read Endpoint** (2.1–2.7): `HabitTodayResource::forHabit()`, `HabitTodayCollection`,
RED `ApiHabitsTest` read half (8 tests, confirmed failing on route-not-found after the
self-correction above), GREEN `HabitController::today()` + route, `openapi/v1.json` `Habits` tag +
`listTodayHabits` + 3 schemas, `ApiContractTest` + Pint clean.

**Phase 3 — Write Endpoints** (3.1–3.7): RED write-half tests (11 scenarios incl. shape-identity
and the 23:30/23:31 UTC-6 HTTP boundary), confirmed failing (7 genuine 404s; the 3-case
unknown/foreign/archived dataset passed trivially pre-GREEN for the wrong reason — re-verified
post-GREEN that it passes for the *right* reason, i.e. real habit-scoping, not route absence).
GREEN `HabitEntryController::increment()/decrement()`, routes with `whereNumber('habit')`,
`openapi/v1.json` `incrementHabit`/`decrementHabit` operations. `ApiContractTest`, full suite
(536/536), Pint — all clean.

**Phase 4 — Web/MCP Propagation** (4.1–4.5): RED assertions added to `HabitTodayViewTest` and
`McpHabitToolsTest` expecting `peak_amount` (confirmed failing: `Undefined array key "peak_amount"`
/ missing substring), GREEN — added `peak_amount` to both `todayProgress()` implementations and the
`HabitTodayProgress` TS type (no JSX change). `npm run build` and `npm run types:check` clean.

**Phase 5 — Final Regression** (5.1–5.3): full suite 539/539; Browser suite green standalone;
success-criteria audit against `proposal.md` — see below.

## Files Changed

| File | Action | What Was Done |
|---|---|---|
| `database/migrations/2026_08_01_152144_add_peak_amount_to_habit_days_table.php` | Created (prior session) | `peak_amount` column + clamped idempotent backfill |
| `database/factories/HabitDayFactory.php` | Modified (prior session) | `peak_amount` closure default |
| `app/Models/HabitDay.php` | Modified | `@property int $peak_amount`, fillable, docblock |
| `app/Models/Habit.php` | Modified | `recordEntry()`: peak maintenance + monotone `completed`; new `decrementToday(): HabitDay` |
| `app/Http/Resources/HabitTodayResource.php` | Created | Single 12-field assembler, `forHabit()` |
| `app/Http/Resources/HabitTodayCollection.php` | Created | Bare `{"data": [...]}`, no `meta` |
| `app/Http/Controllers/Api/V1/HabitController.php` | Created | `today()` |
| `app/Http/Controllers/Api/V1/HabitEntryController.php` | Created | `increment()`, `decrement()`, no route-model binding |
| `routes/api.php` | Modified | 3 routes; `today` before `{habit}`; `whereNumber('habit')` |
| `openapi/v1.json` | Modified | `Habits` tag; `listTodayHabits`, `incrementHabit`, `decrementHabit`; `TodayHabit` + 2 response schemas |
| `app/Http/Controllers/HabitController.php` | Modified | `peak_amount` in `todayProgress()` (Inertia payload) |
| `app/Mcp/Tools/Habits/TodayHabits.php` | Modified | `peak_amount` in `todayProgress()` (MCP payload) |
| `resources/js/components/habits/today-habit-card.tsx` | Modified | `peak_amount: number` on `HabitTodayProgress` type only |
| `tests/Feature/ApiHabitsTest.php` | Created | 19 tests: full read + write API surface |
| `tests/Feature/HabitEntryLoggingTest.php` | Modified | +5 model-level tests (un-completion, zero-floor, invariants, concurrency, streak) |
| `tests/Feature/HabitDaysPeakAmountBackfillTest.php` | Created (prior session) | 6 backfill tests |
| `tests/Feature/HabitTodayViewTest.php` | Modified | +1 test, `peak_amount` assertion added to existing test |
| `tests/Feature/McpHabitToolsTest.php` | Modified | +1 test |
| `openspec/changes/api-habits/specs/` | Created (prior session) | Delta specs |
| `openspec/changes/api-habits/tasks.md` | Modified | All 38 tasks marked `[x]` |

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 1.5–1.9 | `HabitEntryLoggingTest.php` | Model/Feature | ✅ 17/17 pre-existing green | ✅ Written, confirmed failing (2 assertion fails + 3 undefined-method errors) | ✅ 22/22 after `recordEntry()`+`decrementToday()` | ✅ Multiple habit types/scenarios per invariant | ➖ None needed |
| 1.10–1.11 | (implementation, driven by 1.5–1.9) | — | — | — | ✅ | — | — |
| 2.1–2.5 | `ApiHabitsTest.php` (read half) | Feature (HTTP) | N/A (new file) | ✅ Written; self-corrected mid-phase (see Session note) — confirmed failing 8/8 on `404` | ✅ 8/8 after resource+collection+controller+route | ✅ 8 distinct scenarios (shape, flattening, exclusion, week count, auth×2, N+1) | ➖ None needed |
| 2.6–2.7 | `ApiContractTest.php` | Contract | ✅ 2/2 pre-existing | N/A — contract test already exists, exercises new operations | ✅ 2/2 | ➖ N/A | ➖ None needed |
| 3.1–3.3 | `ApiHabitsTest.php` (write half) | Feature (HTTP) | ✅ 8/8 (read half, still passing) | ✅ Written, confirmed failing (7 route-404s; 3-case 404-dataset passed trivially, re-verified post-GREEN) | ✅ 18/18 after `HabitEntryController` + routes | ✅ yes/no, quantitative, decrement, sticky-completed, zero-reject, 404-dataset (×3), shape-identity, UTC-6 boundary | ➖ None needed |
| 3.4–3.6 | (implementation, driven by 3.1–3.3) | — | — | — | ✅ | — | — |
| 4.1–4.4 | `HabitTodayViewTest.php`, `McpHabitToolsTest.php` | Feature | ✅ 3/3 and 14/14 pre-existing | ✅ Written, confirmed failing (`Undefined array key "peak_amount"` ×2, missing substring ×1) | ✅ 5/5 and 15/15 after adding `peak_amount` to both `todayProgress()` methods + TS type | ➖ Single field, no branching — skipped per Strict TDD's "purely structural, one possible output" exemption | ➖ None needed |
| 5.3 gap-close | `ApiHabitsTest.php` | Feature (HTTP) | ✅ 19/19 minus the 2 new | ✅ Written (target-5/3-taps/incomplete; decrement peak_amount+streak) — these exercised already-correct GREEN code so they passed immediately; documented as coverage-gap closure, not a RED/GREEN cycle for new behavior | ✅ 19/19 | ➖ N/A (test-only addition, no new production code) | ➖ None needed |

### Test Summary

- **Total tests written this session**: 26 (5 model + 8 API-read + 11 API-write + 2 gap-close (API) + 2 web/MCP shape). The 6 backfill tests and Phase-0 spec deltas were written in the prior session and verified present/passing.
- **Total tests passing**: 539/539 (baseline 507 + 32 new: 6 backfill + 5 model + 19 `ApiHabitsTest` + 1 `HabitTodayViewTest` + 1 `McpHabitToolsTest`).
- **Layers used**: Model/Feature (Pest `RefreshDatabase`, real Postgres) for everything; Feature/HTTP with real bearer tokens (never `actingAs` for API) for `ApiHabitsTest`; Browser (Playwright-backed Pest) re-verified standalone.
- **Approval tests** (refactoring): None — no refactoring tasks in this change; `recordEntry()`'s day-anchor logic was deliberately left untouched per design.
- **Pure functions created**: 0 new standalone pure functions — the domain logic (`decrementToday()`, `recordEntry()`) is necessarily transactional/stateful per the existing pattern; `HabitTodayResource::toArray()` is a pure mapping given its inputs.

## Work Unit Evidence

| Unit | Focused test command and result | Runtime harness and result | Rollback boundary |
|---|---|---|---|
| 1 — Migration + Model | `php artisan test --compact --filter=HabitEntryLoggingTest` → 22/22 passed. Also `--filter=HabitMetricsTest` → 18/18 (task 1.12 gate — no old-flip pinning found). | `tests/Browser/HabitFlowTest.php` → 2/2 passed, run with no API route registered | Revert `app/Models/Habit.php`, `app/Models/HabitDay.php`; migration stays additive, no route exists yet |
| 2 — Read Endpoint | `php artisan test --compact --filter=ApiHabitsTest` → 8/8 (read half) | `ApiContractTest` → 2/2 (route+doc pairing) | Revert `HabitController.php`, route line, `openapi/v1.json`'s `listTodayHabits` block; resource classes are dead code with no route |
| 3 — Write Endpoints | `php artisan test --compact --filter=ApiHabitsTest` → 19/19 (full file, incl. gap-close tests) | `php artisan test --compact` (full suite) → 539/539 | Revert `HabitEntryController.php`, 2 route lines, `openapi/v1.json`'s write operations; existing `habit_days` rows keep whatever `completed` they had (documented in proposal Rollback §2, unaffected by this apply) |
| 4 — Web/MCP Propagation | `php artisan test --compact --filter="HabitTodayViewTest|McpHabitToolsTest"` → 20/20 | `npm run build` → success; `npm run types:check` → clean | Revert 3 files (`HabitController.php`, `TodayHabits.php`, `today-habit-card.tsx`); field removal, no schema change |

## Deviations from Design

None — implementation matches design.md's D-1 through D-6 exactly, including the exact clamp
expression (byte-identical, verified against the migration file), the `||` monotone `completed`
form, non-nullable `decrementToday(): HabitDay`, no route-model binding, and the single-assembler
shape-identity guarantee.

**Process deviation (self-corrected)**: see Session note above — RED-before-GREEN was briefly
violated in Phase 2 and corrected before any test ran against the prematurely-written code.

## Issues Found

**Task 1.12 (the non-routine gate)**: Ran `HabitMetricsTest` (18 scenarios) and the full
`HabitEntryLoggingTest` (22, incl. the 5 new) explicitly after the monotone `completed` change.
**Result: both fully green. Nothing pinned the old non-monotone flip.** No STOP was required —
`HabitMetricsTest` builds its fixtures directly via `HabitDay::factory()->create(['completed' =>
...])` rather than through `recordEntry()`, so none of its 18 scenarios exercise the changed
expression; `HabitEntryLoggingTest`'s pre-existing 15 (17 with datasets) scenarios only assert
accumulation and percent, never a decrement-then-increment un-completion sequence, so the old and
new `completed` expressions agree on every one of them (design's own argument: the old behavior is
a strict subset of the new one for every pre-existing test fixture).

**Success-criteria audit (task 5.3)** found two real coverage gaps, closed in this session (see
`tasks.md` 5.3 note and the "gap-close" TDD row above):
1. Proposal's exact scenario "three increments on a quantitative habit with target 5 yield 3,
   `completed: false`" had no API-level test — only model-level coverage of partial accumulation
   existed. Added `ApiHabitsTest`'s "three increments... below target...".
2. `peak_amount` staying at its high-water mark and `currentStreak()` staying unchanged specifically
   through the **decrement HTTP endpoint** (not just the model method) was untested. Added the
   assertion to the existing "a decrement never un-completes..." test.

**One proposal success-criterion is a deliberate supersession, not a gap**: "`peak_amount` equals
the SUM of that day's `habit_entries` for every fixture, including after decrements" is the exact
claim design D-3 proves incorrect (`+1, -1, +1` → `peak_amount=1`, `SUM(entries)=2`). Per the task
prompt's explicit instruction ("design.md — authoritative; it corrects the proposal in six
places"), the corrected invariant (`accumulated <= peak_amount` always, `peak_amount <=
SUM(entries)` always, equality only on decrement-free days) is what's tested
(`HabitEntryLoggingTest`'s "accumulated never exceeds peak..." test), not the proposal's original
literal claim. Applying the proposal's claim as written would require reverting design D-1/D-3 and
was not done.

## Status

38/38 tasks complete. `php artisan test --compact`: 539/539 passing (baseline 507 + 32 new), zero
failures. `tests/Browser/HabitFlowTest.php` green standalone. `vendor/bin/pint --dirty --format
agent` clean. `npm run build` and `npm run types:check` clean. Working tree left uncommitted per
instructions. **Ready for sdd-verify.**
