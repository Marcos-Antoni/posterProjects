<?php

use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Habits\LogHabitEntry;
use App\Mcp\Tools\Habits\RestoreHabit;
use App\Mcp\Tools\Habits\RetireHabit;
use App\Models\Habit;
use App\Models\Retirement;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
| Task 6.4 — habit archive/unarchive become retire/restore (habits spec
| "Retiring A Habit Follows The Retirement Protocol"): written reason,
| entries rejected on web, API and MCP, history kept, restored through the
| Retired view; MCP retirement is a major operation.
*/

test('the owner retires a habit with a written reason; it keeps its history', function () {
    $owner = User::factory()->create();
    $habit = Habit::factory()->for($owner)->create(['name' => 'Meditar 20 min']);
    $habit->recordEntry(1);

    $this->actingAs($owner)
        ->post("/habits/{$habit->id}/retire", ['reason' => 'demasiado grande para arrancar'])
        ->assertRedirect('/habits/manage')
        ->assertSessionHasNoErrors();

    $habit = Habit::withRetired()->findOrFail($habit->id);
    $retirement = Retirement::query()->sole();

    expect($habit->retired_at)->not->toBeNull()
        ->and($habit->entries()->count())->toBe(1)
        ->and($retirement->reason)->toBe('demasiado grande para arrancar')
        ->and($retirement->decision->value)->toBe('archive_as_is')
        ->and($retirement->kind->value)->toBe('habit')
        ->and($retirement->objective_id)->toBeNull();
});

test('retiring a habit requires a reason of at least 10 characters', function () {
    $owner = User::factory()->create();
    $habit = Habit::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->post("/habits/{$habit->id}/retire", ['reason' => 'no va'])
        ->assertSessionHasErrors(['reason' => 'Escribí al menos 10 caracteres para que te sirva después.']);

    expect($habit->fresh()->retired_at)->toBeNull();
});

test('another user\'s habit cannot be retired', function () {
    $habit = Habit::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post("/habits/{$habit->id}/retire", ['reason' => 'demasiado grande para arrancar'])
        ->assertNotFound();

    expect($habit->fresh()->retired_at)->toBeNull();
});

test('archive and unarchive routes no longer exist, and there is no destroy route', function () {
    expect(Route::has('habits.archive'))->toBeFalse()
        ->and(Route::has('habits.unarchive'))->toBeFalse()
        ->and(Route::has('habits.destroy'))->toBeFalse()
        ->and(Route::has('habits.retire'))->toBeTrue();

    $owner = User::factory()->create();
    $habit = Habit::factory()->for($owner)->create();

    $this->actingAs($owner)->post("/habits/{$habit->id}/archive")->assertNotFound();
    $this->actingAs($owner)->delete("/habits/{$habit->id}")->assertStatus(405);
});

test('a retired habit rejects entries on the web, the API and MCP', function () {
    $owner = User::factory()->create();
    $habit = Habit::factory()->for($owner)->daily()->retired()->create();

    $this->actingAs($owner)->post("/habits/{$habit->id}/entries")->assertNotFound();
    $this->postJson("/api/v1/habits/{$habit->id}/increment", [], mosMobileHeaders($owner))->assertNotFound();
    PosterServer::actingAs($owner)->tool(LogHabitEntry::class, ['habit_id' => $habit->id])
        ->assertHasErrors(["Habit not found: {$habit->id}"]);

    expect($habit->entries()->count())->toBe(0);
});

test('restoring a habit from the Retired view re-enables entries without altering history', function () {
    $owner = User::factory()->create();
    $habit = Habit::factory()->for($owner)->create();
    $habit->recordEntry(1);
    $this->actingAs($owner)->post("/habits/{$habit->id}/retire", ['reason' => 'volver con 2 minutos más adelante']);
    $retirement = Retirement::query()->sole();

    $this->actingAs($owner)->post("/retired/{$retirement->id}/restore")->assertRedirect()->assertSessionHasNoErrors();

    expect($habit->fresh()->retired_at)->toBeNull()
        ->and($habit->entries()->count())->toBe(1)
        ->and($retirement->fresh()->restored_at)->not->toBeNull();

    $this->actingAs($owner)->post("/habits/{$habit->id}/entries")->assertSessionHasNoErrors();
    expect($habit->entries()->count())->toBe(2);
});

test('MCP retire-habit and restore-habit are major operations: they change nothing without a proposal', function () {
    $owner = User::factory()->create();
    $habit = Habit::factory()->for($owner)->create();

    PosterServer::actingAs($owner)->tool(RetireHabit::class, ['habit_id' => $habit->id, 'reason' => 'demasiado grande para arrancar'])
        ->assertHasErrors();
    expect($habit->fresh()->retired_at)->toBeNull();

    $retired = Habit::factory()->for($owner)->retired()->create();
    $retirement = Retirement::query()->create([
        'user_id' => $owner->id,
        'retirable_type' => 'habit',
        'retirable_id' => $retired->id,
        'kind' => 'habit',
        'reason' => 'demasiado grande para arrancar',
        'decision' => 'archive_as_is',
        'prior_state' => 'active',
        'retired_at' => now(),
    ]);

    PosterServer::actingAs($owner)->tool(RestoreHabit::class, ['habit_id' => $retired->id])->assertHasErrors();
    expect($retired->fresh()->retired_at)->not->toBeNull()
        ->and($retirement->fresh()->restored_at)->toBeNull();
});

test('MCP retire-habit states its tier and still validates the reason and ownership', function () {
    $owner = User::factory()->create();
    $foreign = Habit::factory()->create();

    PosterServer::actingAs($owner)->tool(RetireHabit::class, ['habit_id' => $foreign->id, 'reason' => 'demasiado grande para arrancar'])
        ->assertHasErrors(["Habit not found: {$foreign->id}"]);

    expect((new ReflectionClass(RetireHabit::class))->getAttributes()[0]->getArguments()[0])->toStartWith('Nivel IA: major-proposal')
        ->and((new ReflectionClass(RestoreHabit::class))->getAttributes()[0]->getArguments()[0])->toStartWith('Nivel IA: major-proposal');
});

test('habits archived before the protocol get a retirement row, so they show in Retirados and can be restored', function () {
    $owner = User::factory()->create();
    $habit = Habit::factory()->for($owner)->retired()->create();
    Habit::factory()->for($owner)->create();

    $migration = require database_path('migrations/2026_09_28_600003_backfill_retirements_for_archived_habits.php');
    $migration->up();
    $migration->up();

    $retirement = Retirement::query()->sole();

    expect($retirement->retirable_id)->toBe($habit->id)
        ->and($retirement->user_id)->toBe($owner->id)
        ->and($retirement->kind->value)->toBe('habit')
        ->and($retirement->restored_at)->toBeNull();

    $this->actingAs($owner)->post("/retired/{$retirement->id}/restore")->assertSessionHasNoErrors();
    expect($habit->fresh()->retired_at)->toBeNull();
});
