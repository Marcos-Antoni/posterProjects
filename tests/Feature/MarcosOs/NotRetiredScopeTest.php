<?php

use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Habits\ListHabits;
use App\Mcp\Tools\Habits\TodayHabits;
use App\Mcp\Tools\Objectives\ListObjectives;
use App\Mcp\Tools\Objectives\ShowObjective;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Scopes\NotRetired;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Task 6.2 — the `NotRetired` global scope on every retirable model and the
| hidden-everywhere rule (retirement spec "Retired Elements Are Hidden"):
| retired elements are absent from web screens, API lists and MCP lists, and
| only the Retired view (and restore) bypasses the scope.
*/

test('every retirable model carries the NotRetired global scope', function (string $model) {
    expect((new $model)->hasGlobalScope(NotRetired::class))->toBeTrue();
})->with([Objective::class, Plan::class, Item::class, Habit::class]);

test('retired items are excluded by default and reachable only through withRetired / onlyRetired', function () {
    $plan = Plan::factory()->create();
    $open = Item::factory()->for($plan)->create();
    $retired = Item::factory()->for($plan)->retired()->create();

    expect(Item::query()->pluck('id')->all())->toBe([$open->id])
        ->and(Item::withRetired()->orderBy('id')->pluck('id')->all())->toBe([$open->id, $retired->id])
        ->and(Item::onlyRetired()->pluck('id')->all())->toBe([$retired->id])
        ->and($plan->items()->pluck('items.id')->all())->toBe([$open->id])
        ->and(Item::query()->withState()->pluck('items.id')->all())->toBe([$open->id]);
});

test('retired plans, objectives and habits are excluded by default', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->create();
    $retiredObjective = Objective::factory()->for($owner)->retired()->create();
    $plan = Plan::factory()->for($objective)->create();
    $retiredPlan = Plan::factory()->for($objective)->create(['state' => 'retired']);
    $habit = Habit::factory()->for($owner)->create();
    $retiredHabit = Habit::factory()->for($owner)->retired()->create();

    expect(Objective::query()->pluck('id')->all())->toBe([$objective->id])
        ->and(Objective::onlyRetired()->pluck('id')->all())->toBe([$retiredObjective->id])
        ->and($objective->plans()->pluck('id')->all())->toBe([$plan->id])
        ->and(Plan::onlyRetired()->pluck('id')->all())->toBe([$retiredPlan->id])
        ->and($owner->habits()->pluck('id')->all())->toBe([$habit->id])
        ->and(Habit::onlyRetired()->pluck('id')->all())->toBe([$retiredHabit->id]);
});

test('a child still reaches its retired parent through its parent relation', function () {
    $objective = Objective::factory()->create();
    $plan = Plan::factory()->for($objective)->create();
    $item = Item::factory()->for($plan)->retired()->create();
    $plan->update(['state' => 'retired']);
    $objective->update(['state' => 'retired']);

    $item = Item::withRetired()->findOrFail($item->id);

    expect($item->plan?->id)->toBe($plan->id)
        ->and($item->objective?->id)->toBe($objective->id)
        ->and(Plan::withRetired()->findOrFail($plan->id)->objective?->id)->toBe($objective->id);
});

test('the web hides retired elements: tree, navigation, habit screens, deep links', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    Objective::factory()->for($owner)->retired()->withControlPlan()->create(['key' => 'VIEJO', 'title' => 'Objetivo viejo']);
    $plan = Plan::factory()->for($objective)->create();
    Plan::factory()->for($objective)->create(['state' => 'retired', 'title' => 'Plan retirado']);
    Item::factory()->for($plan)->create(['title' => 'Visible']);
    $retiredItem = Item::factory()->for($plan)->retired()->create(['title' => 'Oculta']);
    Habit::factory()->for($owner)->create(['name' => 'Leer']);
    $retiredHabit = Habit::factory()->for($owner)->retired()->create(['name' => 'Meditar']);

    $this->actingAs($owner)->get('/objectives/SALUD')->assertInertia(fn (Assert $page) => $page
        ->has('plans', 1)
        ->has('plans.0.items', 1)
        ->where('plans.0.items.0.title', 'Visible')
        ->where('navigationObjectives', [['key' => 'SALUD', 'title' => $objective->title]]));

    $this->actingAs($owner)->get('/objectives/SALUD/items/'.$retiredItem->key)->assertNotFound();
    $this->actingAs($owner)->get('/objectives/VIEJO')->assertNotFound();

    $this->actingAs($owner)->get('/habits')->assertInertia(fn (Assert $page) => $page
        ->has('habits', 1)
        ->where('habits.0.name', 'Leer'));

    $this->actingAs($owner)->get('/habits/manage')->assertInertia(fn (Assert $page) => $page
        ->has('habits', 1)
        ->where('habits.0.name', 'Leer'));

    $this->actingAs($owner)->get("/habits/{$retiredHabit->id}")->assertNotFound();
});

test('API lists hide retired objectives and habits, and a retired habit is in the 404 set', function () {
    $owner = User::factory()->create();
    Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    Objective::factory()->for($owner)->retired()->withControlPlan()->create(['key' => 'VIEJO']);
    Habit::factory()->for($owner)->daily()->create(['name' => 'Leer']);
    $retiredHabit = Habit::factory()->for($owner)->daily()->retired()->create(['name' => 'Meditar']);
    $headers = mosMobileHeaders($owner);

    $this->getJson('/api/v1/objectives', $headers)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', 'SALUD');

    $this->getJson('/api/v1/habits/today', $headers)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Leer');

    $this->postJson("/api/v1/habits/{$retiredHabit->id}/increment", [], $headers)->assertNotFound();
    $this->postJson("/api/v1/habits/{$retiredHabit->id}/decrement", [], $headers)->assertNotFound();
    expect($retiredHabit->entries()->count())->toBe(0);
});

test('MCP lists hide retired objectives, items and habits', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    Objective::factory()->for($owner)->retired()->withControlPlan()->create(['key' => 'VIEJO', 'title' => 'Objetivo viejo']);
    Item::factory()->for(Plan::factory()->for($objective))->retired()->create(['title' => 'Tarea oculta']);
    Habit::factory()->for($owner)->daily()->create(['name' => 'Leer']);
    Habit::factory()->for($owner)->daily()->retired()->create(['name' => 'Meditar']);

    PosterServer::actingAs($owner)->tool(ListObjectives::class)->assertOk()->assertDontSee('VIEJO');
    PosterServer::actingAs($owner)->tool(ShowObjective::class, ['objective_key' => 'SALUD'])->assertOk()->assertDontSee('Tarea oculta');
    PosterServer::actingAs($owner)->tool(ListHabits::class)->assertOk()->assertSee('Leer')->assertDontSee('Meditar');
    PosterServer::actingAs($owner)->tool(TodayHabits::class)->assertOk()->assertSee('Leer')->assertDontSee('Meditar');
});
