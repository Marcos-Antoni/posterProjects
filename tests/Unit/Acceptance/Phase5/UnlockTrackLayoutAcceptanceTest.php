<?php

/*
| Phase 5 acceptance (independent tester) — UnlockTrackLayout on generated
| DAGs: deterministic, edges always point down, no two stations share a
| spot, bounded time for 200 items, and the input order of edges is
| irrelevant. Cycles cannot be stored; the layout still terminates on one.
*/

use App\Http\Resources\UnlockTrackLayout;

/**
 * @return array{0: list<array{key: string, group: string, band: int}>, 1: list<array{from: string, to: string}>}
 */
function p5uDag(string $shape, int $seed = 1): array
{
    mt_srand($seed);
    $nodes = [];
    $edges = [];
    $add = function (string $key, string $group = 'G', int $band = 0) use (&$nodes) {
        $nodes[] = ['key' => $key, 'group' => $group, 'band' => $band];
    };

    switch ($shape) {
        case 'fan-out-8':
            $add('R');
            foreach (range(1, 8) as $i) {
                $add("B{$i}", 'G', 1);
                $edges[] = ['from' => 'R', 'to' => "B{$i}"];
                $edges[] = ['from' => "B{$i}", 'to' => 'J'];
            }
            $add('J', 'G', 2);
            break;
        case 'chain-40':
            foreach (range(1, 40) as $i) {
                $add("C{$i}", 'G', intdiv($i, 14));
                if ($i > 1) {
                    $edges[] = ['from' => 'C'.($i - 1), 'to' => "C{$i}"];
                }
            }
            break;
        case 'back-edges':
            // Later plans feeding earlier plans, and cross-group edges both ways.
            foreach (range(0, 29) as $i) {
                $add("N{$i}", $i % 3 === 0 ? 'H' : 'G', intdiv($i, 10));
            }
            foreach (range(0, 60) as $_) {
                $a = mt_rand(0, 29);
                $b = mt_rand(0, 29);
                if ($a !== $b) {
                    [$a, $b] = [max($a, $b), min($a, $b)]; // from a later node to an earlier one: still a DAG
                    $edges[] = ['from' => "N{$a}", 'to' => "N{$b}"];
                }
            }
            break;
        case 'random-200':
            foreach (range(0, 199) as $i) {
                $add("N{$i}", 'G'.($i % 4), intdiv($i % 50, 10));
            }
            foreach (range(0, 399) as $_) {
                $a = mt_rand(0, 198);
                $b = mt_rand($a + 1, 199);
                $edges[] = ['from' => "N{$a}", 'to' => "N{$b}"];
            }
            break;
    }

    return [$nodes, $edges];
}

test('invariants on generated DAGs: every node placed, edges point down, no shared spot, deterministic, edge order irrelevant', function (string $shape, int $seed) {
    [$nodes, $edges] = p5uDag($shape, $seed);

    $layout = UnlockTrackLayout::place($nodes, $edges);
    $again = UnlockTrackLayout::place($nodes, $edges);
    $shuffled = $edges;
    mt_srand($seed + 99);
    shuffle($shuffled);

    expect($again)->toBe($layout)
        ->and(UnlockTrackLayout::place($nodes, $shuffled))->toBe($layout)
        ->and(array_keys($layout))->toEqualCanonicalizing(array_column($nodes, 'key'));

    foreach ($edges as $edge) {
        expect($layout[$edge['to']]['row'])->toBeGreaterThan($layout[$edge['from']]['row']);
    }

    $groups = array_column($nodes, 'group', 'key');
    $spots = [];
    foreach ($layout as $key => $spot) {
        $spots[] = $groups[$key].'|'.$spot['row'].'|'.$spot['lane'];
        expect($spot['lane'])->toBeGreaterThanOrEqual(0);
    }
    expect(count(array_unique($spots)))->toBe(count($spots));
})->with([['fan-out-8', 1], ['chain-40', 1], ['back-edges', 1], ['back-edges', 7], ['back-edges', 42], ['random-200', 1], ['random-200', 2]]);

test('a fan-out of 8 opens 8 lanes on one row and rejoins on the main lane', function () {
    [$nodes, $edges] = p5uDag('fan-out-8');
    $layout = UnlockTrackLayout::place($nodes, $edges);

    expect(array_unique(array_map(fn ($i) => $layout["B{$i}"]['row'], range(1, 8))))->toHaveCount(1)
        ->and(array_map(fn ($i) => $layout["B{$i}"]['lane'], range(1, 8)))->toEqualCanonicalizing(range(0, 7))
        ->and($layout['J']['lane'])->toBe(0)
        ->and($layout['R']['lane'])->toBe(0);
});

test('a chain of 40 is one straight track of 40 rows', function () {
    [$nodes, $edges] = p5uDag('chain-40');
    $layout = UnlockTrackLayout::place($nodes, $edges);

    expect(array_values(array_unique(array_column($layout, 'lane'))))->toBe([0])
        ->and(array_column($layout, 'row'))->toBe(range(0, 39));
});

test('200 items and 400 edges are laid out well under 250 ms', function () {
    [$nodes, $edges] = p5uDag('random-200', 3);
    $start = hrtime(true);
    UnlockTrackLayout::place($nodes, $edges);

    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(250);
});

test('a stored cycle (impossible through AddDependency) still terminates and places every node', function () {
    $nodes = [['key' => 'A', 'group' => 'G', 'band' => 0], ['key' => 'B', 'group' => 'G', 'band' => 0], ['key' => 'C', 'group' => 'G', 'band' => 0]];
    $edges = [['from' => 'A', 'to' => 'B'], ['from' => 'B', 'to' => 'C'], ['from' => 'C', 'to' => 'A']];

    expect(array_keys(UnlockTrackLayout::place($nodes, $edges)))->toEqualCanonicalizing(['A', 'B', 'C']);
});

test('duplicate and self edges do not break the layout', function () {
    $nodes = [['key' => 'A', 'group' => 'G', 'band' => 0], ['key' => 'B', 'group' => 'G', 'band' => 0]];
    $layout = UnlockTrackLayout::place($nodes, [['from' => 'A', 'to' => 'B'], ['from' => 'A', 'to' => 'B'], ['from' => 'A', 'to' => 'A']]);

    expect($layout)->toBe(['A' => ['row' => 0, 'lane' => 0], 'B' => ['row' => 1, 'lane' => 0]]);
});
