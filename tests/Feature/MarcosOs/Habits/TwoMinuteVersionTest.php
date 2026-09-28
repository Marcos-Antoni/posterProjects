<?php

use App\Actions\Habits\CreateHabit;
use App\Actions\Habits\LogTwoMinute;
use App\Actions\Habits\UndoTwoMinute;
use App\Actions\Habits\UpdateHabit;
use App\Actions\Support\Actor;
use App\Enums\StreakState;
use App\Models\Habit;
use App\Models\User;

require_once __DIR__.'/helpers.php';

/*
| Task 4.2 — every habit has a 2-minute version, required on create and
| update; logging it counts as shown-up (streak + identity vote) but never
| marks a quantitative day `completed` (habits spec).
*/

beforeEach(fn () => p4Today('2026-09-27'));

function p4HabitPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Leer 2 páginas de Control',
        'habit_type' => 'quantitative',
        'unit' => 'páginas',
        'daily_target' => 2,
        'recurrence_type' => 'daily',
        'two_minute_version' => 'Abrir el libro en el separador',
    ], $overrides);
}

test('creating a habit requires a 2-minute version', function (?string $value) {
    $user = User::factory()->create();

    $errors = mosErrors(fn () => app(CreateHabit::class)(Actor::ownerWeb($user), p4HabitPayload(['two_minute_version' => $value])));

    expect($errors)->toHaveKey('two_minute_version')
        ->and($errors['two_minute_version'][0])->toBe('Falta la versión de 2 minutos. Es lo que vas a hacer los días difíciles.')
        ->and(Habit::query()->count())->toBe(0);
})->with([null, '', '   ']);

test('updating a habit cannot remove its 2-minute version', function () {
    $user = User::factory()->create();
    $habit = app(CreateHabit::class)(Actor::ownerWeb($user), p4HabitPayload());

    $errors = mosErrors(fn () => app(UpdateHabit::class)(Actor::ownerWeb($user), $habit, p4HabitPayload(['two_minute_version' => ''])));

    expect($errors)->toHaveKey('two_minute_version')
        ->and($habit->fresh()->two_minute_version)->toBe('Abrir el libro en el separador');
});

test('the web form rejects a habit without a 2-minute version', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('habits.store'), p4HabitPayload(['two_minute_version' => '']))
        ->assertSessionHasErrors(['two_minute_version' => 'Falta la versión de 2 minutos. Es lo que vas a hacer los días difíciles.']);

    $this->actingAs($user)
        ->post(route('habits.store'), p4HabitPayload())
        ->assertSessionHasNoErrors();

    expect($user->habits()->sole()->two_minute_version)->toBe('Abrir el libro en el separador');
});

test('logging the 2-minute version shows up without completing a quantitative day', function () {
    $habit = p4Habit('2026-09-20', ['two_minute_version' => 'Abrir el libro'], fn ($f) => $f->quantitative('páginas', 30));

    $day = app(LogTwoMinute::class)(Actor::ownerWeb($habit->user), $habit);

    expect($day->two_minute_logged)->toBeTrue()
        ->and($day->completed)->toBeFalse()
        ->and($day->accumulated_amount)->toBe(0)
        ->and($day->entry_date->toDateString())->toBe('2026-09-27')
        ->and($habit->entries()->count())->toBe(0)
        ->and($habit->fresh()->history()->streak()->current)->toBe(1);
});

test('logging the 2-minute version twice the same day changes nothing', function () {
    $habit = p4Habit('2026-09-20');

    app(LogTwoMinute::class)(Actor::ownerWeb($habit->user), $habit);
    $again = app(LogTwoMinute::class)(Actor::ownerWeb($habit->user), $habit);

    expect($habit->days()->count())->toBe(1)
        ->and($again->two_minute_logged)->toBeTrue();
});

test('reaching the target after the 2-minute version completes the day and keeps the flag', function () {
    $habit = p4Habit('2026-09-20', state: fn ($f) => $f->quantitative('páginas', 2));

    app(LogTwoMinute::class)(Actor::ownerWeb($habit->user), $habit);
    $habit->recordEntry(2);

    $day = $habit->days()->sole();

    expect($day->completed)->toBeTrue()
        ->and($day->two_minute_logged)->toBeTrue();
});

test('the 2-minute version is undone only for today and never touches completion', function () {
    $habit = p4Habit('2026-09-20');
    p4Day($habit, '2026-09-26', 'two');

    app(LogTwoMinute::class)(Actor::ownerWeb($habit->user), $habit);
    $day = app(UndoTwoMinute::class)(Actor::ownerWeb($habit->user), $habit);

    expect($day->two_minute_logged)->toBeFalse()
        ->and($habit->days()->where('entry_date', '2026-09-26')->sole()->two_minute_logged)->toBeTrue();
});

test('the 2-minute version restarts a broken streak', function () {
    $habit = p4Habit('2026-09-01');
    p4Days($habit, '2026-09-10', '2026-09-20');

    expect($habit->history()->streak()->state)->toBe(StreakState::Restart);

    app(LogTwoMinute::class)(Actor::ownerWeb($habit->user), $habit);

    $streak = $habit->fresh()->history()->streak();

    expect($streak->current)->toBe(1)
        ->and($streak->state)->toBe(StreakState::Ok)
        ->and($streak->best)->toBe(11);
});

test('a retired habit rejects the 2-minute version', function () {
    $habit = p4Habit('2026-09-20', ['retired_at' => now()]);

    $errors = mosErrors(fn () => app(LogTwoMinute::class)(Actor::ownerWeb($habit->user), $habit));

    expect($errors)->toHaveKey('habit')
        ->and($habit->days()->count())->toBe(0);
});

test('the web 2-minute route logs it for the owner only', function () {
    $habit = p4Habit('2026-09-20');
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->post(route('habits.two-minute.store', $habit))->assertForbidden();

    $this->actingAs($habit->user)->post(route('habits.two-minute.store', $habit))->assertRedirect();

    expect($habit->days()->sole()->two_minute_logged)->toBeTrue();

    $this->actingAs($habit->user)->delete(route('habits.two-minute.destroy', $habit))->assertRedirect();

    // Nothing else was recorded that day: the emptied row is removed.
    expect($habit->days()->count())->toBe(0);
});
