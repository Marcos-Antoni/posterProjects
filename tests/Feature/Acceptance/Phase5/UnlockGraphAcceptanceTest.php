<?php

/*
| Phase 5 acceptance (independent tester) — unlock-graph, retirement "hidden
| everywhere" and mcp-server "read views match the web": web ↔ MCP parity
| with retired items, retired plans and closed objectives; graph integrity
| (every edge end is drawn); stubs never leak retired objectives nor link to
| a map that 404s; non-disclosure; constant queries; deterministic layout.
*/

use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function p5aLink(Item $a, Item $b): void
{
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
}

/** @return array<string, mixed> */
function p5aProps(User $owner, string $uri): array
{
    return test()->actingAs($owner)->get($uri)->assertOk()->viewData('page')['props'];
}

/** @return array<string, mixed> */
function p5aTool(User $owner, string $tool, array $arguments = []): array
{
    app('auth')->forgetGuards();
    $token = $owner->createToken('mcp', ['mcp'])->plainTextToken;
    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => (object) $arguments],
    ], ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream'])->assertOk();

    $text = (string) $response->json('result.content.0.text');

    return json_decode($text, true) ?? ['__error' => $text, '__isError' => $response->json('result.isError')];
}

/**
 * RARO (active): plan 1 with done/active/locked items, a retired item with a
 * retirement row, a retired plan still holding a non-retired item linked in;
 * CERRADO (closed) and VIEJO (retired) and BORRADOR (draft) objectives each
 * with an item linked to RARO.
 *
 * @return array{0: User, 1: array<string, Item>}
 */
