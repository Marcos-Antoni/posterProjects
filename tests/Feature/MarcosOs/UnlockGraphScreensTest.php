<?php

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/*
| Tasks 5.2 / 5.3 — the per-objective graph (screen 8) and the global graph
| (screen 9): owner-only pages fed by the shared read models plus the track
| layout; dependencies are added and removed from the graph with the
| existing dependency routes, which come back to the graph with the cycle
| error or the unlocked items.
*/

function screenEdge(Item $prerequisite, Item $dependent): void
{
    ItemDependency::query()->create(['prerequisite_id' => $prerequisite->id, 'dependent_id' => $dependent->id]);
}

test('guests are sent to login from both graphs', function (string $uri) {
    $this->get($uri)->assertRedirect(route('login'));
})->with(['/map', '/map/SALUD']);

test('the per-objective graph is a 404 for another owner, a draft, a retired or an unknown objective', function (string $state) {
    $owner = User::factory()->create();
    $objective = match ($state) {
        'foreign' => Objective::factory()->create(['key' => 'AJENO']),
        'draft' => Objective::factory()->for($owner)->draft()->create(['key' => 'AJENO']),
        'retired' => Objective::factory()->for($owner)->retired()->create(['key' => 'AJENO']),
        default => null,
    };

    $this->actingAs($owner)->get('/map/AJENO')->assertNotFound();
})->with(['foreign', 'draft', 'retired', 'unknown']);

test('the per-objective graph renders screen 8 with the read model and the track layout', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $d3 = Item::factory()->for($plan)->active()->create();
    [$d4, $d5, $d6] = Item::factory()->for($plan)->count(3)->create()->all();
    $d7 = Item::factory()->for($plan)->milestone()->create();
    foreach ([$d4, $d5, $d6] as $parallel) {
        screenEdge($d3, $parallel);
        screenEdge($parallel, $d7);
    }

    $this->actingAs($owner)->get(route('map.show', 'DIARIO'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('graphs/objective')
            ->where('graph.objective.key', 'DIARIO')
            ->has('graph.nodes', 5)
            ->has('graph.edges', 6)
            ->where('graph.now.key', 'DIARIO-1')
            ->where('graph.next_milestone.remaining', 4)
            ->where('layout.DIARIO-1', ['row' => 0, 'lane' => 0])
            ->where('layout.DIARIO-2', ['row' => 1, 'lane' => 0])
            ->where('layout.DIARIO-3', ['row' => 1, 'lane' => 1])
            ->where('layout.DIARIO-4', ['row' => 1, 'lane' => 2])
            ->where('layout.DIARIO-5', ['row' => 2, 'lane' => 0]));
});

test('the track routes every edge and every sink to the goal, one waypoint per row; stubs stay out of the layout', function () {
    $owner = User::factory()->create();
    $dinero = Objective::factory()->for($owner)->create(['key' => 'DINERO']);
    $salud = Objective::factory()->for($owner)->create(['key' => 'SALUD']);
    $first = Plan::factory()->for($salud)->create(['position' => 0]);
    $second = Plan::factory()->for($salud)->create(['position' => 1]);
    $a = Item::factory()->for($first)->create();
    Item::factory()->for($first)->create();
    $d = Item::factory()->for($second)->create();
    $in = Item::factory()->for(Plan::factory()->for($dinero))->create();
    screenEdge($a, $d);
    screenEdge($in, $d);

    $this->actingAs($owner)->get('/map/SALUD')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('graph.stubs', 1)
            ->missing('layout.DINERO-1')
            ->where('layout.SALUD-1', ['row' => 0, 'lane' => 0])
            ->where('layout.SALUD-3', ['row' => 1, 'lane' => 0])
            ->where('goals.SALUD', ['row' => 2, 'lane' => 0])
            ->has('routes', 3)
            ->where('routes.0.from', 'SALUD-1')
            ->where('routes.0.to', 'SALUD-3')
            // SALUD-2 is independent: it joins the goal on its own lane,
            // through a waypoint beside SALUD-3, never through it.
            ->where('routes.1.from', 'SALUD-2')
            ->where('routes.1.points.1', ['row' => 1, 'lane' => 1, 'group' => 'SALUD'])
            ->where('routes.2.from', 'SALUD-3'));
});

