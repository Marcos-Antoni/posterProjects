<?php

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\ItemTwoMinuteHistory;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
| Tasks 2.13 / 2.14 — the objective, plan and item screens (inventory 3–7):
| props that feed the mockup structure, the whole tree with retired items
| hidden, item deep links that fail closed, and target dates never rendered
| as failure.
*/

test('guests are sent to login from every objective screen', function (string $uri) {
    $this->get($uri)->assertRedirect(route('login'));
})->with(['/objectives', '/objectives/create', '/objectives/SALUD', '/objectives/SALUD/items/SALUD-1']);

test('the objectives index gives each active objective its line, metric and next concrete step', function () {
    $owner = User::factory()->create();
    $first = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'position' => 0]);
    $second = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'FINALES', 'position' => 1]);
    $plan = Plan::factory()->for($first)->create();
    $done = Item::factory()->for($plan)->done()->create();
    $active = Item::factory()->for($plan)->active()->create(['title' => 'Captura 10 min']);
    Item::factory()->for($plan)->milestone()->create(['title' => 'Semana 1']);
    $locked = Item::factory()->for(Plan::factory()->for($second))->create();
    ItemDependency::query()->create(['prerequisite_id' => $active->id, 'dependent_id' => $locked->id]);

    $this->actingAs($owner)->get(route('objectives.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('objectives/index')
            ->has('objectives', 2)
            ->where('objectives.0.key', 'DIARIO')
            ->where('objectives.0.line', 1)
            ->where('objectives.1.line', 2)
            ->where('objectives.0.control_plan.metric.name', $first->controlPlan->metric_name)
            ->where('objectives.0.next.key', 'DIARIO-2')
            ->where('objectives.0.next.state', 'active')
            ->where('objectives.0.next_milestone', ['title' => 'Semana 1', 'remaining' => 1])
            ->where('objectives.1.next.state', 'locked')
            ->where('objectives.1.next.waiting_on.0.title', 'Captura 10 min')
            ->where('objectives.1.next.waiting_on.0.external', true));
});

test('the objective screen shows the tree with done and available items and hides retired ones', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create();
    Item::factory()->for($plan)->done()->create(['title' => 'Hecha']);
    Item::factory()->for($plan)->create(['title' => 'Disponible']);
    Item::factory()->for($plan)->retired()->create(['title' => 'Retirada']);

    $this->actingAs($owner)->get(route('objectives.show', 'SALUD'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('objectives/show')
            ->where('objective.key', 'SALUD')
            ->where('objective.is_writable', true)
            ->has('plans', 1)
            ->where('plans.0.items.0.title', 'Hecha')
            ->where('plans.0.items.0.state', 'done')
            ->where('plans.0.items.1.title', 'Disponible')
            ->where('plans.0.items.1.state', 'available')
            ->has('plans.0.items', 2)
            ->where('plans.0.retired_titles', ['Retirada'])
            ->where('controlPlan.missing', []));
});

test('a closed objective is readable but not writable; draft, retired and foreign ones are 404', function () {
    $owner = User::factory()->create();
    Objective::factory()->for($owner)->closed()->withControlPlan()->create(['key' => 'CERRADO']);
    Objective::factory()->for($owner)->draft()->create(['key' => 'BORRADOR']);
    Objective::factory()->for($owner)->retired()->withControlPlan()->create(['key' => 'RETIRADO']);
    Objective::factory()->withControlPlan()->create(['key' => 'AJENO']);
    $this->actingAs($owner);

    $this->get('/objectives/CERRADO')->assertOk()->assertInertia(fn ($page) => $page->where('objective.is_writable', false));

    foreach (['BORRADOR', 'RETIRADO', 'AJENO', 'NADA'] as $key) {
        $this->get("/objectives/{$key}")->assertNotFound();
    }
});

test('the level suggestion appears at 80% and never activates the next level', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $level1 = Plan::factory()->for($objective)->withControlPlan()->create(['title' => 'Semana 1', 'level' => 1]);
    Item::factory()->for($level1)->done()->count(8)->create();
    Item::factory()->for($level1)->count(2)->create();
    $level2 = Plan::factory()->for($objective)->draft()->create(['level' => 2]);

    $this->actingAs($owner)->get(route('objectives.show', 'DIARIO'))
        ->assertInertia(fn ($page) => $page->where('levelSuggestion', ['from_title' => 'Semana 1', 'next_level' => 2]));

    expect($level2->fresh()->state->value)->toBe('draft');
});

test('the plan screen lists its items in order with the next number and cross-objective candidates', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->withControlPlan()->create(['level' => 1, 'title' => 'Semana 1']);
    Item::factory()->for($plan)->count(2)->create();
    $other = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'WEB']);
    Item::factory()->for(Plan::factory()->for($other))->create(['title' => 'Especificar Ahora']);

    $this->actingAs($owner)->get(route('objectives.plans.show', ['DIARIO', $plan->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('plans/show')
            ->where('plan.title', 'Semana 1')
            ->has('plan.items', 2)
            ->where('nextNumber', 3)
            ->where('ladder.0.level', 1)
            ->where('prerequisiteCandidates', fn ($candidates) => collect($candidates)->contains('key', 'WEB-1')));
});

