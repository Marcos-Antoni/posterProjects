<?php

/*
| Phase 4 acceptance (independent tester), round 2 — the coordinator's
| no-debt rules (R11/R12): (a) effective-dated schedules (editing never
| rewrites the past), (b) the creation day can not be missed, (c) the first
| partial week's quota is ceil(N × remaining days / 7). Attacked across the
| UTC-6 midnight, repeated and reverted edits, mid-week weekly edits, and
| checked for consistency between streak, votes, marks and the API.
| 2026-09-27 is a Sunday; 2026-09-28 a Monday.
*/

use App\Models\Habit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/support.php';

/**
 * PATCH the full web form with a new schedule.
 *
 * @param  array<string, mixed>  $schedule
 */
function p4aReschedule(Habit $habit, array $schedule): void
{
    test()->actingAs($habit->user)->patch("/habits/{$habit->id}", [
        'name' => $habit->name,
        'habit_type' => 'yes_no',
        'two_minute_version' => (string) $habit->two_minute_version,
        ...$schedule,
    ])->assertRedirect()->assertSessionHasNoErrors();
}

/**
 * @return list<string>
 */
function p4aWeekdaysOnly(string $from, string $to, array $isoDays = [1, 2, 3, 4, 5]): array
{
    return array_values(array_filter(p4aRange($from, $to), fn (string $d): bool => in_array((int) date('N', strtotime($d)), $isoDays, true)));
}

test('(a) editing the schedule twice the same day keeps the ORIGINAL past schedule', function () {
    p4aAt('2026-09-28', '09:00');
    $habit = p4aHabit('2026-09-07', [], fn ($f) => $f->specificWeekdays([1, 2, 3, 4, 5]));
    p4aDays($habit, p4aWeekdaysOnly('2026-09-07', '2026-09-25'));
    $before = $habit->history()->streak()->current;

    p4aReschedule($habit, ['recurrence_type' => 'daily']);
    p4aReschedule($habit->fresh(), ['recurrence_type' => 'times_per_week', 'times_per_week' => 4]);

    $habit = $habit->fresh();

    expect($habit->schedulePeriods()->count())->toBe(1)
        ->and($habit->schedulePeriods()->first()->recurrence_type->value)->toBe('specific_weekdays')
        ->and($habit->history()->streak()->current)->toBe($before)
        ->and($before)->toBe(15);
});

test('(a) edit then revert the same day leaves the history exactly as before', function () {
    p4aAt('2026-09-28', '09:00');
    $habit = p4aHabit('2026-09-07', [], fn ($f) => $f->specificWeekdays([1, 3, 5]));
    p4aDays($habit, ['2026-09-07', '2026-09-09', '2026-09-11', '2026-09-14', '2026-09-18', '2026-09-21', '2026-09-23', '2026-09-25']);
    $streak = $habit->history()->streak();
    $votes = $habit->history()->votes(30);

    p4aReschedule($habit, ['recurrence_type' => 'daily']);
    p4aReschedule($habit->fresh(), ['recurrence_type' => 'specific_weekdays', 'weekdays' => [1, 3, 5]]);

    $after = $habit->fresh()->history();

    expect($after->streak())->toEqual($streak)
        ->and($after->votes(30))->toEqual($votes);
});

test('(a) edit then revert on a LATER day: the in-between period keeps its own schedule', function () {
    p4aAt('2026-09-21', '09:00'); // Monday
    $habit = p4aHabit('2026-09-07', [], fn ($f) => $f->specificWeekdays([1, 3, 5]));
    p4aDays($habit, ['2026-09-07', '2026-09-09', '2026-09-11', '2026-09-14', '2026-09-16', '2026-09-18']);
    p4aReschedule($habit, ['recurrence_type' => 'daily']);

    // daily 21–24 all done, then revert on Friday 25
    p4aDays($habit, p4aRange('2026-09-21', '2026-09-24'));
    p4aAt('2026-09-25', '09:00');
    p4aReschedule($habit->fresh(), ['recurrence_type' => 'specific_weekdays', 'weekdays' => [1, 3, 5]]);

    p4aAt('2026-09-28', '09:00');
    p4aDay($habit, '2026-09-25');
    $history = $habit->fresh()->history();

    // 6 M/W/F + 4 daily + Fri 25 = 11, no misses anywhere (Sat/Sun rest again)
    expect($habit->fresh()->schedulePeriods()->count())->toBe(2)
        ->and($history->streak()->current)->toBe(11)
        ->and($history->streak()->state->value)->toBe('ok');
});

