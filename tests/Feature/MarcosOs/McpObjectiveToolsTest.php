<?php

use App\Actions\Support\Actor;
use App\Actions\Support\AuditWriter;
use App\Actions\Support\Operation;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Items\CheckItem;
use App\Mcp\Tools\Items\ShowItem;
use App\Mcp\Tools\Items\UncheckItem;
use App\Mcp\Tools\Objectives\ListObjectives;
use App\Mcp\Tools\Objectives\ShowObjective;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/*
| Task 2.16 — MCP cutover (mcp-server spec): the retired tools are gone and
| list-objectives, show-objective, show-item, check-item and uncheck-item
| mirror the web, scoped per objective, with their AI tier in the
| description.
*/

test('the server lists exactly the Marcos OS tools of this phase plus the habit tools, and no retired tool', function () {
    $token = User::factory()->create()->createToken('mcp', ['mcp'])->plainTextToken;

    $names = collect($this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 50],
    ], mcpHeaders($token))->assertOk()->json('result.tools'))->pluck('name');

    expect($names->sort()->values()->all())->toBe(collect([
        'list-objectives', 'show-objective', 'show-item', 'check-item', 'uncheck-item',
        'create-habit', 'update-habit', 'retire-habit', 'restore-habit', 'retired-view', 'today-habits', 'list-habits', 'show-habit', 'log-habit-entry',
    ])->sort()->values()->all())
        ->and($names)->not->toContain('board-view', 'backlog-view', 'calendar-view', 'create-sprint', 'create-label', 'create-comment', 'force-delete-project', 'move-issue', 'list-trashed-projects', 'create-project', 'show-issue');
});

test('every Marcos OS tool states its AI tier in its description', function (string $tool, string $tier) {
    expect((string) app($tool)->description())->toContain("Nivel IA: {$tier}");
})->with([
    [ListObjectives::class, 'read'],
    [ShowObjective::class, 'read'],
    [ShowItem::class, 'read'],
    [CheckItem::class, 'minor'],
    [UncheckItem::class, 'minor'],
]);

test('list-objectives returns the same active objectives as the web, with absolute urls', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    Objective::factory()->for($owner)->closed()->withControlPlan()->create();
    Objective::factory()->withControlPlan()->create(['key' => 'AJENO']);

    PosterServer::actingAs($owner)->tool(ListObjectives::class)
        ->assertOk()
        ->assertSee('SALUD')
        ->assertSee(route('objectives.show', 'SALUD'))
        ->assertDontSee('AJENO');
});

test('show-objective returns the tree and fails closed for foreign, retired and unknown keys', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create(['title' => 'Semana 1']);
    Item::factory()->for($plan)->create(['title' => 'Mesa lista']);
    Item::factory()->for($plan)->retired()->create(['title' => 'Retirada']);
    Objective::factory()->for($owner)->retired()->withControlPlan()->create(['key' => 'VIEJO']);
    Objective::factory()->withControlPlan()->create(['key' => 'AJENO']);

    PosterServer::actingAs($owner)->tool(ShowObjective::class, ['objective_key' => 'SALUD'])
        ->assertOk()
        ->assertSee('Semana 1')
        ->assertSee('Mesa lista')
        ->assertDontSee('Retirada')
        ->assertSee(route('objectives.items.show', ['SALUD', 'SALUD-1']));

    foreach (['AJENO', 'VIEJO', 'NADA'] as $key) {
        PosterServer::actingAs($owner)->tool(ShowObjective::class, ['objective_key' => $key])->assertHasErrors(['Objective not found']);
    }
});

test('show-item matches the web payload and rejects an item key of another objective', function () {
    $owner = User::factory()->create();
    $salud = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    $dinero = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DINERO']);
    $a = Item::factory()->for(Plan::factory()->for($dinero))->create(['title' => 'Cobrar']);
    $b = Item::factory()->for(Plan::factory()->for($salud))->create(['title' => 'Correr']);
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    PosterServer::actingAs($owner)->tool(ShowItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-1'])
        ->assertOk()
        ->assertSee('"state":"locked"')
        ->assertSee('DINERO-1')
        ->assertSee(route('objectives.items.show', ['SALUD', 'SALUD-1']));

    PosterServer::actingAs($owner)->tool(ShowItem::class, ['objective_key' => 'SALUD', 'item_key' => 'DINERO-1'])
        ->assertHasErrors(['Item not found']);
});

test('check-item reports unlocked items exactly as the web, and is audited as a minor AI change', function () {
    $audit = new class implements AuditWriter
    {
        public array $operations = [];

        public function record(Actor $actor, Operation $operation, Model $target, array $before, array $after): void
        {
            $this->operations[] = [$actor->kind->value, $operation->value, $operation->tier()->value];
        }
    };
    app()->instance(AuditWriter::class, $audit);

    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create(['title' => 'Siguiente']);
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    PosterServer::actingAs($owner)->tool(CheckItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-1'])
        ->assertOk()
        ->assertSee('"unlocked":[{"key":"SALUD-2","title":"Siguiente","state":"available"}]');

    expect($a->fresh()->completed_at)->not->toBeNull()
        ->and($audit->operations)->toBe([['ai-mcp', 'check-item', 'minor']]);
});

test('a locked item cannot be checked through MCP, matching the web', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    PosterServer::actingAs($owner)->tool(CheckItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-2'])
        ->assertHasErrors(['bloqueada']);

    expect($b->fresh()->completed_at)->toBeNull();
});

test('a milestone needs evidence through MCP too', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    Item::factory()->for(Plan::factory()->for($objective))->milestone()->create();

    PosterServer::actingAs($owner)->tool(CheckItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-1'])
        ->assertHasErrors(['evidencia']);

    PosterServer::actingAs($owner)->tool(CheckItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-1', 'evidence' => 'Boceto hecho'])
        ->assertOk();
});

test('uncheck-item relocks dependents like the web', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->done()->create();
    $b = Item::factory()->for($plan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    PosterServer::actingAs($owner)->tool(UncheckItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-1'])
        ->assertOk()
        ->assertSee('"state":"available"');

    expect($a->fresh()->completed_at)->toBeNull()
        ->and($b->fresh()->deriveState()->value)->toBe('locked');
});

test('another owner\'s objective is not found through the item tools', function () {
    $owner = User::factory()->create();
    $foreign = Objective::factory()->withControlPlan()->create(['key' => 'AJENO']);
    Item::factory()->for(Plan::factory()->for($foreign))->create();

    PosterServer::actingAs($owner)->tool(CheckItem::class, ['objective_key' => 'AJENO', 'item_key' => 'AJENO-1'])
        ->assertHasErrors(['Objective not found']);
});
