<?php

use App\Enums\ObjectiveState;
use App\Models\Capture;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use App\Models\WeeklyPriority;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Phase 7 adversarial pass (light, [WB][p7-test]) — capture-inbox and
| reviews specs. Targets ownership across two users, "capture ≠ priority",
| retirement's reason+hide-never-delete contract, the weekly priority's
| one-main/two-maintenance ceiling, the milestone summit's evidence
| requirement, and the new objective-close routes.
*/

test('the capture inbox never lists another user\'s captures', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $mine = Capture::factory()->create(['user_id' => $owner->id, 'text' => 'mío']);
    Capture::factory()->create(['user_id' => $intruder->id, 'text' => 'ajeno']);

    $this->actingAs($owner)->get('/captures')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('captures/index')
        ->has('captures', 1)
        ->where('captures.0.id', $mine->id));
});

test('another user cannot triage my capture', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $objective = Objective::factory()->for($intruder)->withControlPlan()->create();
    $plan = Plan::factory()->for($objective)->create();
    $capture = Capture::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($intruder)->post("/captures/{$capture->id}/convert-to-item", [
        'objective_key' => $objective->key,
        'plan_id' => $plan->id,
        'title' => 'robado',
        'two_minute_version' => 'abrir y listo',
    ])->assertNotFound();

    expect($capture->fresh()->isTriaged())->toBeFalse();
});

test('another user cannot see or retire my capture', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $capture = Capture::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($intruder)->getJson("/captures/{$capture->id}/retire")->assertNotFound();

    $this->actingAs($intruder)->post("/captures/{$capture->id}/retire", [
        'reason' => 'una razón inventada con más de diez letras',
    ])->assertNotFound();

    expect($capture->fresh()->isRetired())->toBeFalse();
});

test('retiring a capture requires a reason of at least ten characters, and hides without deleting it', function () {
    $owner = User::factory()->create();
    $capture = Capture::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)->post("/captures/{$capture->id}/retire", [
        'reason' => 'corto',
    ])->assertSessionHasErrors('reason');

    expect($capture->fresh()->isRetired())->toBeFalse();

    $this->actingAs($owner)->post("/captures/{$capture->id}/retire", [
        'reason' => 'ya no me interesa, era una idea al pasar',
    ])->assertRedirect('/captures');

    expect(Capture::query()->whereKey($capture->id)->exists())->toBeFalse();

    $retired = Capture::withRetired()->whereKey($capture->id)->first();

    expect($retired)->not->toBeNull()
        ->and($retired->retired_at)->not->toBeNull();
});

test('a capture can never become the weekly priority', function () {
    $owner = User::factory()->create();
    $capture = Capture::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)->post('/reviews/weekly', [
        'what_worked' => 'anoté todo en la captura',
        'what_blocked' => 'nada en particular',
        'main_type' => 'capture',
        'main_id' => $capture->id,
        'maintenance' => [],
    ])->assertSessionHasErrors('main_type');

    expect(WeeklyPriority::query()->where('user_id', $owner->id)->exists())->toBeFalse();
});

test('a third maintenance standard is rejected at the weekly review endpoint', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create();
    $habits = Habit::factory()->for($owner)->count(3)->create();

    $this->actingAs($owner)->post('/reviews/weekly', [
        'what_worked' => 'terminé el boceto',
        'what_blocked' => 'me distraje',
        'main_type' => 'objective',
        'main_id' => $objective->id,
        'maintenance' => $habits->map(fn (Habit $habit): array => ['type' => 'habit', 'id' => $habit->id])->all(),
    ])->assertSessionHasErrors('maintenance');

    expect(WeeklyPriority::query()->where('user_id', $owner->id)->exists())->toBeFalse();
});

test('the weekly priority cannot be set to another user\'s objective', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $foreignObjective = Objective::factory()->for($intruder)->withControlPlan()->create();

    $this->actingAs($owner)->post('/reviews/weekly', [
        'what_worked' => 'x',
        'what_blocked' => 'y',
        'main_type' => 'objective',
        'main_id' => $foreignObjective->id,
        'maintenance' => [],
    ])->assertSessionHasErrors('main_id');

    expect(WeeklyPriority::query()->where('user_id', $owner->id)->exists())->toBeFalse();
});

test('a milestone cannot be checked done without evidence', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create();
    $plan = Plan::factory()->for($objective)->create();
    $milestone = Item::factory()->for($plan)->milestone()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->post("/objectives/{$objective->key}/items/{$milestone->key}/check", [])
        ->assertSessionHasErrors('evidence');

    expect($milestone->fresh()->completed_at)->toBeNull();
});

test('another user cannot view or close my objective', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create();

    $this->actingAs($intruder)->get("/objectives/{$objective->key}/close")->assertNotFound();

    $this->actingAs($intruder)->post("/objectives/{$objective->key}/close", [
        'what_learned' => 'x',
        'what_repeat' => 'y',
        'what_change' => 'z',
        'habits' => [],
    ])->assertNotFound();

    expect($objective->fresh()->state)->toBe(ObjectiveState::Active);
});