test('(a) UTC-6 midnight: an edit at 23:30 UTC-6 (next day in UTC) closes the old schedule on the UTC-6 yesterday', function () {
    p4aAt('2026-09-27', '23:30'); // 05:30 UTC on the 28th
    $habit = p4aHabit('2026-09-07', [], fn ($f) => $f->specificWeekdays([1, 2, 3, 4, 5]));
    p4aDays($habit, p4aWeekdaysOnly('2026-09-07', '2026-09-25'));

    p4aReschedule($habit, ['recurrence_type' => 'daily']);

    expect($habit->fresh()->schedulePeriods()->sole()->valid_until->toDateString())->toBe('2026-09-26');

    // Sunday 27 is the first daily day and still pending → not a miss; Sat 26 was a rest day.
    expect($habit->fresh()->history()->streak()->current)->toBe(15)
        ->and($habit->fresh()->history()->streak()->state->value)->toBe('ok');
});

test('(a) UTC-6 midnight: an edit at 00:10 UTC-6 closes the old schedule on the previous UTC-6 day', function () {
    p4aAt('2026-09-28', '00:10');
    $habit = p4aHabit('2026-09-07', [], fn ($f) => $f->specificWeekdays([1, 2, 3, 4, 5]));

    p4aReschedule($habit, ['recurrence_type' => 'daily']);

    expect($habit->fresh()->schedulePeriods()->sole()->valid_until->toDateString())->toBe('2026-09-27');
});

test('(a) weekly habit edited mid-week to daily: the partial week is pro-rated, days after count daily', function () {
    p4aAt('2026-09-23', '09:00'); // Wednesday
    $habit = p4aHabit('2026-09-07', [], fn ($f) => $f->timesPerWeek(3));
    p4aDays($habit, ['2026-09-07', '2026-09-09', '2026-09-11', '2026-09-14', '2026-09-16', '2026-09-18', '2026-09-21']);

    p4aReschedule($habit, ['recurrence_type' => 'daily']);
    p4aDays($habit, ['2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26']);
    p4aAt('2026-09-27', '09:00');

    $history = $habit->fresh()->history();

    // weeks 7–13, 14–20 met; Mon–Tue 21–22 partial (quota ceil(3·2/7)=1) met by Mon 21; daily 23–26 → 2 + 1 + 4 = 7
    expect($history->streak()->current)->toBe(7)
        ->and($history->streak()->state->value)->toBe('ok');
});

test('(a) daily habit edited mid-week to 3×/week: Mon–Tue keep daily rules, the rest of the week has a pro-rated quota', function () {
    p4aAt('2026-09-23', '09:00'); // Wednesday
    $habit = p4aHabit('2026-09-14');
    p4aDays($habit, p4aRange('2026-09-14', '2026-09-22'));

    p4aReschedule($habit, ['recurrence_type' => 'times_per_week', 'times_per_week' => 3]);

    // Wed–Sun = 5 days → quota ceil(3·5/7) = 3
    p4aDays($habit, ['2026-09-24', '2026-09-26', '2026-09-27']);
    p4aAt('2026-09-28', '09:00');

    $history = $habit->fresh()->history();

    expect($history->streak()->current)->toBe(10)
        ->and($history->streak()->state->value)->toBe('ok');
});

test('(a) identity votes follow the schedule in force each day (no retroactive opportunities)', function () {
    p4aAt('2026-09-24', '09:00'); // Thursday
    $habit = p4aHabit('2026-09-01', ['identity_statement' => 'me muevo'], fn ($f) => $f->specificWeekdays([1, 3, 5]));
    p4aDays($habit, ['2026-09-21', '2026-09-23']);
    p4aReschedule($habit, ['recurrence_type' => 'daily']);
    p4aDays($habit, ['2026-09-24', '2026-09-25', '2026-09-26']);
    p4aAt('2026-09-27', '09:00');

    // window 21–27: M/W/F schedule until Wed 23 → 21, 23 (22 not scheduled); daily 24–26 + today pending → 5 of 5
    $votes = $habit->fresh()->history()->votes(7);

    expect($votes->cast)->toBe(5)->and($votes->possible)->toBe(5);
});

test('(a) the API today list is unchanged in shape and uses the schedule in force today', function () {
    p4aAt('2026-09-27', '09:00'); // Sunday
    $habit = p4aHabit('2026-09-07', [], fn ($f) => $f->specificWeekdays([1, 2, 3, 4, 5]));
    p4aDays($habit, p4aWeekdaysOnly('2026-09-07', '2026-09-25'));
    $headers = p4aBearer($habit->user);

    expect($this->getJson('/api/v1/habits/today', $headers)->json('data'))->toBe([]);

    p4aReschedule($habit, ['recurrence_type' => 'daily']);
    app('auth')->forgetGuards();

    $element = $this->getJson('/api/v1/habits/today', $headers)->assertOk()->json('data.0');

    expect(array_keys($element))->toHaveCount(17)
        ->and($element)->toMatchArray(['id' => $habit->id, 'date' => '2026-09-27', 'streak_current' => 15, 'streak_state' => 'ok', 'week_recorded_days' => null, 'times_per_week' => null]);
});

