<?php

use App\Actions\Items\StartItem;
use App\Actions\Retirement\RestoreElement;
use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Enums\RetirementDecision;
use App\Http\Resources\UnlockGraph;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Wave A integration P5 Graphs x P6 Retirement x P3 Now:
| - retirements written by the real protocol (user_id, kind, objective_id,
|   parent rows of a cascade) feed the graph's "retired beside the track"
|   marks with their reason; nodes/edges respect the NotRetired handling and
|   restoring puts the station back;
| - the active station of screen 8 offers "Seguir en Ahora" (route `now`),
|   and the graph's Now task is the Now screen's task.
*/

function integGraphSetup(): array
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->withControlPlan()->create(['title' => 'Semana 1']);
    $a = Item::factory()->for($plan)->create(['title' => 'Mesa lista']);
    $b = Item::factory()->for($plan)->create(['title' => 'Diseñar segundo cerebro completo']);
    $c = Item::factory()->for($plan)->create(['title' => 'Captura 10 min']);
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
    ItemDependency::query()->create(['prerequisite_id' => $b->id, 'dependent_id' => $c->id]);

    return [$owner, $objective, $plan, $a, $b, $c];
}

test('an item retired through the protocol leaves the track, shows beside it with its reason, and comes back on restore', function () {
    [$owner, $objective, $plan, $a, $b, $c] = integGraphSetup();

    $retirement = app(RetireElement::class)(Actor::ownerWeb($owner), $b, 'planificar sin práctica', RetirementDecision::ArchiveAsIs)->retirement;

    expect($retirement->user_id)->toBe($owner->id)
        ->and($retirement->objective_id)->toBe($objective->id);

    $graph = UnlockGraph::forObjective($objective->fresh());

    expect(array_column($graph['nodes'], 'key'))->toBe(['DIARIO-1', 'DIARIO-3'])
        ->and($graph['edges'])->toBe([])
        ->and($graph['retired'])->toBe([
            ['key' => 'DIARIO-2', 'title' => 'Diseñar segundo cerebro completo', 'plan_id' => $plan->id, 'reason' => 'planificar sin práctica'],
        ]);

    app(RestoreElement::class)(Actor::ownerWeb($owner), $retirement->fresh());

    $graph = UnlockGraph::forObjective($objective->fresh());

    expect(array_column($graph['nodes'], 'key'))->toBe(['DIARIO-1', 'DIARIO-2', 'DIARIO-3'])
        ->and(count($graph['edges']))->toBe(2)
        ->and($graph['retired'])->toBe([]);
});

test('a plan retired as-is takes its items off the map; the cascaded marks keep the shared reason', function () {
    [$owner, $objective, $plan] = integGraphSetup();
    $other = Plan::factory()->for($objective)->withControlPlan()->create(['title' => 'Semana 2']);
    Item::factory()->for($other)->create(['title' => 'Elegir acción del lunes']);

    app(RetireElement::class)(Actor::ownerWeb($owner), $plan, 'el plan era demasiado grande', RetirementDecision::ArchiveAsIs);

    $graph = UnlockGraph::forObjective($objective->fresh());

    expect(array_column($graph['plans'], 'id'))->toBe([$other->id])
        ->and(array_column($graph['nodes'], 'title'))->toBe(['Elegir acción del lunes'])
        ->and($graph['edges'])->toBe([])
        ->and(collect($graph['retired'])->pluck('reason')->unique()->all())->toBe(['el plan era demasiado grande'])
        ->and(Retirement::query()->whereNotNull('parent_id')->count())->toBe(3);

    $this->actingAs($owner)->get(route('map.show', 'DIARIO'))->assertOk();
});

test('the graph Now task is the Now screen task and its station links to Ahora', function () {
    [$owner, , , $a, $b, $c] = integGraphSetup();
    Item::query()->whereKey([$a->id, $b->id])->update(['completed_at' => now()]);
    app(StartItem::class)(Actor::ownerWeb($owner), $c->fresh());

    $this->actingAs($owner)->get(route('map.show', 'DIARIO'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('graphs/objective')
            ->where('graph.now.key', 'DIARIO-3'));

    $this->actingAs($owner)->get(route('now'))
        ->assertInertia(fn (Assert $page) => $page->where('now.key', 'DIARIO-3'));

    expect(file_get_contents(resource_path('js/pages/graphs/objective.tsx')))
        ->toContain('Seguir en Ahora')
        ->toContain("import { now } from '@/routes';");
});
