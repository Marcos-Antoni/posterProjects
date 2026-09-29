<?php

use App\Http\Resources\UnlockGraph;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use App\Models\User;

/*
| Task 5.1 — graph read models (unlock-graph spec): the per-objective graph
| (every non-retired item as a node grouped by plan, its edges, external
| stubs) and the global graph (every active objective as a cluster, the
| cross-objective edges, the Now task and what it unlocks). Retired items
| never become nodes or edges.
*/

function graphEdge(Item $prerequisite, Item $dependent): void
{
    ItemDependency::query()->create(['prerequisite_id' => $prerequisite->id, 'dependent_id' => $dependent->id]);
}

test('the per-objective graph lists every non-retired item as a node, grouped by plan in order, with state and kind', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario']);
    $second = Plan::factory()->for($objective)->create(['title' => 'Semana 2', 'position' => 1]);
    $first = Plan::factory()->for($objective)->create(['title' => 'Semana 1', 'position' => 0]);
    $mesa = Item::factory()->for($first)->done()->create(['title' => 'Mesa lista']);
    $captura = Item::factory()->for($first)->active()->create(['title' => 'Captura 10 min']);
    $hito = Item::factory()->for($first)->milestone()->create(['title' => 'Semana 1: boceto']);
    $lunes = Item::factory()->for($second)->create(['title' => 'Elegir acción del lunes']);
    Item::factory()->for($first)->retired()->create(['title' => 'Diseñar segundo cerebro completo']);
    graphEdge($mesa, $captura);
    graphEdge($captura, $hito);
    graphEdge($hito, $lunes);

    $graph = UnlockGraph::forObjective($objective);

    expect($graph['objective'])->toMatchArray(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario', 'state' => 'active', 'is_writable' => true])
        ->and($graph['plans'])->toBe([
            ['id' => $first->id, 'title' => 'Semana 1'],
            ['id' => $second->id, 'title' => 'Semana 2'],
        ])
        ->and(array_column($graph['nodes'], 'key'))->toBe(['DIARIO-1', 'DIARIO-2', 'DIARIO-3', 'DIARIO-4'])
        ->and(array_column($graph['nodes'], 'state'))->toBe(['done', 'active', 'locked', 'locked'])
        ->and(array_column($graph['nodes'], 'kind'))->toBe(['task', 'task', 'milestone', 'task'])
        ->and(array_column($graph['nodes'], 'plan_id'))->toBe([$first->id, $first->id, $first->id, $second->id])
        ->and($graph['nodes'][1])->toMatchArray(['title' => 'Captura 10 min', 'two_minute_version' => $captura->two_minute_version, 'number' => 2])
        ->and($graph['edges'])->toBe([
            ['from' => 'DIARIO-1', 'to' => 'DIARIO-2', 'external' => false],
            ['from' => 'DIARIO-2', 'to' => 'DIARIO-3', 'external' => false],
            ['from' => 'DIARIO-3', 'to' => 'DIARIO-4', 'external' => false],
        ])
        ->and($graph['stubs'])->toBe([]);
});

test('retired items are excluded from nodes and edges and listed apart with their reason', function () {
    $objective = Objective::factory()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create();
    $retired = Item::factory()->for($plan)->retired()->create(['title' => 'Diseñar segundo cerebro completo']);
    $b = Item::factory()->for($plan)->create();
    graphEdge($a, $retired);
    graphEdge($retired, $b);
    // Phase 6 retirements schema (user_id, kind, objective_id…) via its factory.
    Retirement::factory()->create(['retirable_id' => $retired->id, 'reason' => 'planificar sin práctica']);

    $graph = UnlockGraph::forObjective($objective);

    expect(array_column($graph['nodes'], 'key'))->toBe(['DIARIO-1', 'DIARIO-3'])
        ->and($graph['edges'])->toBe([])
        ->and(array_column($graph['nodes'], 'state'))->toBe(['available', 'available'])
        ->and($graph['retired'])->toBe([
            ['key' => 'DIARIO-2', 'title' => 'Diseñar segundo cerebro completo', 'plan_id' => $plan->id, 'reason' => 'planificar sin práctica'],
        ]);
});

