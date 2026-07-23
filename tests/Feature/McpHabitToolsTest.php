<?php

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Habits\ArchiveHabit;
use App\Mcp\Tools\Habits\CreateHabit;
use App\Mcp\Tools\Habits\ListHabits;
use App\Mcp\Tools\Habits\LogHabitEntry;
use App\Mcp\Tools\Habits\ShowHabit;
use App\Mcp\Tools\Habits\TodayHabits;
use App\Mcp\Tools\Habits\UnarchiveHabit;
use App\Mcp\Tools\Habits\UpdateHabit;
use App\Models\Habit;
use App\Models\User;
use Illuminate\Support\Carbon;

test('create-habit creates a yes/no daily habit', function () {
    $user = User::factory()->create();

    $response = PosterServer::actingAs($user)->tool(CreateHabit::class, [
        'name' => 'Meditate',
        'habit_type' => 'yes_no',
        'recurrence_type' => 'daily',
    ]);

    $response->assertOk()
        ->assertSee('Meditate')
        ->assertSee(route('habits.show', ['habit' => Habit::query()->where('name', 'Meditate')->firstOrFail()->id]));

    $habit = Habit::query()->where('user_id', $user->id)->firstOrFail();
    expect($habit->habit_type)->toBe(HabitType::YesNo)
        ->and($habit->recurrence_type)->toBe(RecurrenceType::Daily)
        ->and($habit->unit)->toBeNull()
        ->and($habit->daily_target)->toBeNull();
});

test('create-habit creates a quantitative habit with a unit and daily target', function () {
    $user = User::factory()->create();

    $response = PosterServer::actingAs($user)->tool(CreateHabit::class, [
        'name' => 'Read',
        'habit_type' => 'quantitative',
        'unit' => 'pages',
        'daily_target' => 20,
        'recurrence_type' => 'specific_weekdays',
        'weekdays' => [1, 3, 5],
    ]);

    $response->assertOk();

    $habit = Habit::query()->where('name', 'Read')->firstOrFail();
    expect($habit->habit_type)->toBe(HabitType::Quantitative)
        ->and($habit->unit)->toBe('pages')
        ->and($habit->daily_target)->toBe(20)
        ->and($habit->recurrence_type)->toBe(RecurrenceType::SpecificWeekdays)
        ->and($habit->weekdays)->toBe([1, 3, 5]);
});

test('update-habit switching from quantitative to yes/no clears unit and daily target', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create(['name' => 'Read']);

    $response = PosterServer::actingAs($user)->tool(UpdateHabit::class, [
        'habit_id' => $habit->id,
        'name' => 'Meditate',
        'habit_type' => 'yes_no',
        'recurrence_type' => 'times_per_week',
        'times_per_week' => 5,
    ]);

    $response->assertOk()->assertSee('Meditate');

    $habit->refresh();
    expect($habit->name)->toBe('Meditate')
        ->and($habit->habit_type)->toBe(HabitType::YesNo)
        ->and($habit->unit)->toBeNull()
        ->and($habit->daily_target)->toBeNull()
        ->and($habit->recurrence_type)->toBe(RecurrenceType::TimesPerWeek)
        ->and($habit->times_per_week)->toBe(5);
});

test('update-habit is not found for another user\'s habit', function () {
    $stranger = User::factory()->create();
    $habit = Habit::factory()->create(['name' => 'Private']);

    $response = PosterServer::actingAs($stranger)->tool(UpdateHabit::class, [
        'habit_id' => $habit->id,
        'name' => 'Hijacked',
        'habit_type' => 'yes_no',
        'recurrence_type' => 'daily',
    ]);

    $response->assertHasErrors(["Habit not found: {$habit->id}"]);
    expect($habit->refresh()->name)->toBe('Private');
});

test('archive-habit and unarchive-habit toggle archived_at', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->create();

    PosterServer::actingAs($user)->tool(ArchiveHabit::class, ['habit_id' => $habit->id])
        ->assertOk();

    expect($habit->refresh()->archived_at)->not->toBeNull();

    PosterServer::actingAs($user)->tool(UnarchiveHabit::class, ['habit_id' => $habit->id])
        ->assertOk();

    expect($habit->refresh()->archived_at)->toBeNull();
});

test('a user cannot archive or unarchive another user\'s habit', function () {
    $stranger = User::factory()->create();
    $habit = Habit::factory()->create();

    PosterServer::actingAs($stranger)->tool(ArchiveHabit::class, ['habit_id' => $habit->id])
        ->assertHasErrors(["Habit not found: {$habit->id}"]);

    expect($habit->refresh()->archived_at)->toBeNull();

    $archived = Habit::factory()->archived()->create();

    PosterServer::actingAs($stranger)->tool(UnarchiveHabit::class, ['habit_id' => $archived->id])
        ->assertHasErrors(["Habit not found: {$archived->id}"]);

    expect($archived->refresh()->archived_at)->not->toBeNull();
});

