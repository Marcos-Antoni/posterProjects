<?php

use App\Actions\Habits\UpdateHabit;
use App\Actions\Support\Actor;
use App\Enums\StreakState;
use App\Http\Resources\HabitPresenter;
use App\Models\Habit;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/*
| Round 2 — "no debt, never back to zero" (requirements R11/R12): a schedule
| edit never rewrites the past, the creation day can not be missed, a partial
| first week of an N-times-per-week habit is pro-rated to ceil(N × days / 7),
| week_recorded_days only counts real records, and vote rows have one mark
| per opportunity. Today is Sunday 2026-09-27 (UTC-6) unless stated.
*/

beforeEach(fn () => p4Today('2026-09-27'));

/**
 * @return array<string, mixed>
 */
function p4UpdatePayload(Habit $habit, array $overrides = []): array
{
    return [
        'name' => $habit->name,
        'habit_type' => 'yes_no',
        'two_minute_version' => (string) $habit->two_minute_version,
        'recurrence_type' => $habit->recurrence_type->value,
        'weekdays' => $habit->weekdays,
        'times_per_week' => $habit->times_per_week,
        ...$overrides,
    ];
}

test('a schedule change applies from today on: past rest days never become misses', function () {
    $habit = p4Habit('2026-09-07', state: fn ($f) => $f->specificWeekdays([1, 2, 3, 4, 5]));
    foreach (['2026-09-07', '2026-09-14', '2026-09-21'] as $monday) {
        p4Days($habit, $monday, date('Y-m-d', strtotime("{$monday} +4 days")));
    }

    expect($habit->history()->streak()->current)->toBe(15);

    app(UpdateHabit::class)(Actor::ownerWeb($habit->user), $habit, p4UpdatePayload($habit, ['recurrence_type' => 'daily']));

    $habit = $habit->fresh();
    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(15)
        ->and($streak->state)->toBe(StreakState::Ok)
        ->and($habit->schedulePeriods()->count())->toBe(1)
        ->and($habit->schedulePeriods()->sole()->valid_until->toDateString())->toBe('2026-09-26');

    // From today on the new daily schedule applies: missing Sunday and Monday breaks.
    p4Today('2026-09-29');

    expect($habit->fresh()->history()->streak()->state)->toBe(StreakState::Restart);
});

test('the past is evaluated with the schedule in force then, also for votes and marks', function () {
    $habit = p4Habit('2026-09-14', ['identity_statement' => 'Soy alguien que se mueve'], fn ($f) => $f->specificWeekdays([2, 4]));
    p4Day($habit, '2026-09-22');
    p4Day($habit, '2026-09-24');

    app(UpdateHabit::class)(Actor::ownerWeb($habit->user), $habit, p4UpdatePayload($habit, ['recurrence_type' => 'daily', 'weekdays' => null]));
    $habit = $habit->fresh();

    $marks = collect($habit->history()->marks('2026-09-21', '2026-09-27'))->pluck('mark', 'date')->all();

    expect($marks['2026-09-21'])->toBe('o')
        ->and($marks['2026-09-23'])->toBe('o')
        ->and($marks['2026-09-27'])->toBe('p')
        ->and($habit->history()->votes(7)->cast)->toBe(2)
        ->and($habit->history()->votes(7)->possible)->toBe(2);
});

test('changing the schedule twice the same day keeps only the schedule that governed the past', function () {
    $habit = p4Habit('2026-09-01', state: fn ($f) => $f->specificWeekdays([1]));

    app(UpdateHabit::class)(Actor::ownerWeb($habit->user), $habit, p4UpdatePayload($habit, ['recurrence_type' => 'daily', 'weekdays' => null]));
    $habit = $habit->fresh();
    app(UpdateHabit::class)(Actor::ownerWeb($habit->user), $habit, p4UpdatePayload($habit, ['recurrence_type' => 'times_per_week', 'times_per_week' => 3]));

    $period = $habit->fresh()->schedulePeriods()->sole();

    expect($period->recurrence_type->value)->toBe('specific_weekdays')
        ->and($period->weekdays)->toBe([1]);
});

test('editing a habit created today, or without touching the schedule, stores no period', function () {
    $new = p4Habit('2026-09-27');
    app(UpdateHabit::class)(Actor::ownerWeb($new->user), $new, p4UpdatePayload($new, ['recurrence_type' => 'times_per_week', 'times_per_week' => 2]));

    $old = p4Habit('2026-09-01');
    app(UpdateHabit::class)(Actor::ownerWeb($old->user), $old, p4UpdatePayload($old, ['name' => 'Otro nombre']));

    expect($new->schedulePeriods()->count() + $old->schedulePeriods()->count())->toBe(0);
});

