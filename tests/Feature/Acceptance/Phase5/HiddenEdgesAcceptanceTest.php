<?php

/*
| Phase 5 acceptance round 3 (independent tester) — the lane cap hides some
| long edges ("+N"). Hidden edges must stay in the data (the side panel lists
| them), be a subset of the real edges, and never hide an edge touching the
| Now task (its direct prerequisites or what it unlocks). New seeds only.
*/

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/**
 * One objective, $plans plans, $count items, ~2 edges per item in a random
 * topological order (many point to earlier plans). The Now task is a random
 * item whose ancestors are all done.
 *
 * @return array{0: User, 1: string, 2: string}
 */
function p5hSeed(int $seed, int $count, int $plans): array
{
    mt_srand($seed);
    $owner = User::factory()->create();
    $key = 'H'.$seed;
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => $key]);
    $planModels = [];
    foreach (range(1, $plans) as $p) {
        $planModels[] = Plan::factory()->for($objective)->create(['position' => $p]);
    }
    $items = [];
    foreach (range(1, $count) as $i) {
        $items[] = Item::factory()->for($planModels[mt_rand(0, $plans - 1)])->create(['title' => "t{$i}"]);
    }
    $rank = range(0, $count - 1);
    shuffle($rank);
    $parents = [];
    for ($n = 0; $n < $count * 2; $n++) {
        $a = mt_rand(0, $count - 1);
        $b = mt_rand(0, $count - 1);
        if ($a === $b) {
            continue;
        }
        [$from, $to] = $rank[$a] < $rank[$b] ? [$a, $b] : [$b, $a];
        if (isset($parents[$to][$from])) {
            continue;
        }
        $parents[$to][$from] = true;
        ItemDependency::query()->create(['prerequisite_id' => $items[$from]->id, 'dependent_id' => $items[$to]->id]);
    }

    // Now = the item with the most ancestors; every ancestor is done.
    $ancestors = function (int $i) use (&$ancestors, $parents): array {
        $all = [];
        foreach (array_keys($parents[$i] ?? []) as $p) {
            $all[$p] = true;
            $all += $ancestors($p);
        }

        return $all;
    };
    $best = 0;
    $bestCount = -1;
    foreach (range(0, $count - 1) as $i) {
        $c = count($ancestors($i));
        if ($c > $bestCount) {
            [$best, $bestCount] = [$i, $c];
        }
    }
    foreach (array_keys($ancestors($best)) as $a) {
        $items[$a]->update(['completed_at' => now()]);
    }
    $items[$best]->update(['is_active' => true]);

    return [$owner, $key, $key.'-'.$items[$best]->fresh()->number];
}

test('hidden edges are real edges, stay listed in the data, and never touch the Now task', function (int $seed, int $count, int $plans) {
    [$owner, $key, $now] = p5hSeed($seed, $count, $plans);

    $props = $this->actingAs($owner)->get("/map/{$key}")->assertOk()->viewData('page')['props'];
    $edges = array_map(fn (array $e) => $e['from'].'>'.$e['to'], $props['graph']['edges']);
    expect($props['graph']['nodes'])->toHaveCount($count);

    $touchingNow = [];
    foreach ($props['hidden'] as $edge) {
        expect($edges)->toContain($edge['from'].'>'.$edge['to']);
        if ($edge['to'] === $now) {
            $touchingNow[] = "prerequisite of Now hidden: {$edge['from']}→{$now}";
        }
        if ($edge['from'] === $now) {
            $touchingNow[] = "unlock of Now hidden: {$now}→{$edge['to']}";
        }
    }
    expect($touchingNow)->toBe([]);

    // Every real edge is either drawn (a route between its ends, possibly
    // via the reduction) or listed as hidden, or implied by drawn ones.
    $drawn = [];
    foreach ($props['routes'] as $route) {
        $drawn[$route['from']][] = $route['to'];
    }
    $reach = function (string $a, string $b) use (&$reach, $drawn, $props): bool {
        foreach ($drawn[$a] ?? [] as $n) {
            if ($n === $b || $reach($n, $b)) {
                return true;
            }
        }
        foreach ($props['hidden'] as $h) {
            if ($h['from'] === $a && ($h['to'] === $b || $reach($h['to'], $b))) {
                return true;
            }
        }

        return false;
    };
    foreach ($props['graph']['edges'] as $edge) {
        expect($reach($edge['from'], $edge['to']))->toBeTrue("edge {$edge['from']}→{$edge['to']} is neither drawn, hidden nor implied");
    }
})->with([
    [301, 60, 5], [412, 75, 6], [523, 80, 4], [634, 50, 3], [745, 70, 6], [856, 40, 3],
]);

test('the global map hides only real edges, reachable in the data', function () {
    [$owner, $key] = p5hSeed(967, 70, 5);
    $props = $this->actingAs($owner)->get('/map')->assertOk()->viewData('page')['props'];
    $edges = array_map(fn (array $e) => $e['from'].'>'.$e['to'], $props['graph']['edges']);

    foreach ($props['hidden'] as $edge) {
        expect($edges)->toContain($edge['from'].'>'.$edge['to']);
    }
});
