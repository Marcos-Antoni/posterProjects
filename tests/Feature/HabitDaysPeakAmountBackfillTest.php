<?php

use App\Models\Habit;
use Illuminate\Support\Facades\DB;

/**
 * Pins the migration's clamped backfill statement (design D-2), independent
 * of the migration's own execution timing: RefreshDatabase already ran the
 * migration against an empty table, so this test seeds "legacy" rows itself
 * and re-runs the exact same statement to prove the clamp, not the plain
 * `peak_amount = accumulated_amount` copy the proposal originally specified.
 *
 * The statement is duplicated here byte-for-byte from the migration
 * (database/migrations/*_add_peak_amount_to_habit_days_table.php) rather
 * than shared, so a paraphrase in either place is caught by a diverging
 * test result instead of silently agreeing with itself.
 */
const HABIT_DAYS_PEAK_AMOUNT_BACKFILL_SQL = <<<'SQL'
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
SQL;

function insertLegacyHabitDay(Habit $habit, array $overrides = []): void
{
    DB::table('habit_days')->insert([
        'habit_id' => $habit->id,
        'entry_date' => $overrides['entry_date'] ?? '2026-07-01',
        'accumulated_amount' => $overrides['accumulated_amount'] ?? 0,
        'peak_amount' => $overrides['peak_amount'] ?? 0,
        'completion_percent' => $overrides['completion_percent'] ?? 0,
        'completed' => $overrides['completed'] ?? false,
        'planned_delta_minutes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('the backfill clamps peak_amount to the current target for a completed row whose target was raised afterwards', function () {
    $habit = Habit::factory()->quantitative('pages', 5)->create();
    insertLegacyHabitDay($habit, ['accumulated_amount' => 5, 'completed' => true]);

    // The habit's target is raised AFTER the day was recorded — the exact
    // scenario the plain `peak_amount = accumulated_amount` copy cannot
    // repair (design D-2).
    $habit->update(['daily_target' => 20]);

    DB::statement(HABIT_DAYS_PEAK_AMOUNT_BACKFILL_SQL);

    $row = DB::table('habit_days')->where('habit_id', $habit->id)->sole();
    expect((int) $row->peak_amount)->toBe(20)
        ->and((bool) $row->completed)->toBeTrue();
});

test('the backfill is byte-identical to a plain copy when the target never changed', function () {
    $habit = Habit::factory()->quantitative('pages', 10)->create();
    insertLegacyHabitDay($habit, ['accumulated_amount' => 10, 'completed' => true]);

    DB::statement(HABIT_DAYS_PEAK_AMOUNT_BACKFILL_SQL);

    $row = DB::table('habit_days')->where('habit_id', $habit->id)->sole();
    expect((int) $row->peak_amount)->toBe(10);
});

test('the backfill clamps a completed yes/no day to peak 1', function () {
    $habit = Habit::factory()->yesNo()->create();
    insertLegacyHabitDay($habit, ['accumulated_amount' => 1, 'completed' => true]);

    DB::statement(HABIT_DAYS_PEAK_AMOUNT_BACKFILL_SQL);

    $row = DB::table('habit_days')->where('habit_id', $habit->id)->sole();
    expect((int) $row->peak_amount)->toBe(1);
});

test('the backfill leaves an uncompleted row honest instead of granting completion', function () {
    $habit = Habit::factory()->quantitative('pages', 20)->create();
    insertLegacyHabitDay($habit, ['accumulated_amount' => 5, 'completed' => false]);

    DB::statement(HABIT_DAYS_PEAK_AMOUNT_BACKFILL_SQL);

    $row = DB::table('habit_days')->where('habit_id', $habit->id)->sole();
    expect((int) $row->peak_amount)->toBe(5)
        ->and((bool) $row->completed)->toBeFalse();
});

test('the backfill is idempotent — running it twice does not change the result', function () {
    $habit = Habit::factory()->quantitative('pages', 5)->create();
    insertLegacyHabitDay($habit, ['accumulated_amount' => 5, 'completed' => true]);
    $habit->update(['daily_target' => 20]);

    DB::statement(HABIT_DAYS_PEAK_AMOUNT_BACKFILL_SQL);
    $first = (int) DB::table('habit_days')->where('habit_id', $habit->id)->value('peak_amount');

    DB::statement(HABIT_DAYS_PEAK_AMOUNT_BACKFILL_SQL);
    $second = (int) DB::table('habit_days')->where('habit_id', $habit->id)->value('peak_amount');

    expect($second)->toBe($first)->toBe(20);
});

test('completed always implies peak_amount at or above the current target after the backfill, across every row', function () {
    $unchanged = Habit::factory()->quantitative('pages', 10)->create();
    insertLegacyHabitDay($unchanged, ['accumulated_amount' => 10, 'completed' => true, 'entry_date' => '2026-07-01']);

    $raised = Habit::factory()->quantitative('pages', 5)->create();
    insertLegacyHabitDay($raised, ['accumulated_amount' => 5, 'completed' => true, 'entry_date' => '2026-07-02']);
    $raised->update(['daily_target' => 30]);

    $neverCompleted = Habit::factory()->quantitative('pages', 20)->create();
    insertLegacyHabitDay($neverCompleted, ['accumulated_amount' => 5, 'completed' => false, 'entry_date' => '2026-07-03']);

    DB::statement(HABIT_DAYS_PEAK_AMOUNT_BACKFILL_SQL);

    $rows = DB::table('habit_days')
        ->join('habits', 'habits.id', '=', 'habit_days.habit_id')
        ->select('habit_days.completed', 'habit_days.peak_amount', 'habits.habit_type', 'habits.daily_target')
        ->get();

    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        $target = $row->habit_type === 'quantitative' ? max(1, (int) $row->daily_target) : 1;

        expect((bool) $row->completed)->toBe((int) $row->peak_amount >= $target);
    }
});