// Frozen "now": 2026-07-22 18:00 UTC == Wednesday 2026-07-22 12:00 UTC-6.
test('today-habits lists only habits scheduled for the current utc-6 day', function () {
    $this->travelTo(Carbon::parse('2026-07-22 18:00:00', 'UTC'));

    $user = User::factory()->create();
    Habit::factory()->for($user)->daily()->create(['name' => 'Daily']);
    Habit::factory()->for($user)->specificWeekdays([3])->create(['name' => 'Wednesdays']);
    Habit::factory()->for($user)->specificWeekdays([2, 4])->create(['name' => 'Not today']);
    Habit::factory()->for($user)->archived()->create(['name' => 'Archived']);
    Habit::factory()->daily()->create(['name' => "Someone else's"]);

    $response = PosterServer::actingAs($user)->tool(TodayHabits::class);

    $response->assertOk()
        ->assertSee(['"date":"2026-07-22"', 'Daily', 'Wednesdays'])
        ->assertDontSee(['Not today', 'Archived', "Someone else's"]);
});

test('list-habits returns every habit including archived ones', function () {
    $user = User::factory()->create();
    Habit::factory()->for($user)->create(['name' => 'Active']);
    Habit::factory()->for($user)->archived()->create(['name' => 'Archived']);
    Habit::factory()->create(['name' => 'Not mine']);

    $response = PosterServer::actingAs($user)->tool(ListHabits::class);

    $response->assertOk()
        ->assertSee('Active')
        ->assertSee('Archived')
        ->assertDontSee('Not mine');
});

test('log-habit-entry accumulates into the current utc-6 day and returns its state', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create();

    $response = PosterServer::actingAs($user)->tool(LogHabitEntry::class, [
        'habit_id' => $habit->id,
        'amount' => 5,
    ]);

    $response->assertOk();

    expect($habit->entries()->count())->toBe(1)
        ->and($habit->days()->firstOrFail()->accumulated_amount)->toBe(5);

    $response = PosterServer::actingAs($user)->tool(LogHabitEntry::class, [
        'habit_id' => $habit->id,
        'amount' => 15,
    ]);

    $response->assertOk();

    $day = $habit->days()->firstOrFail();
    expect($day->accumulated_amount)->toBe(20)
        ->and($day->completion_percent)->toBe(100)
        ->and($day->completed)->toBeTrue();
});

test('log-habit-entry rejects an archived habit, matching the web behavior', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->archived()->create();

    $response = PosterServer::actingAs($user)->tool(LogHabitEntry::class, [
        'habit_id' => $habit->id,
    ]);

    $response->assertHasErrors(['No podés registrar en un hábito archivado.']);
    expect($habit->entries()->count())->toBe(0);
});

test('log-habit-entry is not found for another user\'s habit', function () {
    $stranger = User::factory()->create();
    $habit = Habit::factory()->quantitative('pages', 20)->create();

    $response = PosterServer::actingAs($stranger)->tool(LogHabitEntry::class, [
        'habit_id' => $habit->id,
        'amount' => 5,
    ]);

    $response->assertHasErrors(["Habit not found: {$habit->id}"]);
    expect($habit->entries()->count())->toBe(0);
});

test('log-habit-entry at 23:30 utc-6 lands on the utc-6 day even though it is already the next utc day', function () {
    // 2026-07-11 05:30 UTC == 2026-07-10 23:30 in UTC-6.
    $this->travelTo(Carbon::parse('2026-07-11 05:30:00', 'UTC'));

    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create();

    $response = PosterServer::actingAs($user)->tool(LogHabitEntry::class, [
        'habit_id' => $habit->id,
        'amount' => 5,
    ]);

    $response->assertOk();

    $day = $habit->days()->firstOrFail();
    expect($day->entry_date->toDateString())->toBe('2026-07-10');
});

test('show-habit returns streaks, completion percent and the daily series', function () {
    $this->travelTo(Carbon::parse('2026-07-22 18:00:00', 'UTC'));

    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->daily()->create();
    $habit->days()->create(['entry_date' => '2026-07-21', 'accumulated_amount' => 10, 'completion_percent' => 50, 'completed' => false]);
    $habit->days()->create(['entry_date' => '2026-07-22', 'accumulated_amount' => 24, 'completion_percent' => 120, 'completed' => true]);

    $response = PosterServer::actingAs($user)->tool(ShowHabit::class, [
        'habit_id' => $habit->id,
        'days' => 7,
    ]);

    // The response is compact JSON (no pretty-printing), so these
    // substrings pin down both the computed metrics and the last two
    // days of the series without needing to decode the payload.
    $response->assertOk()
        ->assertSee([
            '"current_streak":1',
            '"best_streak":1',
            '"period_days":7',
            '"date":"2026-07-22","scheduled":true,"completion_percent":120,"completed":true',
            '"date":"2026-07-21","scheduled":true,"completion_percent":50,"completed":false',
        ]);
});

test('show-habit is not found for another user\'s habit', function () {
    $stranger = User::factory()->create();
    $habit = Habit::factory()->create();

    $response = PosterServer::actingAs($stranger)->tool(ShowHabit::class, [
        'habit_id' => $habit->id,
    ]);

    $response->assertHasErrors(["Habit not found: {$habit->id}"]);
});