function p5aSeed(): array
{
    $owner = User::factory()->create();
    $raro = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'RARO', 'position' => 0]);
    $p1 = Plan::factory()->for($raro)->create(['position' => 1]);
    $gone = Plan::factory()->for($raro)->create(['position' => 2, 'state' => PlanState::Retired]);

    $a = Item::factory()->for($p1)->done()->create(['title' => 'A']);
    $b = Item::factory()->for($p1)->create(['title' => 'B']);
    $c = Item::factory()->for($p1)->milestone()->create(['title' => 'C']);
    $r = Item::factory()->for($p1)->retired()->create(['title' => 'Retirada']);
    DB::table('retirements')->insert(['retirable_type' => 'item', 'retirable_id' => $r->id, 'reason' => 'demasiado grande', 'decision' => 'archive', 'retired_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $orphan = Item::factory()->for($gone)->create(['title' => 'En plan retirado']);

    p5aLink($a, $b);
    p5aLink($b, $c);
    p5aLink($r, $c);
    p5aLink($orphan, $c);

    $closed = Objective::factory()->for($owner)->create(['key' => 'CERRADO', 'state' => ObjectiveState::Closed, 'position' => 1]);
    $retired = Objective::factory()->for($owner)->create(['key' => 'VIEJO', 'state' => ObjectiveState::Retired, 'position' => 2]);
    $draft = Objective::factory()->for($owner)->create(['key' => 'BORRADOR', 'state' => ObjectiveState::Draft, 'position' => 3]);
    $fromClosed = Item::factory()->for(Plan::factory()->for($closed))->done()->create(['title' => 'De cerrado']);
    $fromRetired = Item::factory()->for(Plan::factory()->for($retired))->create(['title' => 'De objetivo retirado']);
    $toDraft = Item::factory()->for(Plan::factory()->for($draft))->create(['title' => 'De borrador']);
    p5aLink($fromClosed, $b);
    p5aLink($fromRetired, $c);
    p5aLink($a, $toDraft);

    return [$owner, compact('a', 'b', 'c', 'r', 'orphan', 'fromClosed', 'fromRetired', 'toDraft')];
}

/** @param array<string, mixed> $graph */
function p5aStrip(array $graph): array
{
    unset($graph['url'], $graph['objective']['url']);

    return $graph;
}

test('objective-graph (MCP) returns exactly the web graph of screen 8, with retired items, a retired plan and external links', function () {
    [$owner] = p5aSeed();

    $web = p5aProps($owner, '/map/RARO')['graph'];
    $mcp = p5aTool($owner, 'objective-graph', ['objective_key' => 'RARO']);

    expect(p5aStrip($mcp))->toEqual($web)
        ->and($mcp['url'])->toStartWith('http')->toEndWith('/map/RARO');
});

test('objective-graph (MCP) matches the web on a closed objective, both read-only', function () {
    [$owner] = p5aSeed();

    $web = p5aProps($owner, '/map/CERRADO')['graph'];
    $mcp = p5aTool($owner, 'objective-graph', ['objective_key' => 'CERRADO']);

    expect(p5aStrip($mcp))->toEqual($web)
        ->and($web['objective']['is_writable'])->toBeFalse();
});

test('global-graph (MCP) returns exactly the web global graph', function () {
    [$owner] = p5aSeed();

    $web = p5aProps($owner, '/map')['graph'];
    $mcp = p5aTool($owner, 'global-graph');

    expect(p5aStrip($mcp))->toEqual($web)
        ->and(array_column($web['objectives'], 'key'))->toBe(['RARO']);
});

test('a retired item is never a node, a stub or an edge end, on the web or MCP', function () {
    [$owner] = p5aSeed();

    foreach ([p5aProps($owner, '/map/RARO')['graph'], p5aTool($owner, 'objective-graph', ['objective_key' => 'RARO'])] as $graph) {
        $drawn = [...array_column($graph['nodes'], 'key'), ...array_column($graph['stubs'], 'key')];
        expect($drawn)->not->toContain('RARO-4')
            ->and(array_merge(array_column($graph['edges'], 'from'), array_column($graph['edges'], 'to')))->not->toContain('RARO-4')
            ->and($graph['retired'])->toBe([['key' => 'RARO-4', 'title' => 'Retirada', 'plan_id' => $graph['retired'][0]['plan_id'], 'reason' => 'demasiado grande']]);
    }
});

test('every edge of the objective graph ends on a drawn node or stub (no dangling ends, e.g. items of a retired plan)', function () {
    [$owner] = p5aSeed();
    $graph = p5aProps($owner, '/map/RARO')['graph'];
    $drawn = [...array_column($graph['nodes'], 'key'), ...array_column($graph['stubs'], 'key')];

    foreach ($graph['edges'] as $edge) {
        expect($drawn)->toContain($edge['from'])->toContain($edge['to']);
    }
});

test('every edge of the global graph ends on a node of an active objective', function () {
    [$owner] = p5aSeed();
    $graph = p5aProps($owner, '/map')['graph'];
    $drawn = array_merge(...array_map(fn (array $c) => array_column($c['nodes'], 'key'), $graph['objectives']));

    foreach ($graph['edges'] as $edge) {
        expect($drawn)->toContain($edge['from'])->toContain($edge['to']);
    }
});

test('items of a retired objective are hidden from the graph: never a stub (retirement spec)', function () {
    [$owner] = p5aSeed();
    $graph = p5aProps($owner, '/map/RARO')['graph'];

    expect(array_column($graph['stubs'], 'objective_key'))->not->toContain('VIEJO');
});

test('every stub links to an objective map that opens (no stub pointing to a 404 map)', function () {
    [$owner] = p5aSeed();
    $graph = p5aProps($owner, '/map/RARO')['graph'];

    foreach (array_unique(array_column($graph['stubs'], 'objective_key')) as $key) {
        $this->actingAs($owner)->get("/map/{$key}")->assertOk();
    }
});

test('the Now task is never an item of a closed objective nor a retired item', function () {
    $owner = User::factory()->create();
    $closed = Objective::factory()->for($owner)->create(['key' => 'CERRADO', 'state' => ObjectiveState::Closed]);
    Item::factory()->for(Plan::factory()->for($closed))->active()->create();

    expect(p5aProps($owner, '/map')['graph']['now'])->toBeNull()
        ->and(p5aTool($owner, 'global-graph')['now'])->toBeNull()
        ->and(p5aProps($owner, '/map/CERRADO')['graph']['now'])->toBeNull();
});

test('another owner, a draft, a retired or an unknown objective: web 404 and MCP error with the same text (non-disclosure)', function () {
    [$owner] = p5aSeed();
    $stranger = User::factory()->create();
    Objective::factory()->for($stranger)->create(['key' => 'AJENO']);

    foreach (['AJENO', 'BORRADOR', 'VIEJO', 'NOEXISTE', 'raro'] as $key) {
        $this->actingAs($owner)->get("/map/{$key}")->assertNotFound();
        $mcp = p5aTool($owner, 'objective-graph', ['objective_key' => $key]);
        expect($mcp)->toHaveKey('__error')
            ->and($mcp['__error'])->toBe("Objective not found: {$key}")
            ->and($mcp['__error'])->not->toContain($stranger->email);
    }

    expect(p5aTool($stranger, 'global-graph')['objectives'])->toBeArray()
        ->and(array_column(p5aTool($stranger, 'global-graph')['objectives'], 'key'))->toBe(['AJENO'])
        ->and(json_encode(p5aProps($stranger, '/map')))->not->toContain('RARO');
});

test('a guest gets no graph and no MCP data', function () {
    $this->get('/map')->assertRedirect('/login');
    $this->get('/map/RARO')->assertRedirect('/login');
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'global-graph', 'arguments' => (object) []]])->assertUnauthorized();
});

