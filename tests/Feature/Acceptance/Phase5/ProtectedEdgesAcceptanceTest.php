<?php

/*
| Phase 5 acceptance round 4 (independent tester) — the lane cap never hides
| a protected edge: one touching the Now task, or between two items on the
| way from the Now task (until a milestone). Adversarial "hub" graphs: the
| Now task has 8–12 prerequisites and 8–12 dependents inside a dense random
| DAG, so the cap must be exceeded. Seeds never used before. Also on the
| global map, and deterministic.
*/

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/**
 * @return array{0: User, 1: string, 2: string}
 */
function p5pHub(int $seed, string $key, ?User $owner = null): array
{
    mt_srand($seed);
    $owner ??= User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => $key]);
    $plans = [];
    foreach (range(1, mt_rand(3, 6)) as $p) {
        $plans[] = Plan::factory()->for($objective)->create(['position' => $p]);
    }
    $count = mt_rand(50, 80);
    $items = [];
    foreach (range(0, $count - 1) as $i) {
        $kind = mt_rand(0, 9) === 0 ? 'milestone' : 'task';
        $items[] = Item::factory()->for($plans[mt_rand(0, count($plans) - 1)])->create(['title' => "n{$i}", 'kind' => $kind]);
    }
    // Rank order: the hub sits in the middle.
    $rank = range(0, $count - 1);
    shuffle($rank);
    $order = array_flip($rank); // rank => item index
    $hubRank = intdiv($count, 2);
    $hub = $order[$hubRank];
    $items[$hub]->update(['kind' => 'task']);
    $pairs = [];
    $pre = mt_rand(8, 12);
    $dep = mt_rand(8, 12);
    $below = range(0, $hubRank - 1);
    $above = range($hubRank + 1, $count - 1);
    shuffle($below);
    shuffle($above);
    foreach (array_slice($below, 0, $pre) as $r) {
        $pairs[$order[$r].'-'.$hub] = [$order[$r], $hub];
    }
    foreach (array_slice($above, 0, $dep) as $r) {
        $pairs[$hub.'-'.$order[$r]] = [$hub, $order[$r]];
    }
    for ($n = 0; $n < $count * 2; $n++) {
        $a = mt_rand(0, $count - 1);
        $b = mt_rand(0, $count - 1);
        if ($a === $b) {
            continue;
        }
        [$from, $to] = $rank[$a] < $rank[$b] ? [$a, $b] : [$b, $a];
        $pairs["{$from}-{$to}"] = [$from, $to];
    }
    ksort($pairs);
    $parents = [];
    foreach ($pairs as [$from, $to]) {
        $parents[$to][] = $from;
        ItemDependency::query()->create(['prerequisite_id' => $items[$from]->id, 'dependent_id' => $items[$to]->id]);
    }
    // Every ancestor of the hub is done; the hub is the Now task.
    $stack = $parents[$hub] ?? [];
    $seen = [];
    while ($stack !== []) {
        $x = array_pop($stack);
        if (isset($seen[$x])) {
            continue;
        }
        $seen[$x] = true;
        $items[$x]->update(['completed_at' => now()]);
        array_push($stack, ...($parents[$x] ?? []));
    }
    $items[$hub]->update(['is_active' => true]);

    return [$owner, $key, $key.'-'.$items[$hub]->fresh()->number];
}

/**
 * @param  array<string, mixed>  $graph
 * @return list<string> protected edge ids "from>to"
 */
function p5pProtected(array $nodes, array $edges, string $now): array
{
    $kind = array_column($nodes, 'kind', 'key');
    $dependents = [];
    foreach ($edges as $e) {
        $dependents[$e['from']][] = $e['to'];
    }
    $ahead = [$now => true];
    $queue = [$now];
    while ($queue !== []) {
        $k = array_shift($queue);
        if ($k !== $now && ($kind[$k] ?? null) === 'milestone') {
            continue;
        }
        foreach ($dependents[$k] ?? [] as $n) {
            if (! isset($ahead[$n]) && isset($kind[$n])) {
                $ahead[$n] = true;
                $queue[] = $n;
            }
        }
    }
    $protected = [];
    foreach ($edges as $e) {
        if ($e['from'] === $now || $e['to'] === $now || (isset($ahead[$e['from']]) && isset($ahead[$e['to']]))) {
            $protected[] = $e['from'].'>'.$e['to'];
        }
    }

    return $protected;
}

test('hub graphs: no protected edge is ever hidden on the objective map', function (int $seed) {
    [$owner, $key, $now] = p5pHub($seed, 'P'.$seed);
    $props = $this->actingAs($owner)->get("/map/{$key}")->assertOk()->viewData('page')['props'];

    expect($props['graph']['now']['key'] ?? null)->toBe($now);
    $protected = p5pProtected($props['graph']['nodes'], $props['graph']['edges'], $now);
    $hidden = array_map(fn ($h) => $h['from'].'>'.$h['to'], $props['hidden']);

    expect(array_values(array_intersect($hidden, $protected)))->toBe([])
        ->and(count(array_filter($props['graph']['edges'], fn ($e) => $e['to'] === $now)))->toBeGreaterThanOrEqual(8)
        ->and(count(array_filter($props['graph']['edges'], fn ($e) => $e['from'] === $now)))->toBeGreaterThanOrEqual(8);
})->with([1103, 1217, 1331, 1447, 1559, 1663, 1777, 1889]);

test('hub graphs: no protected edge is hidden on the global map either', function (int $seed) {
    [$owner, , $now] = p5pHub($seed, 'G'.$seed);
    $other = Objective::factory()->for($owner)->create(['key' => 'OT'.$seed, 'position' => 9]);
    $props = $this->actingAs($owner)->get('/map')->assertOk()->viewData('page')['props'];
    $nodes = array_merge(...array_map(fn ($c) => $c['nodes'], $props['graph']['objectives']));
    $protected = p5pProtected($nodes, $props['graph']['edges'], $now);
    $hidden = array_map(fn ($h) => $h['from'].'>'.$h['to'], $props['hidden']);

    expect(array_values(array_intersect($hidden, $protected)))->toBe([]);
})->with([2111, 2239, 2357]);

test('hub graphs are laid out deterministically, independent of the objective key', function () {
    $normalise = function (array $props, string $key): string {
        $json = json_encode([$props['layout'], $props['routes'], $props['hidden'], $props['goals']]);

        return str_replace($key, 'K', $json);
    };
    [$a, $keyA] = p5pHub(3001, 'DA');
    [$b, $keyB] = p5pHub(3001, 'DB');

    $first = $this->actingAs($a)->get("/map/{$keyA}")->viewData('page')['props'];
    $again = $this->actingAs($a)->get("/map/{$keyA}")->viewData('page')['props'];
    $twin = $this->actingAs($b)->get("/map/{$keyB}")->viewData('page')['props'];

    expect($normalise($again, $keyA))->toBe($normalise($first, $keyA))
        ->and($normalise($twin, $keyB))->toBe($normalise($first, $keyA));
});
