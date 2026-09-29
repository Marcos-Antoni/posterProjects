<?php

use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Review;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Task 7.5/7.6/7.7/7.8 — the weekly review (screen 14), the milestone
| summit (screen 12), the objective close/learning review (screen 13) and
| the reviews history (screen 15).
*/

beforeEach(function () {
    $this->owner = User::factory()->create();
});

test('the weekly review shows progress before the questions', function () {
    Item::factory()->for(Plan::factory()->for(Objective::factory()->for($this->owner)->withControlPlan()))->done()->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->get('/reviews/weekly')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reviews/weekly')
        ->has('progress.items_done')
        ->has('main_candidates'));
});

test('submitting the weekly review redirects to the reviews history', function () {
    $objective = Objective::factory()->for($this->owner)->withControlPlan()->create();

    $this->actingAs($this->owner)->post('/reviews/weekly', [
        'what_worked' => 'la captura rápida',
        'what_blocked' => 'las notificaciones',
        'main_type' => 'objective',
        'main_id' => $objective->id,
        'maintenance' => [],
    ])->assertRedirect('/reviews');

    expect(Review::query()->where('user_id', $this->owner->id)->count())->toBe(1);
});

test('the reviews history lists reviews newest first, and none are deletable', function () {
    Review::factory()->for($this->owner)->create(['created_at' => now()->subDays(2)]);
    Review::factory()->for($this->owner)->create(['created_at' => now()]);

    $this->actingAs($this->owner)->get('/reviews')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reviews/index')
        ->has('reviews', 2));
});

test('the milestone summit shows the evidence form before completion, and what it opened after', function () {
    $objective = Objective::factory()->for($this->owner)->withControlPlan()->create();
    $plan = Plan::factory()->for($objective)->create();
    $milestone = Item::factory()->for($plan)->milestone()->create(['user_id' => $this->owner->id]);
    $unlocked = Item::factory()->for($plan)->create(['user_id' => $this->owner->id]);
    $unlocked->prerequisites()->attach($milestone->id);

    $this->actingAs($this->owner)->get("/objectives/{$objective->key}/items/{$milestone->key}/summit")
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('items/summit')->where('opened', []));

    $this->actingAs($this->owner)->post("/objectives/{$objective->key}/items/{$milestone->key}/check", [
        'evidence' => 'boceto hecho',
    ])->assertRedirect();

    $this->actingAs($this->owner)->get("/objectives/{$objective->key}/items/{$milestone->key}/summit")
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('items/summit')
        ->has('opened', 1));
});

test('closing an objective shows its habits and requires a decision for each, then history keeps the review', function () {
    $objective = Objective::factory()->for($this->owner)->withControlPlan()->create();
    Habit::factory()->for($this->owner)->create(['objective_id' => $objective->id]);

    $this->actingAs($this->owner)->get("/objectives/{$objective->key}/close")
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('objectives/close')->has('habits', 1));

    $this->actingAs($this->owner)->post("/objectives/{$objective->key}/close", [
        'what_learned' => 'aprendí',
        'what_repeat' => 'repetiría',
        'what_change' => 'cambiaría',
        'habits' => [],
    ])->assertSessionHasErrors('habits');
});
