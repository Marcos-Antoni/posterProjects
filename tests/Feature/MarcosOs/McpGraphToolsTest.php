<?php

use App\Http\Resources\UnlockGraph;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Graphs\GlobalGraph;
use App\Mcp\Tools\Graphs\ObjectiveGraph;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/*
| Task 5.4 — MCP `objective-graph` and `global-graph` (mcp-server spec "Read
| Views Match The Web"): the same nodes and edges as the web graphs, with
| the absolute web URL, read tier, scoped to the owner.
*/

/**
 * Call a tool over real JSON-RPC and decode its JSON payload.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function callGraphTool(User $owner, string $tool, array $arguments = []): array
{
    $token = $owner->createToken('mcp', ['mcp'])->plainTextToken;
    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => (object) $arguments],
    ], ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream'])->assertOk();

    return json_decode((string) $response->json('result.content.0.text'), true);
}

function seedGraphForMcp(User $owner): void
{
    $diario = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'position' => 0]);
    $web = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'WEB', 'position' => 1]);
    $plan = Plan::factory()->for($diario)->create();
    $a = Item::factory()->for($plan)->done()->create();
    $b = Item::factory()->for($plan)->active()->create();
    $c = Item::factory()->for($plan)->milestone()->create();
    Item::factory()->for($plan)->retired()->create();
    $w = Item::factory()->for(Plan::factory()->for($web))->create();
    foreach ([[$a, $b], [$b, $c], [$c, $w]] as [$prerequisite, $dependent]) {
        ItemDependency::query()->create(['prerequisite_id' => $prerequisite->id, 'dependent_id' => $dependent->id]);
    }
}

test('both graph tools are read-tier and read-only', function (string $tool) {
    expect((string) app($tool)->description())->toStartWith('Nivel IA: read.');
})->with([ObjectiveGraph::class, GlobalGraph::class]);

test('objective-graph returns the same nodes, edges and stubs as the web graph, with its web url', function () {
    $owner = User::factory()->create();
    seedGraphForMcp($owner);

    $payload = callGraphTool($owner, 'objective-graph', ['objective_key' => 'DIARIO']);
    $web = $this->actingAs($owner)->get(route('map.show', 'DIARIO'))->viewData('page')['props']['graph'];

    expect($payload['nodes'])->toBe($web['nodes'])
        ->and($payload['edges'])->toBe($web['edges'])
        ->and($payload['stubs'])->toBe($web['stubs'])
        ->and($payload['retired'])->toBe($web['retired'])
        ->and($payload['now'])->toBe($web['now'])
        ->and($payload['next_milestone'])->toBe($web['next_milestone'])
        ->and(array_column($payload['nodes'], 'key'))->toBe(['DIARIO-1', 'DIARIO-2', 'DIARIO-3'])
        ->and(array_column($payload['stubs'], 'key'))->toBe(['WEB-1'])
        ->and($payload['url'])->toBe(route('map.show', 'DIARIO'))
        ->and($payload['objective']['url'])->toBe(route('objectives.show', 'DIARIO'));
});

test('objective-graph fails closed for another owner, a retired or an unknown objective', function (string $key) {
    $owner = User::factory()->create();
    Objective::factory()->create(['key' => 'AJENO']);
    Objective::factory()->for($owner)->retired()->create(['key' => 'VIEJO']);

    PosterServer::actingAs($owner)->tool(ObjectiveGraph::class, ['objective_key' => $key])
        ->assertHasErrors(['Objective not found']);
})->with(['AJENO', 'VIEJO', 'NADA']);

test('objective-graph works on a closed objective, read-only like the web', function () {
    $owner = User::factory()->create();
    Objective::factory()->for($owner)->closed()->create(['key' => 'CERRADO']);

    PosterServer::actingAs($owner)->tool(ObjectiveGraph::class, ['objective_key' => 'CERRADO'])
        ->assertOk()
        ->assertSee('"is_writable":false');
});

test('global-graph returns the same clusters, edges and Now highlight as the web global graph', function () {
    $owner = User::factory()->create();
    seedGraphForMcp($owner);
    Objective::factory()->create(['key' => 'AJENO']);

    $payload = callGraphTool($owner, 'global-graph');
    $web = $this->actingAs($owner)->get(route('map.index'))->viewData('page')['props']['graph'];

    expect($payload['objectives'])->toBe($web['objectives'])
        ->and($payload['edges'])->toBe($web['edges'])
        ->and($payload['now'])->toBe($web['now'])
        ->and($payload['now_unlocks'])->toBe(['DIARIO-3'])
        ->and($payload['edges'])->toContain(['from' => 'DIARIO-3', 'to' => 'WEB-1', 'cross' => true])
        ->and(array_column($payload['objectives'], 'key'))->toBe(['DIARIO', 'WEB'])
        ->and($payload['url'])->toBe(route('map.index'));
});

test('the graph tools return exactly the shared read models', function () {
    $owner = User::factory()->create();
    seedGraphForMcp($owner);
    $objective = Objective::query()->where('key', 'DIARIO')->firstOrFail();

    $objectivePayload = callGraphTool($owner, 'objective-graph', ['objective_key' => 'DIARIO']);
    $globalPayload = callGraphTool($owner, 'global-graph');

    unset($objectivePayload['url'], $objectivePayload['objective']['url'], $globalPayload['url']);

    expect($objectivePayload)->toEqual(UnlockGraph::forObjective($objective))
        ->and($globalPayload)->toEqual(UnlockGraph::global($owner));
});