test('an external prerequisite appears as a stub linked to its objective (spec scenario)', function () {
    $owner = User::factory()->create();
    $dinero = Objective::factory()->for($owner)->create(['key' => 'DINERO', 'title' => 'Ordenar el dinero']);
    $salud = Objective::factory()->for($owner)->create(['key' => 'SALUD']);
    $a = Item::factory()->for(Plan::factory()->for($dinero))->create(['title' => 'Presupuesto del mes']);
    $b = Item::factory()->for(Plan::factory()->for($salud))->create();
    $c = Item::factory()->for(Plan::factory()->for($dinero))->create(['title' => 'Pagar el gimnasio']);
    graphEdge($a, $b);
    graphEdge($b, $c);

    $graph = UnlockGraph::forObjective($salud);

    expect(array_column($graph['nodes'], 'key'))->toBe(['SALUD-1'])
        ->and($graph['nodes'][0]['state'])->toBe('locked')
        ->and($graph['stubs'])->toBe([
            ['key' => 'DINERO-1', 'kind' => 'task', 'title' => 'Presupuesto del mes', 'state' => 'available', 'objective_key' => 'DINERO', 'objective_title' => 'Ordenar el dinero'],
            ['key' => 'DINERO-2', 'kind' => 'task', 'title' => 'Pagar el gimnasio', 'state' => 'locked', 'objective_key' => 'DINERO', 'objective_title' => 'Ordenar el dinero'],
        ])
        ->and($graph['edges'])->toBe([
            ['from' => 'DINERO-1', 'to' => 'SALUD-1', 'external' => true],
            ['from' => 'SALUD-1', 'to' => 'DINERO-2', 'external' => true],
        ]);
});

test('a retired external item is not a stub', function () {
    $owner = User::factory()->create();
    $dinero = Objective::factory()->for($owner)->create(['key' => 'DINERO']);
    $salud = Objective::factory()->for($owner)->create(['key' => 'SALUD']);
    $a = Item::factory()->for(Plan::factory()->for($dinero))->retired()->create();
    $b = Item::factory()->for(Plan::factory()->for($salud))->create();
    graphEdge($a, $b);

    $graph = UnlockGraph::forObjective($salud);

    expect($graph['stubs'])->toBe([])->and($graph['edges'])->toBe([])
        ->and($graph['nodes'][0]['state'])->toBe('available');
});

test('the per-objective graph names the Now task and the next milestone with the stations left', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $d1 = Item::factory()->for($plan)->done()->create();
    $d2 = Item::factory()->for($plan)->active()->create(['title' => 'Captura 10 min']);
    $d3 = Item::factory()->for($plan)->create();
    [$d4, $d5, $d6] = Item::factory()->for($plan)->count(3)->create()->all();
    $d7 = Item::factory()->for($plan)->milestone()->create(['title' => 'Semana 1: boceto de “Ahora”']);
    $d8 = Item::factory()->for($plan)->milestone()->create(['title' => 'Especificar “Ahora”']);
    graphEdge($d1, $d2);
    graphEdge($d2, $d3);
    foreach ([$d4, $d5, $d6] as $parallel) {
        graphEdge($d3, $parallel);
        graphEdge($parallel, $d7);
    }
    graphEdge($d7, $d8);

    $graph = UnlockGraph::forObjective($objective);

    expect($graph['now'])->toBe(['key' => 'DIARIO-2', 'title' => 'Captura 10 min', 'objective_key' => 'DIARIO', 'objective_title' => $objective->title])
        ->and($graph['next_milestone'])->toBe(['key' => 'DIARIO-7', 'title' => 'Semana 1: boceto de “Ahora”', 'remaining' => 5]);
});

test('the Now task of another objective is still named, and there is no milestone when none is open', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->create(['key' => 'DIARIO']);
    Item::factory()->for(Plan::factory()->for($objective))->milestone()->done()->create();
    $other = Objective::factory()->for($owner)->create(['key' => 'WEB']);
    Item::factory()->for(Plan::factory()->for($other))->active()->create(['title' => 'Pantalla Ahora']);

    $graph = UnlockGraph::forObjective($objective);

    expect($graph['now'])->toMatchArray(['key' => 'WEB-1', 'objective_key' => 'WEB'])
        ->and($graph['next_milestone'])->toBeNull();
});

test('the per-objective graph carries the goal: the metric and deadline of its 5-point plan', function () {
    $objective = Objective::factory()->withControlPlan()->create();
    $objective->controlPlan->update(['metric_name' => 'días con el ciclo diario completo', 'metric_target' => 14, 'metric_current' => 1, 'deadline' => '2026-10-11']);

    $graph = UnlockGraph::forObjective($objective->fresh());

    expect($graph['goal'])->toBe([
        'title' => $objective->title,
        'deadline' => '2026-10-11',
        'metric' => ['name' => 'días con el ciclo diario completo', 'current' => 1.0, 'target' => 14.0],
    ]);
});

test('a closed objective graph is read-only', function () {
    $objective = Objective::factory()->closed()->create();

    expect(UnlockGraph::forObjective($objective)['objective']['is_writable'])->toBeFalse();
});

