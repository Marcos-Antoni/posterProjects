<?php

use App\Enums\StreakState;
use App\Models\Habit;

require_once __DIR__.'/helpers.php';

/*
| Task 4.3 — tolerant streak "never miss twice" (habits spec, design D5):
| a run survives a single missed opportunity, breaks at two consecutive
| misses, a pending today never counts, best streak remembers the longest
| run. Days are UTC-6. Today is Sunday 2026-09-27 unless stated.
*/

beforeEach(fn () => p4Today('2026-09-27'));

test('a habit with no history reports zero and ok', function () {
    $habit = p4Habit('2026-09-27');

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(0)
        ->and($streak->best)->toBe(0)
        ->and($streak->state)->toBe(StreakState::Ok);
});

test('a single miss does not break a daily streak and reports at_risk', function () {
    $habit = p4Habit('2026-09-01');
    p4Days($habit, '2026-09-16', '2026-09-25');

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(10)
        ->and($streak->state)->toBe(StreakState::AtRisk);
});

test('two consecutive misses break the streak, best remembers the run', function () {
    $habit = p4Habit('2026-09-01');
    p4Days($habit, '2026-09-15', '2026-09-24');

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(0)
        ->and($streak->best)->toBe(10)
        ->and($streak->state)->toBe(StreakState::Restart)
        ->and($streak->closedRun)->toBe(10);
});

test('a miss followed by a show-up keeps the run without counting the missed day', function () {
    $habit = p4Habit('2026-09-20');
    p4Days($habit, '2026-09-20', '2026-09-22');
    p4Days($habit, '2026-09-24', '2026-09-26');

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(6)
        ->and($streak->state)->toBe(StreakState::Ok);
});

test('a pending today is not a miss and a shown-up today counts', function () {
    $habit = p4Habit('2026-09-24');
    p4Days($habit, '2026-09-24', '2026-09-26');

    expect($habit->history()->streak()->current)->toBe(3)
        ->and($habit->history()->streak()->state)->toBe(StreakState::Ok);

    p4Day($habit, '2026-09-27');

    expect($habit->fresh()->history()->streak()->current)->toBe(4);
});

test('the 2-minute version counts as shown-up, a partial quantitative day does not', function () {
    $habit = p4Habit('2026-09-22', state: fn ($f) => $f->quantitative('páginas', 30));
    p4Day($habit, '2026-09-22');
    p4Day($habit, '2026-09-23', 'two');
    p4Day($habit, '2026-09-24', 'partial');
    p4Day($habit, '2026-09-25', 'partial');
    p4Day($habit, '2026-09-26', 'two');

    $streak = $habit->history()->streak();

    // 22 done, 23 two, then two misses (24, 25) close the run of 2; 26 starts a new one.
    expect($streak->current)->toBe(1)
        ->and($streak->best)->toBe(2)
        ->and($streak->twoMinuteReturns)->toBe(1)
        ->and($streak->state)->toBe(StreakState::Ok);
});

test('specific weekdays only count scheduled days', function () {
    // Tuesday to Friday; unscheduled weekends never count as misses.
    $habit = p4Habit('2026-09-01', state: fn ($f) => $f->specificWeekdays([2, 3, 4, 5]));
    p4Days($habit, '2026-09-15', '2026-09-18'); // Tue–Fri
    p4Day($habit, '2026-09-22'); // Tue
    p4Day($habit, '2026-09-23'); // Wed
    // Thu 24 missed, Fri 25 two-minute, weekend unscheduled, today Sunday unscheduled.
    p4Day($habit, '2026-09-25', 'two');

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(7)
        ->and($streak->state)->toBe(StreakState::Ok);
});

test('specific weekdays break only after two scheduled misses in a row', function () {
    $habit = p4Habit('2026-09-01', state: fn ($f) => $f->specificWeekdays([1, 4])); // Mon, Thu
    p4Day($habit, '2026-09-14'); // Mon
    p4Day($habit, '2026-09-17'); // Thu
    // Mon 21 missed, Thu 24 missed.

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(0)
        ->and($streak->best)->toBe(2)
        ->and($streak->state)->toBe(StreakState::Restart);
});

