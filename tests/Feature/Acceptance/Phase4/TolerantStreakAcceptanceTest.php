<?php

/*
| Phase 4 acceptance (independent tester) — habits spec "Streaks Are
| Tolerant: Never Miss Twice" + design D5, attacked through the public read
| surfaces (the API today element, the manage screen props) with a frozen
| clock, including the UTC-6 midnight edge. 2026-09-27 is a Sunday.
*/

use App\Enums\StreakState;
use App\Models\Habit;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/support.php';

/**
 * The API today element of one habit (or null when absent).
 *
 * @return array<string, mixed>|null
 */
function p4aTodayElement(Habit $habit): ?array
{
    $response = test()->getJson('/api/v1/habits/today', p4aBearer($habit->user))->assertOk();

    return collect($response->json('data'))->firstWhere('id', $habit->id);
}

test('spec: 10 shown-up days then a miss yesterday → streak 10, at_risk, before any entry today', function () {
    p4aAt('2026-09-27', '07:00');
    $habit = p4aHabit('2026-09-01');
    p4aDays($habit, p4aRange('2026-09-16', '2026-09-25'));

    $element = p4aTodayElement($habit);

    expect($element['streak_current'])->toBe(10)
        ->and($element['streak_state'])->toBe('at_risk')
        ->and($element['shown_up'])->toBeFalse();
});

test('spec: two consecutive misses → 0 and restart; best keeps the run', function () {
    p4aAt('2026-09-27');
    $habit = p4aHabit('2026-09-01');
    p4aDays($habit, p4aRange('2026-09-15', '2026-09-24'));

    $streak = $habit->history()->streak();
    $element = p4aTodayElement($habit);

    expect($streak->current)->toBe(0)
        ->and($streak->best)->toBeGreaterThanOrEqual(10)
        ->and($element['streak_current'])->toBe(0)
        ->and($element['streak_state'])->toBe('restart');
});