test('the creation day can not be missed; it counts only when shown up that day', function () {
    $habit = p4Habit('2026-09-25');
    p4Day($habit, '2026-09-26');

    expect($habit->history()->streak()->state)->toBe(StreakState::Ok)
        ->and($habit->history()->streak()->current)->toBe(1)
        ->and($habit->history()->votes(7)->possible)->toBe(1);

    $shownOnDayOne = p4Habit('2026-09-25');
    p4Day($shownOnDayOne, '2026-09-25');

    expect($shownOnDayOne->history()->votes(7)->possible)->toBe(2)
        ->and($shownOnDayOne->history()->streak()->state)->toBe(StreakState::AtRisk);
});

test('a first partial week of N times per week is pro-rated to ceil(N × remaining / 7)', function (string $created, int $times, array $done, int $current) {
    p4Today('2026-09-28'); // Monday: the week of Sep 21 is closed
    $habit = p4Habit($created, state: fn ($f) => $f->timesPerWeek($times));
    foreach ($done as $date) {
        p4Day($habit, $date);
    }

    expect($habit->history()->streak()->current)->toBe($current);
})->with([
    // Created Thursday 24: 4 days left → ceil(5×4/7) = 3 (min(5, 4) would demand 4).
    'thursday, 5×/week, 3 done' => ['2026-09-24', 5, ['2026-09-24', '2026-09-25', '2026-09-26'], 1],
    // Created Saturday 26: 2 days left → ceil(3×2/7) = 1.
    'saturday, 3×/week, 1 done' => ['2026-09-26', 3, ['2026-09-27'], 1],
    // Created Wednesday 23: 5 days left → ceil(3×5/7) = 3; 2 done is under quota (a single miss, not a break).
    'wednesday, 3×/week, 2 done' => ['2026-09-23', 3, ['2026-09-23', '2026-09-24'], 0],
]);

test('week_recorded_days counts only days with a real record, on web and API alike', function () {
    $user = User::factory()->create();
    $habit = p4Habit('2026-09-01', ['user_id' => $user->id], fn ($f) => $f->timesPerWeek(3));
    p4Day($habit, '2026-09-22');

    $this->actingAs($user)->post(route('habits.two-minute.store', $habit))->assertRedirect();
    $this->actingAs($user)->delete(route('habits.two-minute.destroy', $habit))->assertRedirect();

    $this->actingAs($user)->get(route('habits.today'))
        ->assertInertia(fn (Assert $page) => $page->where('habits.0.week_recorded_days', 1));

    app('auth')->forgetGuards();

    $this->getJson(route('api.v1.habits.today'), mosMobileHeaders($user))
        ->assertJsonPath('data.0.week_recorded_days', 1);
});

test('undoing the 2-minute version keeps the day row when an amount was recorded', function () {
    $user = User::factory()->create();
    $habit = p4Habit('2026-09-01', ['user_id' => $user->id], fn ($f) => $f->quantitative('páginas', 5));
    $habit->recordEntry(2);

    $this->actingAs($user)->post(route('habits.two-minute.store', $habit));
    $this->actingAs($user)->delete(route('habits.two-minute.destroy', $habit));

    expect($habit->days()->sole()->accumulated_amount)->toBe(2)
        ->and($habit->days()->sole()->two_minute_logged)->toBeFalse();
});

test('vote rows have one mark per opportunity, even when two habits share a day', function () {
    $user = User::factory()->create();
    $a = p4Habit('2026-09-01', ['user_id' => $user->id, 'identity_statement' => 'Soy alguien que se mueve']);
    $b = p4Habit('2026-09-01', ['user_id' => $user->id, 'identity_statement' => 'Soy alguien que se mueve'], fn ($f) => $f->specificWeekdays([2, 4]));
    p4Days($a, '2026-09-21', '2026-09-26');
    p4Day($b, '2026-09-22'); // Thursday 24 missed while $a voted that day

    $items = HabitPresenter::voteItems([$a, $b], Habit::todayLocalDate());

    expect($items)->toHaveCount(8)
        ->and(collect($items)->where('mark', 'm')->pluck('date')->all())->toBe(['2026-09-24']);

    $this->actingAs($user)->get(route('habits.identity'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('identities.0.last7.cast', 7)
            ->where('identities.0.last7.possible', 8)
            ->has('identities.0.last7.items', 8)
            ->has('identities.0.last7.row', 8));
});

test('a restarted habit is never shown as zero: manage exposes the saved run and the best', function () {
    $user = User::factory()->create();
    $habit = p4Habit('2026-09-01', ['user_id' => $user->id]);
    p4Days($habit, '2026-09-15', '2026-09-24');

    $this->actingAs($user)->get(route('habits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('habits.0.streak.state', 'restart')
            ->where('habits.0.streak.closed_run', 10)
            ->where('habits.0.streak.best', 10));
});

test('after more than two misses the saved run is still the last one that closed', function () {
    $habit = p4Habit('2026-09-01');
    p4Days($habit, '2026-09-10', '2026-09-20');

    expect($habit->history()->streak()->closedRun)->toBe(11);
});
