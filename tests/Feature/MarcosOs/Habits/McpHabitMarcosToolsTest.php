<?php

use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Habits\CreateHabit;
use App\Mcp\Tools\Habits\ListHabits;
use App\Mcp\Tools\Habits\LogHabitEntry;
use App\Mcp\Tools\Habits\LogTwoMinute;
use App\Mcp\Tools\Habits\ShowHabit;
use App\Mcp\Tools\Habits\TodayHabits;
use App\Mcp\Tools\Habits\UpdateHabit;
use App\Models\Habit;
use App\Models\User;
use Laravel\Mcp\Server\Attributes\Description;

require_once __DIR__.'/helpers.php';

/*
| Task 4.9 — MCP habit tools accept/return the 2-minute version and the
| tolerant streak state; `log-two-minute` is new (minor). Creating or
| changing a habit is a major AI operation: refused, nothing written.
*/

beforeEach(fn () => p4Today('2026-09-27'));

test('log-two-minute logs the 2-minute version as shown-up, never completed', function () {
    $habit = p4Habit('2026-09-01', ['two_minute_version' => 'Abrir el libro'], fn ($f) => $f->quantitative('páginas', 30));
    p4Days($habit, '2026-09-16', '2026-09-25');

    $response = PosterServer::actingAs($habit->user)->tool(LogTwoMinute::class, ['habit_id' => $habit->id]);

    $response->assertOk()
        ->assertSee('"two_minute_logged":true')
        ->assertSee('"completed":false')
        ->assertSee('"streak_state":"ok"')
        ->assertSee('"streak_current":11')
        ->assertSee(route('habits.show', $habit));

    $day = $habit->days()->where('entry_date', '2026-09-27')->sole();

    expect($day->two_minute_logged)->toBeTrue()
        ->and($day->completed)->toBeFalse();
});

test('log-two-minute hides retired habits (not found, retirement protocol) and other users\' habits', function () {
    $archived = p4Habit('2026-09-01', ['retired_at' => now()]);
    $foreign = p4Habit('2026-09-01');

    PosterServer::actingAs($archived->user)->tool(LogTwoMinute::class, ['habit_id' => $archived->id])
        ->assertHasErrors(["Habit not found: {$archived->id}"]);

    PosterServer::actingAs($archived->user)->tool(LogTwoMinute::class, ['habit_id' => $foreign->id])
        ->assertHasErrors(["Habit not found: {$foreign->id}"]);

    expect($archived->days()->count() + $foreign->days()->count())->toBe(0);
});

test('log-two-minute is listed with its tier and every habit tool states a tier', function () {
    foreach ([LogTwoMinute::class, LogHabitEntry::class, TodayHabits::class, ListHabits::class, ShowHabit::class, CreateHabit::class, UpdateHabit::class] as $tool) {
        $description = (new ReflectionClass($tool))->getAttributes(Description::class)[0]->getArguments()[0];

        expect($description)->toStartWith('Nivel IA:');
    }

    expect(app(LogTwoMinute::class)->name())->toBe('log-two-minute');
});

test('today-habits returns the 2-minute version, shown-up and the streak state', function () {
    $habit = p4Habit('2026-09-01', ['two_minute_version' => 'Dejar el teléfono fuera']);
    p4Days($habit, '2026-09-16', '2026-09-25');

    PosterServer::actingAs($habit->user)->tool(TodayHabits::class)
        ->assertOk()
        ->assertSee('"two_minute_version":"Dejar el teléfono fuera"')
        ->assertSee('"streak_current":10')
        ->assertSee('"streak_state":"at_risk"');
});

test('list-habits and show-habit return the 2-minute version, streak state and votes', function () {
    $habit = p4Habit('2026-09-01', ['two_minute_version' => 'Ponerme la ropa', 'identity_statement' => 'Soy alguien que entrena']);
    p4Days($habit, '2026-09-15', '2026-09-24');

    PosterServer::actingAs($habit->user)->tool(ListHabits::class)
        ->assertOk()
        ->assertSee('"two_minute_version":"Ponerme la ropa"')
        ->assertSee('"streak_state":"restart"');

    PosterServer::actingAs($habit->user)->tool(ShowHabit::class, ['habit_id' => $habit->id])
        ->assertOk()
        ->assertSee('"streak_state":"restart"')
        ->assertSee('"best_streak":10')
        ->assertSee('"effective_identity_statement":"Soy alguien que entrena"')
        ->assertSee('"votes_7":{"cast":4,"possible":6}');
});

test('log-habit-entry returns the day with its 2-minute flag and the streak', function () {
    $habit = p4Habit('2026-09-20');

    PosterServer::actingAs($habit->user)->tool(LogHabitEntry::class, ['habit_id' => $habit->id])
        ->assertOk()
        ->assertSee('"completed":true')
        ->assertSee('"two_minute_logged":false')
        ->assertSee('"shown_up":true');
});

test('create-habit and update-habit validate the 2-minute version and are major for the AI', function () {
    $user = User::factory()->create();

    PosterServer::actingAs($user)->tool(CreateHabit::class, [
        'name' => 'Estirar', 'habit_type' => 'yes_no', 'recurrence_type' => 'daily',
    ])->assertHasErrors(['Falta la versión de 2 minutos. Es lo que vas a hacer los días difíciles.']);

    PosterServer::actingAs($user)->tool(CreateHabit::class, [
        'name' => 'Estirar', 'habit_type' => 'yes_no', 'recurrence_type' => 'daily', 'two_minute_version' => 'Tocarme los pies',
    ])->assertHasErrors(['propose-change']);

    expect(Habit::query()->count())->toBe(0);

    $habit = p4Habit('2026-09-01', ['user_id' => $user->id, 'name' => 'Leer']);

    PosterServer::actingAs($user)->tool(UpdateHabit::class, [
        'habit_id' => $habit->id, 'name' => 'Leer más', 'habit_type' => 'yes_no', 'recurrence_type' => 'daily', 'two_minute_version' => 'Abrir el libro',
    ])->assertHasErrors(['propose-change']);

    expect($habit->fresh()->name)->toBe('Leer');
});
