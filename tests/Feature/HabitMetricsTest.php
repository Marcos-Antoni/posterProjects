<?php

use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\User;
use Illuminate\Support\Carbon;

// All metric tests run against a frozen "now": 2026-07-22 18:00 UTC,
// which is Wednesday 2026-07-22 12:00 in the feature's UTC-6 zone.
//
// Marcos OS (Phase 4): streaks are TOLERANT — "never miss twice" (habits
// spec, design D5). A single missed opportunity no longer breaks the run;
// times-per-week streaks count closed weeks. The expectations below were
// updated from the removed "Streaks Are Recurrence-Aware" requirement.
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-07-22 18:00:00', 'UTC'));
});

/**
 * @param  array<string, array{completed?: bool, completion_percent?: int}>  $days
 */
function createDays(Habit $habit, array $days): void
{
    foreach ($days as $date => $attributes) {
        HabitDay::factory()->for($habit)->create([
            'entry_date' => $date,
            'completed' => $attributes['completed'] ?? true,
            'completion_percent' => $attributes['completion_percent'] ?? 100,
        ]);
    }
}

test('a habit with no history has zero streaks', function () {
    $habit = Habit::factory()->daily()->create();

    expect($habit->currentStreak())->toBe(0)
        ->and($habit->bestStreak())->toBe(0);
});

test('daily streak counts consecutive completed days ending today', function () {
    $habit = Habit::factory()->daily()->create();
    createDays($habit, [
        '2026-07-20' => [],
        '2026-07-21' => [],
        '2026-07-22' => [],
    ]);

    expect($habit->currentStreak())->toBe(3)
        ->and($habit->bestStreak())->toBe(3);
});

test('a still-pending today does not break the daily streak', function () {
    $habit = Habit::factory()->daily()->create();
    createDays($habit, [
        '2026-07-20' => [],
        '2026-07-21' => [],
    ]);

    expect($habit->currentStreak())->toBe(2);
});

test('a single missed day does not break the daily streak (never miss twice)', function () {
    $habit = Habit::factory()->daily()->create();
    createDays($habit, [
        '2026-07-17' => [],
        '2026-07-18' => [],
        '2026-07-19' => [],
        // 2026-07-20 missed.
        '2026-07-21' => [],
        '2026-07-22' => [],
    ]);

    expect($habit->currentStreak())->toBe(5)
        ->and($habit->bestStreak())->toBe(5);
});

test('a partial day below the target is a miss, and a single miss does not break the streak', function () {
    $habit = Habit::factory()->quantitative('pages', 20)->daily()->create();
    createDays($habit, [
        '2026-07-20' => [],
        '2026-07-21' => ['completed' => false, 'completion_percent' => 50],
        '2026-07-22' => [],
    ]);

    expect($habit->currentStreak())->toBe(2)
        ->and($habit->bestStreak())->toBe(2);
});

test('specific weekdays streak skips unscheduled days without breaking', function () {
    // Scheduled Monday/Wednesday/Friday; completed Fri 17, Mon 20, Wed 22.
    $habit = Habit::factory()->specificWeekdays([1, 3, 5])->create();
    createDays($habit, [
        '2026-07-17' => [],
        '2026-07-20' => [],
        '2026-07-22' => [],
    ]);

    expect($habit->currentStreak())->toBe(3)
        ->and($habit->bestStreak())->toBe(3);
});

test('a single missed scheduled day does not break the specific weekdays streak', function () {
    // Scheduled Mon/Wed/Fri: completed Mon 13, Wed 15, Fri 17 — then
    // missed Mon 20 — then completed Wed 22 (today).
    $habit = Habit::factory()->specificWeekdays([1, 3, 5])->create();
    createDays($habit, [
        '2026-07-13' => [],
        '2026-07-15' => [],
        '2026-07-17' => [],
        '2026-07-22' => [],
    ]);

    expect($habit->currentStreak())->toBe(4)
        ->and($habit->bestStreak())->toBe(4);
});

test('a pending scheduled today does not break the specific weekdays streak', function () {
    // Today (Wed 22) is scheduled but has no record yet.
    $habit = Habit::factory()->specificWeekdays([1, 3, 5])->create();
    createDays($habit, [
        '2026-07-17' => [],
        '2026-07-20' => [],
    ]);

    expect($habit->currentStreak())->toBe(2);
});

test('times per week streak counts fulfilled weeks; the week in progress counts once its quota is reached', function () {
    // Quota 3: last week recorded Mon 13, Wed 15, Fri 17 (met), current
    // week recorded Mon 20 and Tue 21.
    $habit = Habit::factory()->timesPerWeek(3)->create();
    createDays($habit, [
        '2026-07-13' => [],
        '2026-07-15' => [],
        '2026-07-17' => [],
        '2026-07-20' => [],
        '2026-07-21' => [],
    ]);

    expect($habit->currentStreak())->toBe(1)
        ->and($habit->bestStreak())->toBe(1);

    $habit->days()->create(['entry_date' => '2026-07-22', 'completed' => true, 'accumulated_amount' => 1, 'peak_amount' => 1, 'completion_percent' => 100]);

    expect($habit->fresh()->currentStreak())->toBe(2);
});

