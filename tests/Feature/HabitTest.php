<?php

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\HabitEntry;
use App\Models\User;
use Illuminate\Database\QueryException;

test('habit persists with expected attributes and belongs to its user', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create([
        'name' => 'Read every day',
    ]);

    expect($habit)->toBeInstanceOf(Habit::class)
        ->and($habit->user_id)->toBe($user->id)
        ->and($habit->name)->toBe('Read every day')
        ->and($habit->unit)->toBe('pages')
        ->and($habit->daily_target)->toBe(20)
        ->and($habit->user->id)->toBe($user->id);

    $this->assertModelExists($habit);
});

test('habit type and recurrence type are cast to their backed enums', function () {
    $habit = Habit::factory()->quantitative()->timesPerWeek(3)->create();

    $habit->refresh();

    expect($habit->habit_type)->toBe(HabitType::Quantitative)
        ->and($habit->recurrence_type)->toBe(RecurrenceType::TimesPerWeek)
        ->and($habit->times_per_week)->toBe(3);
});

test('weekdays are cast to an array of iso weekday numbers', function () {
    $habit = Habit::factory()->specificWeekdays([1, 3, 5])->create();

    $habit->refresh();

    expect($habit->weekdays)->toBe([1, 3, 5])
        ->and($habit->recurrence_type)->toBe(RecurrenceType::SpecificWeekdays);
});

test('unit, daily_target, weekdays, times_per_week, planned_time and archived_at are nullable', function () {
    $habit = Habit::factory()->create();

    expect($habit->unit)->toBeNull()
        ->and($habit->daily_target)->toBeNull()
        ->and($habit->weekdays)->toBeNull()
        ->and($habit->times_per_week)->toBeNull()
        ->and($habit->planned_time)->toBeNull()
        ->and($habit->archived_at)->toBeNull();

    $this->assertModelExists($habit);
});

test('archived factory state sets archived_at', function () {
    $habit = Habit::factory()->archived()->create();

    expect($habit->archived_at)->not->toBeNull();
});

test('planned time factory state persists a time of day', function () {
    $habit = Habit::factory()->plannedAt('07:30:00')->create();

    $habit->refresh();

    expect($habit->planned_time)->toBe('07:30:00');
});

test('a user exposes their habits through the habits relationship', function () {
    $user = User::factory()->create();
    Habit::factory()->for($user)->count(2)->create();
    Habit::factory()->create();

    expect($user->habits)->toHaveCount(2);
});

test('habit entry persists with its amount and logged_at timestamp', function () {
    $habit = Habit::factory()->create();
    $entry = HabitEntry::factory()->for($habit)->create([
        'amount' => 5,
        'logged_at' => '2026-07-10 14:30:00',
    ]);

    $entry->refresh();

    expect($entry->habit_id)->toBe($habit->id)
        ->and($entry->amount)->toBe(5)
        ->and($entry->logged_at->toDateTimeString())->toBe('2026-07-10 14:30:00')
        ->and($entry->habit->id)->toBe($habit->id);
});

test('a habit exposes its entries and days through relationships', function () {
    $habit = Habit::factory()->create();
    HabitEntry::factory()->for($habit)->count(3)->create();
    HabitDay::factory()->for($habit)->count(2)->create();

    expect($habit->entries)->toHaveCount(3)
        ->and($habit->days)->toHaveCount(2);
});

test('habit day persists the accumulated amount and the real completion percent', function () {
    $habit = Habit::factory()->quantitative('pages', 20)->create();
    $day = HabitDay::factory()->for($habit)->create([
        'entry_date' => '2026-07-10',
        'accumulated_amount' => 24,
        'completion_percent' => 120,
        'completed' => true,
        'planned_delta_minutes' => -15,
    ]);

    $day->refresh();

    expect($day->entry_date->toDateString())->toBe('2026-07-10')
        ->and($day->accumulated_amount)->toBe(24)
        ->and($day->completion_percent)->toBe(120)
        ->and($day->completed)->toBeTrue()
        ->and($day->planned_delta_minutes)->toBe(-15);
});

test('habit day entry_date must be unique per habit', function () {
    $habit = Habit::factory()->create();
    HabitDay::factory()->for($habit)->create(['entry_date' => '2026-07-10']);

    expect(fn () => HabitDay::factory()->for($habit)->create(['entry_date' => '2026-07-10']))
        ->toThrow(QueryException::class);
});

test('the same entry_date can exist across different habits', function () {
    $dayOne = HabitDay::factory()->create(['entry_date' => '2026-07-10']);
    $dayTwo = HabitDay::factory()->create(['entry_date' => '2026-07-10']);

    expect($dayOne->entry_date->toDateString())->toBe('2026-07-10')
        ->and($dayTwo->entry_date->toDateString())->toBe('2026-07-10');
});

test('factory type and recurrence states produce consistent columns', function (string $state, HabitType $expectedType) {
    $habit = Habit::factory()->{$state}()->create();

    expect($habit->habit_type)->toBe($expectedType);
})->with([
    'yesNo' => ['yesNo', HabitType::YesNo],
    'quantitative' => ['quantitative', HabitType::Quantitative],
]);