test('the global graph has one cluster per active objective of the owner, in manual order, with its line', function () {
    $owner = User::factory()->create();
    $web = Objective::factory()->for($owner)->create(['key' => 'WEB', 'position' => 2]);
    $diario = Objective::factory()->for($owner)->create(['key' => 'DIARIO', 'position' => 0]);
    $finales = Objective::factory()->for($owner)->create(['key' => 'FINALES', 'position' => 1]);
    $salud = Objective::factory()->for($owner)->create(['key' => 'SALUD', 'position' => 3]);
    Objective::factory()->for($owner)->closed()->create(['key' => 'CERRADO']);
    Objective::factory()->for($owner)->draft()->create(['key' => 'BORRADOR']);
    Objective::factory()->for($owner)->retired()->create(['key' => 'VIEJO']);
    Objective::factory()->create(['key' => 'AJENO']);
    $plan = Plan::factory()->for($finales)->create();
    Item::factory()->for($plan)->done()->create();
    Item::factory()->for($plan)->count(2)->create();
    Item::factory()->for($plan)->retired()->create();

    $graph = UnlockGraph::global($owner);

    expect(array_column($graph['objectives'], 'key'))->toBe(['DIARIO', 'FINALES', 'WEB', 'SALUD'])
        ->and(array_column($graph['objectives'], 'line'))->toBe([1, 2, 3, 1])
        ->and($graph['objectives'][1]['progress'])->toBe(['done' => 1, 'total' => 3, 'available' => 2])
        ->and(array_column($graph['objectives'][1]['nodes'], 'key'))->toBe(['FINALES-1', 'FINALES-2', 'FINALES-3'])
        ->and($graph['objectives'][1]['retired'])->toHaveCount(1)
        ->and($graph['objectives'][1]['plans'])->toBe([['id' => $plan->id, 'title' => $plan->title]]);
});

test('cross-objective edges are visible globally (spec scenario) and flagged as cross', function () {
    $owner = User::factory()->create();
    $dinero = Objective::factory()->for($owner)->create(['key' => 'DINERO', 'position' => 0]);
    $salud = Objective::factory()->for($owner)->create(['key' => 'SALUD', 'position' => 1]);
    $closed = Objective::factory()->for($owner)->closed()->create(['key' => 'CERRADO']);
    $a = Item::factory()->for(Plan::factory()->for($dinero))->create();
    $a2 = Item::factory()->for(Plan::factory()->for($dinero))->create();
    $b = Item::factory()->for(Plan::factory()->for($salud))->create();
    $hidden = Item::factory()->for(Plan::factory()->for($closed))->create();
    graphEdge($a, $a2);
    graphEdge($a, $b);
    graphEdge($hidden, $b);

    $graph = UnlockGraph::global($owner);

    expect($graph['edges'])->toBe([
        ['from' => 'DINERO-1', 'to' => 'DINERO-2', 'cross' => false],
        ['from' => 'DINERO-1', 'to' => 'SALUD-1', 'cross' => true],
    ]);
});

test('the global graph highlights the Now task and the items it unlocks', function () {
    $owner = User::factory()->create();
    $diario = Objective::factory()->for($owner)->create(['key' => 'DIARIO', 'position' => 0]);
    $web = Objective::factory()->for($owner)->create(['key' => 'WEB', 'position' => 1]);
    $now = Item::factory()->for(Plan::factory()->for($diario))->active()->create(['title' => 'Captura 10 min']);
    $next = Item::factory()->for(Plan::factory()->for($diario))->create();
    $crossNext = Item::factory()->for(Plan::factory()->for($web))->create();
    $retiredNext = Item::factory()->for(Plan::factory()->for($web))->retired()->create();
    graphEdge($now, $next);
    graphEdge($now, $crossNext);
    graphEdge($now, $retiredNext);

    $graph = UnlockGraph::global($owner);

    expect($graph['now'])->toMatchArray(['key' => 'DIARIO-1', 'title' => 'Captura 10 min'])
        ->and($graph['now_unlocks'])->toBe(['DIARIO-2', 'WEB-1']);
});

test('the global graph is empty without active objectives, and never shows another owner', function () {
    $owner = User::factory()->create();
    Item::factory()->active()->create();

    $graph = UnlockGraph::global($owner);

    expect($graph['objectives'])->toBe([])
        ->and($graph['edges'])->toBe([])
        ->and($graph['now'])->toBeNull()
        ->and($graph['now_unlocks'])->toBe([]);
});

test('both read models run a fixed number of queries whatever the graph size', function () {
    $count = function (int $size): array {
        $owner = User::factory()->create();
        $objective = Objective::factory()->for($owner)->withControlPlan()->create();
        $other = Objective::factory()->for($owner)->create();
        $plan = Plan::factory()->for($objective)->create();
        $items = Item::factory()->for($plan)->count($size)->create();
        $external = Item::factory()->for(Plan::factory()->for($other))->count($size)->create();
        Item::factory()->for($plan)->retired()->count($size)->create();

        foreach ($items as $index => $item) {
            if ($index > 0) {
                graphEdge($items[$index - 1], $item);
            }
            graphEdge($external[$index], $item);
        }

        $objective = Objective::query()->findOrFail($objective->id);

        return [
            mosQueryCount(fn () => UnlockGraph::forObjective($objective)),
            mosQueryCount(fn () => UnlockGraph::global($owner)),
        ];
    };

    expect($count(1))->toBe($count(5));
});
