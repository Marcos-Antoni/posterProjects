<?php

use App\Actions\Objectives\ActivateObjective;
use App\Actions\Objectives\ReopenObjective;
use App\Enums\ObjectiveState;
use App\Models\Objective;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/*
| Task 2.12 — objective lifecycle draft/active/closed/retired, navigation
| shows only active objectives, and no delete route exists anywhere
| (projects spec).
*/

test('the lifecycle allows only its transitions', function () {
    expect(ObjectiveState::Draft->canTransitionTo(ObjectiveState::Active))->toBeTrue()
        ->and(ObjectiveState::Active->canTransitionTo(ObjectiveState::Closed))->toBeTrue()
        ->and(ObjectiveState::Active->canTransitionTo(ObjectiveState::Retired))->toBeTrue()
        ->and(ObjectiveState::Closed->canTransitionTo(ObjectiveState::Active))->toBeTrue()
        ->and(ObjectiveState::Closed->canTransitionTo(ObjectiveState::Retired))->toBeFalse()
        ->and(ObjectiveState::Active->canTransitionTo(ObjectiveState::Draft))->toBeFalse()
        ->and(ObjectiveState::Retired->canTransitionTo(ObjectiveState::Active))->toBeFalse();
});

test('a closed objective may be reopened to active', function () {
    $objective = Objective::factory()->closed()->withControlPlan()->create();

    app(ReopenObjective::class)(mosOwner($objective), $objective);

    expect($objective->fresh()->state)->toBe(ObjectiveState::Active)
        ->and($objective->fresh()->closed_at)->toBeNull();
});

test('only a closed objective can be reopened and only a draft activated', function () {
    $active = Objective::factory()->withControlPlan()->create();

    expect(fn () => app(ReopenObjective::class)(mosOwner($active), $active))->toThrow(ValidationException::class);
    expect(fn () => app(ActivateObjective::class)(mosOwner($active), $active))->toThrow(ValidationException::class);
});

test('navigation lists only the owner\'s active objectives in manual order, and nothing for guests', function () {
    $owner = User::factory()->create();
    $second = Objective::factory()->for($owner)->create(['title' => 'Segundo', 'position' => 2]);
    $first = Objective::factory()->for($owner)->create(['title' => 'Primero', 'position' => 1]);
    Objective::factory()->for($owner)->closed()->create();
    Objective::factory()->for($owner)->retired()->create();
    Objective::factory()->for($owner)->draft()->create();
    Objective::factory()->create();

    $this->actingAs($owner)->get(route('settings.appearance.show'))
        ->assertInertia(fn ($page) => $page
            ->has('navigationObjectives', 2)
            ->where('navigationObjectives.0.key', $first->key)
            ->where('navigationObjectives.0.title', 'Primero')
            ->where('navigationObjectives.1.key', $second->key));

    auth()->logout();

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('navigationObjectives', []));
});

test('closed and retired objectives leave the index too', function () {
    $owner = User::factory()->create();
    $active = Objective::factory()->for($owner)->withControlPlan()->create();
    Objective::factory()->for($owner)->closed()->withControlPlan()->create();
    Objective::factory()->for($owner)->retired()->withControlPlan()->create();

    $this->actingAs($owner)->get(route('objectives.index'))
        ->assertInertia(fn ($page) => $page->has('objectives', 1)->where('objectives.0.key', $active->key));
});

test('no delete or force-delete route exists for objectives, plans or items on web or API', function () {
    $routes = collect(Route::getRoutes()->getRoutes());

    $destructive = $routes->filter(function ($route): bool {
        $uri = $route->uri();

        if (preg_match('#(objectives|plans|items)#', $uri) !== 1) {
            return false;
        }

        if (preg_match('#(force|trash|destroy|delete)#i', $uri.' '.$route->getName()) === 1
            && preg_match('#(control-map|prerequisites|unlocks)#', $uri) !== 1) {
            return true;
        }

        return in_array('DELETE', $route->methods(), true)
            && preg_match('#/(control-map|prerequisites|unlocks)/#', $uri) !== 1;
    });

    expect($destructive->map(fn ($route) => $route->uri())->values()->all())->toBe([]);
});

test('the retired legacy web routes are gone', function (string $uri) {
    $this->actingAs(User::factory()->create())->get($uri)->assertNotFound();
})->with(['/projects', '/projects/trash', '/calendar', '/projects/DEMO/board', '/projects/DEMO/backlog', '/projects/DEMO/labels']);