test('a plan of another objective or a retired plan is not found', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $other = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'OTRO']);
    $foreignPlan = Plan::factory()->for($other)->create();
    $retiredPlan = Plan::factory()->for($objective)->create(['state' => 'retired']);
    $this->actingAs($owner);

    $this->get(route('objectives.plans.show', ['DIARIO', $foreignPlan->id]))->assertNotFound();
    $this->get(route('objectives.plans.show', ['DIARIO', $retiredPlan->id]))->assertNotFound();
});

test('the item deep link renders the complete payload as a full page', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario']);
    $plan = Plan::factory()->for($objective)->create(['title' => 'Semana 1']);
    $pre = Item::factory()->for($plan)->done()->create(['title' => 'Mesa lista']);
    $item = Item::factory()->for($plan)->create(['title' => 'Captura 10 min', 'two_minute_version' => 'abrir el inbox']);
    $next = Item::factory()->for($plan)->create(['title' => 'Clasificar']);
    ItemDependency::query()->create(['prerequisite_id' => $pre->id, 'dependent_id' => $item->id]);
    ItemDependency::query()->create(['prerequisite_id' => $item->id, 'dependent_id' => $next->id]);
    ItemTwoMinuteHistory::query()->create(['item_id' => $item->id, 'text' => 'hacer la captura completa', 'source' => 'owner', 'replaced_at' => now()]);

    $this->actingAs($owner)->get('/objectives/DIARIO/items/DIARIO-2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('items/show')
            ->where('item.key', 'DIARIO-2')
            ->where('item.kind', 'task')
            ->where('item.state', 'available')
            ->where('item.plan', ['id' => $plan->id, 'title' => 'Semana 1'])
            ->where('objective.key', 'DIARIO')
            ->where('objective.title', 'Marcos OS en uso diario')
            ->where('item.prerequisites', [['key' => 'DIARIO-1', 'title' => 'Mesa lista', 'state' => 'done']])
            ->where('item.unlocks', [['key' => 'DIARIO-3', 'title' => 'Clasificar', 'state' => 'locked']])
            ->where('twoMinuteVersions.0.text', 'abrir el inbox')
            ->where('twoMinuteVersions.0.current', true)
            ->where('twoMinuteVersions.1.text', 'hacer la captura completa')
            ->where('twoMinuteVersions.1.source', 'created'));
});

test('every malformed or unauthorized item key is a 404, never an error or a leak', function (string $path) {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create();
    Item::factory()->for($plan)->create();
    Item::factory()->for($plan)->retired()->create();
    $dinero = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DINERO']);
    Item::factory()->for(Plan::factory()->for($dinero))->create();
    $foreign = Objective::factory()->withControlPlan()->create(['key' => 'AJENO']);
    Item::factory()->for(Plan::factory()->for($foreign))->create();

    $this->actingAs($owner)->get($path)->assertNotFound();
})->with([
    'no dash' => '/objectives/SALUD/items/SALUD1',
    'non-numeric suffix' => '/objectives/SALUD/items/SALUD-uno',
    'empty suffix' => '/objectives/SALUD/items/SALUD-',
    'unknown number' => '/objectives/SALUD/items/SALUD-99',
    'prefix mismatch' => '/objectives/SALUD/items/DINERO-1',
    'another owner' => '/objectives/AJENO/items/AJENO-1',
    'retired item' => '/objectives/SALUD/items/SALUD-2',
    'huge number' => '/objectives/SALUD/items/SALUD-99999999999999999999',
]);

test('a past target date is flagged neutrally and never changes the state', function () {
    Carbon::setTestNow('2026-09-27 18:00:00');
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    Item::factory()->for(Plan::factory()->for($objective))->create(['target_date' => '2026-09-26']);

    $this->actingAs($owner)->get('/objectives/SALUD/items/SALUD-1')
        ->assertInertia(fn ($page) => $page
            ->where('item.state', 'available')
            ->where('item.target_date', '2026-09-26')
            ->where('item.target_date_passed', true));
});

test('the item screen never ships overdue wording', function () {
    $source = collect(['items/show.tsx', 'objectives/show.tsx', 'objectives/index.tsx', 'plans/show.tsx'])
        ->map(fn (string $page) => file_get_contents(resource_path("js/pages/{$page}")))
        ->implode("\n");

    expect($source)->not->toContain('vencid')
        ->not->toContain('atrasad')
        ->not->toContain('overdue')
        ->not->toContain('destructive');
});

test('creating an objective through the web lands on its screen', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('objectives.store'), mosObjectiveData(['key' => 'WEB']))
        ->assertRedirect(route('objectives.show', 'WEB'));

    expect(Objective::query()->where('key', 'WEB')->value('state')->value)->toBe('active');
});
