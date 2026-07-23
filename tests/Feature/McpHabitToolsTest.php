<?php

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Habits\ArchiveHabit;
use App\Mcp\Tools\Habits\CreateHabit;
use App\Mcp\Tools\Habits\UnarchiveHabit;
use App\Mcp\Tools\Habits\UpdateHabit;
use App\Models\Habit;
use App\Models\User;

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
