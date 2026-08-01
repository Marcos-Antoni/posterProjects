# Archive Report — api-habits

**Change**: api-habits
**Date**: 2026-08-01
**Mode**: openspec
**Verdict**: Specs merged, folder move NOT performed (no shell/move capability available to this executor)

## Gate Status

- Verify report: `openspec/changes/api-habits/verify-report.md` — **PASS**. 12/12 properties CONFIRMED. 0 CRITICAL, 0 WARNING, 0 SUGGESTION.
- Task Completion Gate: `openspec/changes/api-habits/tasks.md` — 38/38 tasks `[x]`, zero `[ ]`. Spot-verified against source per the verify report.
- Test evidence: `php artisan test --compact` (PostgreSQL) → **539/539 passed, 1793 assertions, 0 failures**, run live during verify. `tests/Browser/HabitFlowTest.php` standalone 2/2 passed and untouched by this change's commit range.
- No CRITICAL issues found. Archive proceeds.

## The Critical Semantic Change — Handled Carefully

`openspec/specs/habits/spec.md` previously stated (Requirement: "Entries Accumulate With A Persisted
Real Percent", old lines 66-77): *"A day MUST be considered completed exactly at the target."* This
change makes that statement false and it has been **replaced in place** — not appended alongside as a
second, contradictory requirement.

New behavior merged into the same requirement block:

- `peak_amount` (new `habit_days` column) tracks the day's high-water mark.
- `completed` is now `completed || peak_amount >= target` — monotone in both the increment path
  (`Habit::recordEntry()`) and the decrement path (`Habit::decrementToday()`).
- A `habit_days` row can therefore legitimately show `accumulated_amount: 0` with `completed: true`;
  `peak_amount` on that same row is what explains it.
- `completion_percent` still tracks `accumulated_amount`, not `peak_amount`, and DOES drop on
  decrement even while `completed` stays `true` — only one invariant moves, not two.

**Maintainer note recorded verbatim in the merged spec** (this was the single most important thing to
preserve): `completed` remains recomputable, but only from `peak_amount`, never from
`accumulated_amount`. A future batch job or backfill that "corrects" `completed` from
`accumulated_amount` alone would silently erase every legitimately completed day that was later
decremented — this is exactly the failure the `peak_amount` column and this requirement exist to
prevent. This warning is now permanent, in-spec text at
`openspec/specs/habits/spec.md` (Requirement: Entries Accumulate With A Persisted Real Percent),
not just in this archive report or the proposal's Risks table.

## Specs Synced

| Domain | Action | Details |
|--------|--------|---------|
| `habits` | Updated (MODIFIED) | 1 requirement replaced in place: **Entries Accumulate With A Persisted Real Percent** — restated as the high-water-mark rule above, decrement behavior added, 4 new scenarios added (decrement corrects an over-tap, decrementing a completed day does not un-complete it, decrement rejected at zero, two concurrent decrements never go negative) alongside the 4 preserved original scenarios. Two additional facts folded into the requirement's prose during merge (present in tasks/verify-report reasoning but not verbatim in the delta spec text): (1) `unsignedInteger` provides no protection on PostgreSQL — the column is a plain `int4`, Laravel drops the modifier, so the zero floor is application code under the row lock, not a schema guarantee; (2) `peak_amount <= SUM(habit_entries)` holds with equality **only on decrement-free days** (not unconditionally — this was design's correction of the proposal's original, wrong claim, and is now stated precisely in-spec). All 8 other `habits` requirements (Habit Types And Recurrence Modes, The Habit Day Is Anchored To UTC-6, Planned-Versus-Actual Delta, Streaks Are Recurrence-Aware, Completion For A Period Respects The Schedule, Archiving Is Reversible And Blocks New Entries, Habits Are Strictly Per-User, The Today View Lists Only Active Scheduled Habits) left untouched. |
| `api-habits` | Created (new domain, no prior main spec) | Full spec copied from the change's delta: 3 requirements (The Today List Returns A Flattened, Complete Habit Shape; One-Tap Increment And Decrement, Shape-Identical To The List; Unknown, Foreign, And Archived Habits Are Indistinguishable). The UTC-6 anchoring fact folded into the decrement requirement's prose during merge: both `recordEntry()` and `decrementToday()` resolve the current day through the same `Config::string('habits.timezone')` (`Etc/GMT+6`) expression, deliberately as two independent call sites rather than a shared helper — the 23:30/23:31 boundary test is what binds them, not a structural guarantee. |

No REMOVED or RENAMED requirements in this change — no destructive merge, no `config.yaml` archive warning triggered.

## Archive Contents (present in the pre-move change folder)

- `proposal.md` ✅
- `exploration.md` ✅
- `specs/habits/spec.md` ✅ (delta)
- `specs/api-habits/spec.md` ✅ (delta)
- `design.md` ✅
- `tasks.md` ✅ (38/38 tasks complete)
- `apply-progress.md` ✅
- `verify-report.md` ✅ (PASS)

## Source of Truth Updated

- `openspec/specs/habits/spec.md` — 1 requirement replaced in place, with the completed/peak_amount
  reasoning preserved as permanent maintainer-facing spec text.
- `openspec/specs/api-habits/spec.md` — created.

## Folder Move — NOT Performed

This executor has no shell, move, or delete capability (Read/Write/Edit/Glob/mem tools only). Per
the archive skill's explicit instruction, no duplicate copy was written to
`openspec/changes/archive/2026-08-01-api-habits/` to avoid an un-deletable duplicate.

**Required follow-up (orchestrator or a shell-capable agent):**
```
mv openspec/changes/api-habits openspec/changes/archive/2026-08-01-api-habits
```

Until this move runs, `openspec/changes/api-habits/` (including this report) remains in the active
changes directory. The merge into `openspec/specs/` above is already complete and correct regardless
of when the move happens.

## SDD Cycle Status

Planned, implemented, and verified. Spec sync complete, including the deliberate in-place replacement
of the now-false "completed exactly at target" requirement with the monotone high-water-mark rule.
Folder archival pending a shell-capable executor.