test('a single week closed under quota does not break the times per week streak', function () {
    // Quota 3: week of Jul 6 met (3 records), week of Jul 13 closed with
    // a single record — the streak peaked at 4 during that week, then
    // broke at its close. Current week has 2 records.
    $habit = Habit::factory()->timesPerWeek(3)->create();
    createDays($habit, [
        '2026-07-06' => [],
        '2026-07-08' => [],
        '2026-07-10' => [],
        '2026-07-14' => [],
        '2026-07-20' => [],
        '2026-07-21' => [],
    ]);

    expect($habit->currentStreak())->toBe(1)
        ->and($habit->bestStreak())->toBe(1)
        ->and($habit->history()->streak()->state->value)->toBe('at_risk');
});

test('empty days in the in-progress week never break the times per week streak', function () {
    // Quota 3 met last week; the current week has no records at all by
    // Wednesday — the streak survives because the week can still reach
    // its quota.
    $habit = Habit::factory()->timesPerWeek(3)->create();
    createDays($habit, [
        '2026-07-13' => [],
        '2026-07-14' => [],
        '2026-07-15' => [],
    ]);

    expect($habit->currentStreak())->toBe(1);
});

test('a sunday under quota does not break the streak while it is still today', function () {
    // Quota 3 met the previous week; the current week only has 1 record
    // and today IS its closing Sunday. The week is still in progress
    // until it is strictly in the past, so the streak survives the
    // whole day even though the quota can no longer be met.
    $this->travelTo(Carbon::parse('2026-07-19 18:00:00', 'UTC'));

    $habit = Habit::factory()->timesPerWeek(3)->create();
    createDays($habit, [
        '2026-07-06' => [],
        '2026-07-08' => [],
        '2026-07-10' => [],
        '2026-07-14' => [],
    ]);

    expect($habit->currentStreak())->toBe(1)
        ->and($habit->history()->streak()->state->value)->toBe('ok');
});

test('a recorded but uncompleted day does not count toward the weekly quota, a 2-minute day does', function () {
    // Quota 2 last week: one full day and one partial day — the week
    // still closes fulfilled, so the current-week day extends the run.
    $habit = Habit::factory()->quantitative('pages', 20)->timesPerWeek(2)->create();
    createDays($habit, [
        '2026-07-14' => [],
        '2026-07-16' => ['completed' => false, 'completion_percent' => 40],
        '2026-07-21' => [],
    ]);

    expect($habit->currentStreak())->toBe(0);

    $habit->days()->where('entry_date', '2026-07-16')->update(['two_minute_logged' => true]);

    expect($habit->fresh()->currentStreak())->toBe(1);
});

test('completion for period on a daily habit is completed days over calendar days', function () {
    $habit = Habit::factory()->daily()->create();
    createDays($habit, [
        '2026-07-09' => [],
        '2026-07-11' => [],
        '2026-07-13' => [],
        '2026-07-15' => [],
        '2026-07-17' => [],
        '2026-07-19' => [],
        '2026-07-21' => [],
    ]);

    $percent = $habit->completionForPeriod(
        Carbon::parse('2026-07-09'),
        Carbon::parse('2026-07-22'),
    );

    expect($percent)->toBe(50);
});

test('completion for period on specific weekdays only expects the scheduled days', function () {
    // Mon/Wed/Fri between Jul 13 and Jul 19 → 3 scheduled, 2 completed.
    $habit = Habit::factory()->specificWeekdays([1, 3, 5])->create();
    createDays($habit, [
        '2026-07-13' => [],
        '2026-07-15' => [],
    ]);

    $percent = $habit->completionForPeriod(
        Carbon::parse('2026-07-13'),
        Carbon::parse('2026-07-19'),
    );

    expect($percent)->toBe(67);
});

test('completion for period on times per week pro-rates the weekly quota', function () {
    // Quota 3 over 14 days → 6 expected; 3 recorded days → 50%.
    $habit = Habit::factory()->timesPerWeek(3)->create();
    createDays($habit, [
        '2026-07-10' => [],
        '2026-07-14' => ['completed' => false, 'completion_percent' => 60],
        '2026-07-20' => [],
    ]);

    $percent = $habit->completionForPeriod(
        Carbon::parse('2026-07-09'),
        Carbon::parse('2026-07-22'),
    );

    expect($percent)->toBe(50);
});

test('the habit detail returns the tolerant streak and the 8-week calendar of marks', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->daily()->create(['created_at' => '2026-07-20 12:00:00']);
    createDays($habit, [
        '2026-07-21' => ['completed' => false, 'completion_percent' => 50],
        '2026-07-22' => ['completion_percent' => 120],
    ]);

    $response = $this->actingAs($user)->get("/habits/{$habit->id}", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'habits/show');

    $marks = collect($response->json('props.habit.calendar'))->flatMap(fn (array $week) => $week['days'])->pluck('mark', 'date');

    expect($response->json('props.habit.streak.current'))->toBe(1)
        ->and($response->json('props.habit.streak.best'))->toBe(1)
        ->and($response->json('props.habit.calendar'))->toHaveCount(8)
        ->and($marks['2026-07-21'])->toBe('r')
        ->and($marks['2026-07-22'])->toBe('d')
        ->and($marks)->not->toHaveKey('2026-07-23');
});

test('a user cannot view another user\'s habit detail', function () {
    $stranger = User::factory()->create();
    $habit = Habit::factory()->create();

    $response = $this->actingAs($stranger)->get("/habits/{$habit->id}");

    $response->assertForbidden();
});