test('(b) a habit created at 23:59 UTC-6 and not done that minute is not at risk the next day', function () {
    p4aAt('2026-09-26', '23:59');
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->create(['created_at' => now()]);

    p4aAt('2026-09-27', '08:00');

    $this->getJson('/api/v1/habits/today', p4aBearer($user))->assertOk()
        ->assertJsonPath('data.0.streak_state', 'ok')
        ->assertJsonPath('data.0.streak_current', 0);

    expect($habit->fresh()->history()->startDate()->toDateString())->toBe('2026-09-26');
});

test('(b) the creation day counts when it WAS done, and the next day is a real opportunity', function () {
    p4aAt('2026-09-28', '09:00');
    $habit = p4aHabit('2026-09-25');
    p4aDay($habit, '2026-09-25');

    $streak = $habit->history()->streak();

    // 25 done, 26 and 27 missed → break (creation-day rule never hides later misses)
    expect($streak->current)->toBe(0)
        ->and($streak->best)->toBe(1)
        ->and($streak->state->value)->toBe('restart');
});

test('(b) only the creation day is exempt: created then missed the next day is at_risk', function () {
    p4aAt('2026-09-28', '09:00');
    $habit = p4aHabit('2026-09-26');

    expect($habit->history()->streak()->state->value)->toBe('at_risk')
        ->and($habit->history()->votes(7)->possible)->toBe(1);
});

test('(c) first partial week quota is ceil(N × remaining days / 7)', function (string $created, int $times, array $done, string $state) {
    p4aAt('2026-09-29', '09:00'); // Tuesday of the next week
    $habit = p4aHabit($created, [], fn ($f) => $f->timesPerWeek($times));
    p4aDays($habit, $done);

    expect($habit->history()->streak()->state->value)->toBe($state);
})->with([
    'Fri, 3×: 3 days → quota 2, 2 done' => ['2026-09-25', 3, ['2026-09-25', '2026-09-27'], 'ok'],
    'Fri, 3×: 3 days → quota 2, 1 done' => ['2026-09-25', 3, ['2026-09-26'], 'at_risk'],
    'Sun, 5×: 1 day → quota 1, 1 done' => ['2026-09-27', 5, ['2026-09-27'], 'ok'],
    'Wed, 7×: 5 days → quota 5, 4 done' => ['2026-09-23', 7, ['2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26'], 'at_risk'],
]);

test('P4-T1: web undo of a 2-minute-only day leaves no row; a day with an amount keeps its row', function () {
    p4aAt('2026-09-27', '09:00');
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], fn ($f) => $f->quantitative('páginas', 5), $user);

    $this->actingAs($user)->post("/habits/{$habit->id}/two-minute");
    $this->actingAs($user)->delete("/habits/{$habit->id}/two-minute");

    expect($habit->days()->count())->toBe(0);

    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 2]);
    $this->actingAs($user)->post("/habits/{$habit->id}/two-minute");
    $this->actingAs($user)->delete("/habits/{$habit->id}/two-minute");

    expect($habit->days()->sole())->accumulated_amount->toBe(2)->two_minute_logged->toBeFalse();
});

test('P4-T1: week_recorded_days is the same number on web and API', function () {
    p4aAt('2026-09-27', '09:00');
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], fn ($f) => $f->timesPerWeek(3), $user);
    p4aDay($habit, '2026-09-22');
    p4aDay($habit, '2026-09-24', 'two');

    $web = $this->actingAs($user)->get('/habits')->viewData('page')['props']['habits'][0]['week_recorded_days'] ?? null;
    app('auth')->forgetGuards();
    $api = $this->getJson('/api/v1/habits/today', p4aBearer($user))->json('data.0.week_recorded_days');

    expect($api)->toBe(2)->and($web)->toBe($api);
});

test('migration 400002 rolls back and re-applies cleanly', function () {
    expect(Schema::hasTable('habit_schedule_periods'))->toBeTrue();

    Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_09_28_400002_create_habit_schedule_periods_table.php', '--force' => true]);
    expect(Schema::hasTable('habit_schedule_periods'))->toBeFalse();

    Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_28_400002_create_habit_schedule_periods_table.php', '--force' => true]);
    expect(Schema::hasTable('habit_schedule_periods'))->toBeTrue();
});