test('items of a retired plan, a retired objective or a draft objective leave no edge and no stub (P5-T6/T7)', function () {
    $owner = User::factory()->create();
    $raro = Objective::factory()->for($owner)->create(['key' => 'RARO']);
    $plan = Plan::factory()->for($raro)->create();
    $gone = Plan::factory()->for($raro)->create(['state' => 'retired']);
    $b = Item::factory()->for($plan)->create();
    $orphan = Item::factory()->for($gone)->create();
    $old = Objective::factory()->for($owner)->retired()->create(['key' => 'VIEJO']);
    $draft = Objective::factory()->for($owner)->draft()->create(['key' => 'BORRADOR']);
    screenEdge($orphan, $b);
    screenEdge(Item::factory()->for(Plan::factory()->for($old))->create(), $b);
    screenEdge($b, Item::factory()->for(Plan::factory()->for($draft))->create());

    $this->actingAs($owner)->get('/map/RARO')
        ->assertInertia(fn ($page) => $page
            ->where('graph.edges', [])
            ->where('graph.stubs', []));
});

test('a closed objective graph opens read-only', function () {
    $owner = User::factory()->create();
    Objective::factory()->for($owner)->closed()->create(['key' => 'CERRADO']);

    $this->actingAs($owner)->get('/map/CERRADO')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('graph.objective.is_writable', false));
});

test('adding a prerequisite from the graph comes back to the graph and locks the dependent', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();

    $this->actingAs($owner)->from('/map/DIARIO?sel=DIARIO-2')
        ->post(route('objectives.items.prerequisites.store', ['DIARIO', 'DIARIO-2']), ['key' => 'diario-1'])
        ->assertRedirect('/map/DIARIO?sel=DIARIO-2')
        ->assertSessionHasNoErrors();

    expect($b->fresh()->deriveState()->value)->toBe('locked');

    $this->get('/map/DIARIO')->assertInertia(fn ($page) => $page
        ->where('graph.edges', [['from' => 'DIARIO-1', 'to' => 'DIARIO-2', 'external' => false]])
        ->where('graph.nodes.1.state', 'locked'));
});

test('a cycle attempted from the graph comes back with the Spanish path message', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create(['title' => 'Alfa']);
    $b = Item::factory()->for($plan)->create(['title' => 'Beta']);
    $c = Item::factory()->for($plan)->create(['title' => 'Gama']);
    screenEdge($a, $b);
    screenEdge($b, $c);

    $this->actingAs($owner)->from('/map/DIARIO')
        ->post(route('objectives.items.unlocks.store', ['DIARIO', 'DIARIO-3']), ['key' => 'DIARIO-1'])
        ->assertRedirect('/map/DIARIO')
        ->assertSessionHasErrors(['prerequisite' => 'No se puede: armaría un círculo. Camino: DIARIO-3 Gama → DIARIO-1 Alfa → DIARIO-2 Beta → DIARIO-3 Gama.']);

    expect(ItemDependency::query()->count())->toBe(2);
});

test('removing a dependency from the graph comes back to the graph and frees the dependent', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();
    screenEdge($a, $b);

    $this->actingAs($owner)->from('/map/DIARIO')
        ->delete(route('objectives.items.unlocks.destroy', ['DIARIO', 'DIARIO-1', 'DIARIO-2']))
        ->assertRedirect('/map/DIARIO');

    expect(ItemDependency::query()->count())->toBe(0)
        ->and($b->fresh()->deriveState()->value)->toBe('available');
});

test('checking an item from the graph comes back with the items it unlocked', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->active()->create();
    $b = Item::factory()->for($plan)->create(['title' => 'Clasificar y elegir prioridad']);
    screenEdge($a, $b);

    $this->actingAs($owner)->from('/map/DIARIO')
        ->post(route('objectives.items.check', ['DIARIO', 'DIARIO-1']))
        ->assertRedirect('/map/DIARIO')
        ->assertSessionHas('unlocked', [['key' => 'DIARIO-2', 'title' => 'Clasificar y elegir prioridad']]);
});