test('a single miss repaired by a show-up continues the run without counting the missed day', function () {
    p4aAt('2026-09-27');
    $habit = p4aHabit('2026-09-20');
    p4aDays($habit, ['2026-09-20', '2026-09-21', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26']);

    expect(p4aTodayElement($habit))->toMatchArray(['streak_current' => 6, 'streak_state' => 'ok']);
});

test('alternating single misses never break the run', function () {
    p4aAt('2026-09-27');
    $habit = p4aHabit('2026-09-18');
    p4aDays($habit, ['2026-09-18', '2026-09-20', '2026-09-22', '2026-09-24', '2026-09-26']);

    expect($habit->history()->streak()->current)->toBe(5)
        ->and($habit->history()->streak()->state)->toBe(StreakState::Ok);
});

test('three misses then one show-up starts a new run of 1, best remembers the old one', function () {
    p4aAt('2026-09-27');
    $habit = p4aHabit('2026-09-10');
    p4aDays($habit, p4aRange('2026-09-10', '2026-09-22'));
    p4aDay($habit, '2026-09-26');

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(1)
        ->and($streak->best)->toBe(13)
        ->and($streak->state)->toBe(StreakState::Ok);
});

test('a pending today is not a miss even with yesterday missed: at_risk, not restart', function () {
    p4aAt('2026-09-27', '23:59');
    $habit = p4aHabit('2026-09-20');
    p4aDays($habit, p4aRange('2026-09-20', '2026-09-25'));

    expect(p4aTodayElement($habit))->toMatchArray(['streak_current' => 6, 'streak_state' => 'at_risk']);
});

test('UTC-6 midnight: at 23:30 UTC-6 (05:30 UTC next day) today is still the UTC-6 date', function () {
    p4aAt('2026-09-27', '23:30');
    $habit = p4aHabit('2026-09-20');
    p4aDays($habit, p4aRange('2026-09-20', '2026-09-26'));

    $element = p4aTodayElement($habit);
    expect($element['date'])->toBe('2026-09-27')
        ->and($element['streak_current'])->toBe(7)
        ->and($element['streak_state'])->toBe('ok');

    $this->postJson("/api/v1/habits/{$habit->id}/increment", [], p4aBearer($habit->user))->assertOk()
        ->assertJsonPath('data.date', '2026-09-27')
        ->assertJsonPath('data.streak_current', 8);

    expect($habit->days()->where('entry_date', '2026-09-27')->exists())->toBeTrue()
        ->and($habit->days()->where('entry_date', '2026-09-28')->exists())->toBeFalse();
});

test('UTC-6 midnight: right after 00:00 UTC-6 yesterday becomes a single miss, not a break', function () {
    p4aAt('2026-09-28', '00:05');
    $habit = p4aHabit('2026-09-20');
    p4aDays($habit, p4aRange('2026-09-20', '2026-09-26'));

    $element = p4aTodayElement($habit);

    expect($element['date'])->toBe('2026-09-28')
        ->and($element['streak_current'])->toBe(7)
        ->and($element['streak_state'])->toBe('at_risk');
});

test('UTC-6 anchoring: 20:00 UTC-6 is already the next day in UTC, but the habit day is not', function () {
    p4aAt('2026-09-27', '20:00');
    $habit = p4aHabit('2026-09-27');

    $this->postJson("/api/v1/habits/{$habit->id}/two-minute", [], p4aBearer($habit->user))->assertOk()
        ->assertJsonPath('data.date', '2026-09-27');

    expect($habit->days()->sole()->entry_date->toDateString())->toBe('2026-09-27');
});

test('days before the habit existed are not misses', function () {
    p4aAt('2026-09-27');
    $habit = p4aHabit('2026-09-25');
    p4aDay($habit, '2026-09-25');
    p4aDay($habit, '2026-09-26');

    expect(p4aTodayElement($habit))->toMatchArray(['streak_current' => 2, 'streak_state' => 'ok']);
});

test('a fresh habit with no history reports 0 and ok', function () {
    p4aAt('2026-09-27');
    $habit = p4aHabit('2026-09-27');

    expect(p4aTodayElement($habit))->toMatchArray(['streak_current' => 0, 'streak_state' => 'ok', 'shown_up' => false]);
});

test('a partial quantitative day is a miss; the 2-minute version is a show-up', function () {
    p4aAt('2026-09-27');
    $habit = p4aHabit('2026-09-22', state: fn ($f) => $f->quantitative('páginas', 30));
    p4aDay($habit, '2026-09-22');
    p4aDay($habit, '2026-09-23', 'partial');
    p4aDay($habit, '2026-09-24', 'partial');
    p4aDay($habit, '2026-09-25', 'two');
    p4aDay($habit, '2026-09-26', 'two');

    expect(p4aTodayElement($habit))->toMatchArray(['streak_current' => 2, 'streak_state' => 'ok']);
});

test('specific weekdays: unscheduled days never count, a missed scheduled day is only at_risk', function () {
    // Mon/Wed/Fri. Today Sunday 27 (unscheduled). Mon 21 done, Wed 23 missed, Fri 25 done.
    p4aAt('2026-09-27');
    $habit = p4aHabit('2026-09-14', state: fn ($f) => $f->specificWeekdays([1, 3, 5]));
    p4aDays($habit, ['2026-09-14', '2026-09-16', '2026-09-18', '2026-09-21', '2026-09-25']);

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(5)->and($streak->state)->toBe(StreakState::Ok);

    // Friday missed too → the last scheduled opportunity is a miss (Sat/Sun never count).
    $other = p4aHabit('2026-09-14', state: fn ($f) => $f->specificWeekdays([1, 3, 5]));
    p4aDays($other, ['2026-09-14', '2026-09-16', '2026-09-18', '2026-09-21', '2026-09-23']);

    expect($other->history()->streak()->current)->toBe(5)
        ->and($other->history()->streak()->state)->toBe(StreakState::AtRisk);
});

test('specific weekdays: two consecutive scheduled misses break even if unscheduled days lie between', function () {
    p4aAt('2026-09-27');
    $habit = p4aHabit('2026-09-14', state: fn ($f) => $f->specificWeekdays([1, 5]));
    p4aDays($habit, ['2026-09-14', '2026-09-18', '2026-09-21']);

    // Fri 18? done; Mon 21 done; Fri 25 missed... only one miss so far → at_risk.
    expect($habit->history()->streak()->state)->toBe(StreakState::AtRisk)
        ->and($habit->history()->streak()->current)->toBe(3);

    p4aAt('2026-09-29');
    // Mon 28 missed as well → two consecutive scheduled misses.
    expect($habit->fresh()->history()->streak()->current)->toBe(0)
        ->and($habit->fresh()->history()->streak()->state)->toBe(StreakState::Restart);
});

test('times per week: the week in progress never counts as a miss', function () {
    // 3×/week. Weeks Mon 14–Sun 20 met; current week Mon 21–Sun 27 only 1 day so far but today is Sunday.
    // Test on Wednesday 23 (week in progress).
    p4aAt('2026-09-23');
    $habit = p4aHabit('2026-09-14', state: fn ($f) => $f->timesPerWeek(3));
    p4aDays($habit, ['2026-09-14', '2026-09-16', '2026-09-18']);

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(1)
        ->and($streak->state)->toBe(StreakState::Ok);
});

test('spec: times per week needs two consecutive under-quota closed weeks to break', function () {
    p4aAt('2026-10-01'); // Thursday of week Mon 28 Sep – Sun 4 Oct
    $habit = p4aHabit('2026-09-07', state: fn ($f) => $f->timesPerWeek(3));
    // Weeks 7–13 and 14–20 met; week 21–27 closed with 2 (under quota).
    p4aDays($habit, ['2026-09-07', '2026-09-09', '2026-09-11', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-22', '2026-09-24']);

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(2)
        ->and($streak->state)->toBe(StreakState::AtRisk);

    // The week of 28 Sep closes under quota too → break (checked on Monday 5 Oct).
    p4aAt('2026-10-05');
    p4aDay($habit, '2026-09-29');

    $after = $habit->fresh()->history()->streak();

    expect($after->current)->toBe(0)
        ->and($after->best)->toBe(2)
        ->and($after->state)->toBe(StreakState::Restart);
});

test('times per week: a quota met mid-week counts for the in-progress week immediately', function () {
    p4aAt('2026-09-23'); // Wednesday
    $habit = p4aHabit('2026-09-14', state: fn ($f) => $f->timesPerWeek(2));
    p4aDays($habit, ['2026-09-14', '2026-09-15', '2026-09-21', '2026-09-22']);

    expect($habit->history()->streak()->current)->toBe(2);
});

test('times per week: a partial first week is pro-rated, not a miss because the habit did not exist yet', function () {
    // Created Saturday 19: only 2 days of that week existed; quota 3 → the first week can not be a miss when both days were done.
    p4aAt('2026-09-23');
    $habit = p4aHabit('2026-09-19', state: fn ($f) => $f->timesPerWeek(3));
    p4aDays($habit, ['2026-09-19', '2026-09-20']);

    expect($habit->history()->streak()->current)->toBe(1)
        ->and($habit->history()->streak()->state)->toBe(StreakState::Ok);
});

test('times per week: a habit created on a Sunday owes one day for that first week', function () {
    p4aAt('2026-09-23');
    $habit = p4aHabit('2026-09-20', state: fn ($f) => $f->timesPerWeek(3));
    p4aDay($habit, '2026-09-20');

    expect($habit->history()->streak()->current)->toBe(1)
        ->and($habit->history()->streak()->state)->toBe(StreakState::Ok);
});

test('the manage screen exposes the same tolerant streak as the API (one source of truth)', function () {
    p4aAt('2026-09-27');
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', user: $user);
    p4aDays($habit, p4aRange('2026-09-16', '2026-09-25'));

    $this->actingAs($user)->get('/habits/manage')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('habits.0.streak.current', 10)
        ->where('habits.0.streak.state', 'at_risk')
        ->etc());
});

test('best streak on a long history survives several breaks and counts the longest tolerant run', function () {
    p4aAt('2026-09-27');
    $habit = p4aHabit('2026-08-01');
    // run A: 1–5 Aug (5) · break 6–7 · run B: 8–20 with single misses on 12 and 16 (11 shown) · break 21–22 · run C: 23 Aug–26 Sep (35)
    p4aDays($habit, p4aRange('2026-08-01', '2026-08-05'));
    p4aDays($habit, array_values(array_diff(p4aRange('2026-08-08', '2026-08-20'), ['2026-08-12', '2026-08-16'])));
    p4aDays($habit, p4aRange('2026-08-23', '2026-09-26'));

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(35)
        ->and($streak->best)->toBe(35)
        ->and($streak->state)->toBe(StreakState::Ok);
});

test('no-debt: editing the schedule never rewrites past rest days as misses', function () {
    p4aAt('2026-09-27');
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-07', [], fn ($f) => $f->specificWeekdays([1, 2, 3, 4, 5]), $user);
    p4aDays($habit, array_values(array_filter(p4aRange('2026-09-07', '2026-09-25'), fn ($d) => (int) date('N', strtotime($d)) <= 5)));
    $before = $habit->history()->streak()->current;

    $this->actingAs($user)->patch("/habits/{$habit->id}", [
        'name' => $habit->name, 'habit_type' => 'yes_no', 'recurrence_type' => 'daily', 'two_minute_version' => $habit->two_minute_version,
    ])->assertRedirect();

    expect($before)->toBe(15)
        ->and($habit->fresh()->history()->streak()->current)->toBe($before);
});