test('both graph pages and both tools run a constant number of queries: 1 vs 40 items with stubs, retired items and cross edges', function () {
    $measure = function (int $size): array {
        $owner = User::factory()->create();
        $main = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'M'.$size]);
        $other = Objective::factory()->for($owner)->create(['key' => 'O'.$size, 'position' => 1]);
        $plans = [Plan::factory()->for($main)->create(), Plan::factory()->for($main)->create()];
        $op = Plan::factory()->for($other)->create();
        $prev = null;
        foreach (range(1, $size) as $i) {
            $item = Item::factory()->for($plans[$i % 2])->create();
            $ext = Item::factory()->for($op)->create();
            p5aLink($ext, $item);
            if ($prev) {
                p5aLink($prev, $item);
            }
            $gone = Item::factory()->for($plans[$i % 2])->retired()->create();
            DB::table('retirements')->insert(['retirable_type' => 'item', 'retirable_id' => $gone->id, 'reason' => 'x', 'decision' => 'archive', 'retired_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $prev = $item;
        }

        return [
            mosQueryCount(fn () => test()->actingAs($owner)->get('/map/M'.$size)->assertOk()),
            mosQueryCount(fn () => test()->actingAs($owner)->get('/map')->assertOk()),
            mosQueryCount(fn () => p5aTool($owner, 'objective-graph', ['objective_key' => 'M'.$size])),
            mosQueryCount(fn () => p5aTool($owner, 'global-graph')),
        ];
    };

    expect($measure(1))->toBe($measure(40));
});

test('the layout is deterministic: the same graph gives the same rows and lanes, whatever order the edges were drawn in', function () {
    $build = function (bool $reverse): array {
        $owner = User::factory()->create();
        $key = $reverse ? 'DETB' : 'DETA';
        $objective = Objective::factory()->for($owner)->create(['key' => $key]);
        $p1 = Plan::factory()->for($objective)->create(['position' => 1]);
        $p2 = Plan::factory()->for($objective)->create(['position' => 2]);
        $items = [];
        foreach (range(0, 11) as $i) {
            $items[] = Item::factory()->for($i < 6 ? $p1 : $p2)->create();
        }
        $pairs = [[0, 1], [0, 2], [0, 3], [1, 4], [2, 4], [3, 5], [4, 6], [5, 6], [6, 7], [6, 8], [0, 9], [9, 10], [8, 11], [2, 11]];
        foreach ($reverse ? array_reverse($pairs) : $pairs as [$x, $y]) {
            p5aLink($items[$x], $items[$y]);
        }

        $first = p5aProps($owner, "/map/{$key}");
        $again = p5aProps($owner, "/map/{$key}");
        expect($again['layout'])->toBe($first['layout']);

        $layout = [];
        foreach ($first['layout'] as $node => $spot) {
            $layout[substr($node, strlen($key))] = $spot;
        }
        ksort($layout);

        return $layout;
    };

    expect($build(false))->toBe($build(true));
});