test('times per week counts closed weeks and needs two under-quota weeks to break', function () {
    $habit = p4Habit('2026-08-31', state: fn ($f) => $f->timesPerWeek(3));
    // Week of Aug 31: 3 days (ok). Week of Sep 7: 3 (ok). Week of Sep 14: 1 (under quota).
    p4Days($habit, '2026-08-31', '2026-09-02');
    p4Days($habit, '2026-09-07', '2026-09-09');
    p4Day($habit, '2026-09-14');
    // Week of Sep 21 (closed last Sunday? no: today is Sunday 27, the week is still in progress).
    p4Day($habit, '2026-09-21');

    $streak = $habit->history()->streak();

    expect($streak->current)->toBe(2)
        ->and($streak->state)->toBe(StreakState::AtRisk);

    // Next Monday the week of Sep 21 closes with 1 of 3: second consecutive under-quota week.
    p4Today('2026-09-28');

    $streak = $habit->fresh()->history()->streak();

    expect($streak->current)->toBe(0)
        ->and($streak->best)->toBe(2)
        ->and($streak->state)->toBe(StreakState::Restart);
});

test('times per week counts the in-progress week once its quota is reached', function () {
    $habit = p4Habit('2026-09-14', state: fn ($f) => $f->timesPerWeek(2));
    p4Days($habit, '2026-09-14', '2026-09-15');
    p4Days($habit, '2026-09-21', '2026-09-22');

    expect($habit->history()->streak()->current)->toBe(2)
        ->and($habit->history()->streak()->state)->toBe(StreakState::Ok);
});

test('best streak is the longest historical run under the tolerant rule', function () {
    $habit = p4Habit('2026-08-01');
    p4Days($habit, '2026-08-01', '2026-08-05');
    p4Days($habit, '2026-08-07', '2026-08-10'); // single gap on the 6th: run of 9
    p4Days($habit, '2026-09-20', '2026-09-26'); // current run of 7

    $streak = $habit->history()->streak();

    expect($streak->best)->toBe(9)
        ->and($streak->current)->toBe(7);
});

test('the day boundary is UTC-6, never UTC', function () {
    // 23:30 in Guatemala on Saturday 26 is already Sunday 27 in UTC.
    p4Today('2026-09-26', '23:30');
    $habit = p4Habit('2026-09-24');
    p4Days($habit, '2026-09-24', '2026-09-25');

    // Saturday is still pending (not a miss): the run is intact and ok.
    expect($habit->history()->streak()->state)->toBe(StreakState::Ok)
        ->and(Habit::todayLocalDate()->toDateString())->toBe('2026-09-26');
});

test('marks describe each day without debt: done, two, repaired gap, gap, pending, not scheduled', function () {
    $habit = p4Habit('2026-09-20', state: fn ($f) => $f->specificWeekdays([1, 2, 3, 4, 5, 6, 7]));
    p4Day($habit, '2026-09-20');
    p4Day($habit, '2026-09-22', 'two'); // 21 missed then repaired
    p4Day($habit, '2026-09-23');
    // 24, 25 missed: the run closes
    p4Day($habit, '2026-09-26');

    $marks = collect($habit->history()->marks('2026-09-20', '2026-09-27'))->pluck('mark', 'date')->all();

    expect($marks)->toBe([
        '2026-09-20' => 'd',
        '2026-09-21' => 'r',
        '2026-09-22' => 't',
        '2026-09-23' => 'd',
        '2026-09-24' => 'g',
        '2026-09-25' => 'g',
        '2026-09-26' => 'd',
        '2026-09-27' => 'p',
    ]);

    $closing = collect($habit->history()->marks('2026-09-20', '2026-09-27'))->where('closes', true)->pluck('date')->all();

    expect($closing)->toBe(['2026-09-25']);
});
