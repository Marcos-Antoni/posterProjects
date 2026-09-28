<?php

use App\Actions\Items\AddItem;
use App\Actions\Items\CheckItem;
use App\Actions\Items\UncheckItem;
use App\Actions\Plans\CreatePlan;
use App\Actions\Plans\MovePlan;
use App\Actions\Plans\UpdatePlan;
use App\Actions\Support\Actor;
use App\Enums\PlanState;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Support\Facades\Route;

/*
| Task 2.9 — plan actions (plans spec): create/append, reorder, auto-done when
| every non-retired item is done, unchecking returns the plan to active, and
| no delete operation anywhere.
*/

test('a plan is appended at the end of its objective', function () {
    $objective = Objective::factory()->withControlPlan()->create();
    Plan::factory()->for($objective)->count(2)->create();

    $third = app(CreatePlan::class)(Actor::ownerWeb($objective->user), $objective, ['title' => 'Cierre']);

    expect($objective->plans()->pluck('title')->last())->toBe('Cierre')
        ->and($third->position)->toBe(2);
});

test('plans are reordered by moving one up or down, with no gaps or duplicates', function () {
    $objective = Objective::factory()->withControlPlan()->create();
    [$a, $b, $c] = Plan::factory()->for($objective)->count(3)->create()->all();
    $actor = Actor::ownerWeb($objective->user);

    app(MovePlan::class)($actor, $c, -1);
    expect($objective->plans()->pluck('id')->all())->toBe([$a->id, $c->id, $b->id]);

    app(MovePlan::class)($actor, $a->fresh(), 1);
    expect($objective->plans()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and($objective->plans()->pluck('position')->all())->toBe([0, 1, 2]);

    // Moving past either end is a no-op.
    app(MovePlan::class)($actor, $c->fresh(), -1);
    expect($objective->plans()->pluck('id')->first())->toBe($c->id);
});

test('checking the last open item completes the plan automatically', function () {
    $plan = Plan::factory()->create();
    Item::factory()->for($plan)->done()->create();
    Item::factory()->for($plan)->retired()->create();
    $last = Item::factory()->for($plan)->create();

    app(CheckItem::class)(Actor::ownerWeb($plan->objective->user), $last);

    expect($plan->fresh()->state)->toBe(PlanState::Done);
});

test('a plan with open items stays active', function () {
    $plan = Plan::factory()->create();
    $a = Item::factory()->for($plan)->create();
    Item::factory()->for($plan)->create();

    app(CheckItem::class)(Actor::ownerWeb($plan->objective->user), $a);

    expect($plan->fresh()->state)->toBe(PlanState::Active);
});

test('unchecking an item of a done plan returns it to active, without penalty', function () {
    $plan = Plan::factory()->create();
    $item = Item::factory()->for($plan)->create();
    $actor = Actor::ownerWeb($plan->objective->user);

    app(CheckItem::class)($actor, $item);
    expect($plan->fresh()->state)->toBe(PlanState::Done);

    app(UncheckItem::class)($actor, $item->fresh());
    expect($plan->fresh()->state)->toBe(PlanState::Active);
});

test('adding an item to a done plan reopens it', function () {
    $plan = Plan::factory()->create();
    $item = Item::factory()->for($plan)->create();
    $actor = Actor::ownerWeb($plan->objective->user);
    app(CheckItem::class)($actor, $item);

    app(AddItem::class)($actor, $plan->fresh(), ['title' => 'Una más', 'two_minute_version' => 'abrir la libreta']);

    expect($plan->fresh()->state)->toBe(PlanState::Active);
});

test('a draft plan is never auto-completed nor auto-activated', function () {
    $plan = Plan::factory()->draft()->create();
    $item = Item::factory()->for($plan)->create();
    $actor = Actor::ownerWeb($plan->objective->user);

    app(CheckItem::class)($actor, $item);
    expect($plan->fresh()->state)->toBe(PlanState::Draft);

    app(UncheckItem::class)($actor, $item->fresh());
    expect($plan->fresh()->state)->toBe(PlanState::Draft);
});

test('a plan title and level are editable', function () {
    $plan = Plan::factory()->withControlPlan()->create();

    app(UpdatePlan::class)(Actor::ownerWeb($plan->objective->user), $plan, ['title' => 'Semana 1: primer ciclo', 'level' => 1]);

    expect($plan->fresh()->title)->toBe('Semana 1: primer ciclo')->and($plan->fresh()->level)->toBe(1);
});

test('no delete route exists for plans (or objectives or items)', function () {
    $deletes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('DELETE', $route->methods(), true))
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => preg_match('#(^|/)(objectives|plans|items)(/|$)#', $uri) === 1
            && preg_match('#/(control-map|prerequisites|unlocks)/#', $uri) !== 1);

    expect($deletes->values()->all())->toBe([]);
});