test('the global graph renders screen 9 with clusters, cross edges, the Now task and one column per objective', function () {
    $owner = User::factory()->create();
    $diario = Objective::factory()->for($owner)->create(['key' => 'DIARIO', 'position' => 0]);
    $finales = Objective::factory()->for($owner)->create(['key' => 'FINALES', 'position' => 1]);
    $web = Objective::factory()->for($owner)->create(['key' => 'WEB', 'position' => 2]);
    $plan = Plan::factory()->for($diario)->create();
    $d1 = Item::factory()->for($plan)->active()->create();
    $d2 = Item::factory()->for($plan)->milestone()->create();
    Item::factory()->for(Plan::factory()->for($finales))->create();
    $w1 = Item::factory()->for(Plan::factory()->for($web))->create();
    screenEdge($d1, $d2);
    screenEdge($d2, $w1);

    $this->actingAs($owner)->get(route('map.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('graphs/global')
            ->has('graph.objectives', 3)
            ->where('graph.edges.1', ['from' => 'DIARIO-2', 'to' => 'WEB-1', 'cross' => true])
            ->where('graph.now.key', 'DIARIO-1')
            ->where('graph.now_unlocks', ['DIARIO-2'])
            ->where('layout.FINALES-1', ['row' => 0, 'lane' => 0])
            ->where('layout.WEB-1', ['row' => 2, 'lane' => 0])
            ->where('lineKey', 'DIARIO'));
});

test('the global graph links "Esta línea" to the first objective when nothing is active, and to none without objectives', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get('/map')->assertOk()
        ->assertInertia(fn ($page) => $page->where('graph.objectives', [])->where('lineKey', null));

    Objective::factory()->for($owner)->create(['key' => 'SEGUNDO', 'position' => 1]);
    Objective::factory()->for($owner)->create(['key' => 'PRIMERO', 'position' => 0]);

    $this->get('/map')->assertInertia(fn ($page) => $page->where('lineKey', 'PRIMERO'));
});

test('both graph pages run a fixed number of queries whatever the graph size', function () {
    $count = function (int $size): array {
        $owner = User::factory()->create();
        $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'Q'.$size]);
        $plan = Plan::factory()->for($objective)->create();
        $items = Item::factory()->for($plan)->count($size)->create();
        $items->skip(1)->each(fn (Item $item, int $index) => screenEdge($items[$index - 1], $item));

        return [
            mosQueryCount(fn () => $this->actingAs($owner)->get('/map/Q'.$size)->assertOk()),
            mosQueryCount(fn () => $this->actingAs($owner)->get('/map')->assertOk()),
        ];
    };

    expect($count(1))->toBe($count(5));
});

test('the lane cap never hides an edge touching the Now task (round 4)', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->create(['key' => 'CAP']);
    $first = Plan::factory()->for($objective)->create(['position' => 0]);
    $last = Plan::factory()->for($objective)->create(['position' => 1]);
    $chain = Item::factory()->for($first)->count(4)->create()->all();
    foreach (range(1, 3) as $i) {
        screenEdge($chain[$i - 1], $chain[$i]);
    }
    $now = Item::factory()->for($last)->active()->create();
    // Six long edges into the late plan, one of them from Now's prerequisites.
    foreach (range(1, 6) as $i) {
        $source = Item::factory()->for($first)->create();
        screenEdge($source, $i === 1 ? $now : Item::factory()->for($last)->create());
        screenEdge($chain[3], $i === 1 ? $now : Item::query()->latest('id')->first());
    }

    $props = $this->actingAs($owner)->get('/map/CAP')->assertOk()->viewData('page')['props'];
    $nowKey = 'CAP-'.$now->number;

    expect($props['hidden'])->not->toBeEmpty()
        ->and(collect($props['hidden'])->filter(fn (array $edge): bool => $edge['from'] === $nowKey || $edge['to'] === $nowKey)->all())->toBe([]);
});
