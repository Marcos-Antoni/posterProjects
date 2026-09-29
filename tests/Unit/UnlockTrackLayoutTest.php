<?php

use App\Http\Resources\UnlockTrackLayout;

/*
| Task 5.1 — the deterministic layering of the minimal vertical track
| (design D16, custom SVG instead of React Flow + ELK): rows grow downward
| along every dependency, independent items open parallel lanes that rejoin,
| plans stay grouped in bands, and each objective is its own column.
*/

/**
 * @param  list<array{0: string, 1?: int, 2?: string}>  $nodes  [key, band, group]
 * @return list<array{key: string, group: string, band: int}>
 */
function trackNodes(array $nodes): array
{
    return array_map(fn (array $node): array => [
        'key' => $node[0],
        'band' => $node[1] ?? 0,
        'group' => $node[2] ?? 'G',
    ], $nodes);
}

/**
 * @param  list<array{0: string, 1: string}>  $edges
 * @return list<array{from: string, to: string}>
 */
function trackEdges(array $edges): array
{
    return array_map(fn (array $edge): array => ['from' => $edge[0], 'to' => $edge[1]], $edges);
}

test('a chain is one straight track, one row per item', function () {
    $layout = UnlockTrackLayout::place(trackNodes([['A'], ['B'], ['C']]), trackEdges([['A', 'B'], ['B', 'C']]));

    expect($layout)->toBe([
        'A' => ['row' => 0, 'lane' => 0],
        'B' => ['row' => 1, 'lane' => 0],
        'C' => ['row' => 2, 'lane' => 0],
    ]);
});

test('independent items split into parallel lanes and rejoin on the main lane', function () {
    $layout = UnlockTrackLayout::place(
        trackNodes([['D3'], ['D4'], ['D5'], ['D6'], ['D7']]),
        trackEdges([['D3', 'D4'], ['D3', 'D5'], ['D3', 'D6'], ['D4', 'D7'], ['D5', 'D7'], ['D6', 'D7']]),
    );

    expect($layout)->toBe([
        'D3' => ['row' => 0, 'lane' => 0],
        'D4' => ['row' => 1, 'lane' => 0],
        'D5' => ['row' => 1, 'lane' => 1],
        'D6' => ['row' => 1, 'lane' => 2],
        'D7' => ['row' => 2, 'lane' => 0],
    ]);
});

test('an edge that skips rows still points down', function () {
    $layout = UnlockTrackLayout::place(trackNodes([['A'], ['B'], ['C']]), trackEdges([['A', 'B'], ['B', 'C'], ['A', 'C']]));

    expect($layout['C']['row'])->toBe(2);
});

test('plans stay grouped: a later plan starts below the earlier plan even without edges', function () {
    $layout = UnlockTrackLayout::place(
        trackNodes([['A', 0], ['B', 0], ['X', 1], ['Y', 2]]),
        trackEdges([['A', 'B']]),
    );

    expect($layout['A']['row'])->toBe(0)
        ->and($layout['B']['row'])->toBe(1)
        ->and($layout['X']['row'])->toBe(2)
        ->and($layout['Y']['row'])->toBe(3);
});

test('items of the same plan without edges between them sit side by side', function () {
    $layout = UnlockTrackLayout::place(trackNodes([['A'], ['B'], ['C']]), []);

    expect($layout)->toBe([
        'A' => ['row' => 0, 'lane' => 0],
        'B' => ['row' => 0, 'lane' => 1],
        'C' => ['row' => 0, 'lane' => 2],
    ]);
});

test('an item keeps following its branch when it is alone in the branch', function () {
    // A → B, A → C, C → E, B → D, D, E → F: two branches of two stations.
    $layout = UnlockTrackLayout::place(
        trackNodes([['A'], ['B'], ['C'], ['D'], ['E'], ['F']]),
        trackEdges([['A', 'B'], ['A', 'C'], ['B', 'D'], ['C', 'E'], ['D', 'F'], ['E', 'F']]),
    );

    expect($layout['D'])->toBe(['row' => 2, 'lane' => 0])
        ->and($layout['E'])->toBe(['row' => 2, 'lane' => 1])
        ->and($layout['F'])->toBe(['row' => 3, 'lane' => 0]);
});

test('each group is its own column, and a cross-group edge pushes the dependent below its prerequisite', function () {
    $layout = UnlockTrackLayout::place(
        trackNodes([['D1', 0, 'DIARIO'], ['D2', 0, 'DIARIO'], ['D3', 1, 'DIARIO'], ['F1', 0, 'FINALES'], ['W1', 0, 'WEB'], ['W2', 0, 'WEB']]),
        trackEdges([['D1', 'D2'], ['D2', 'D3'], ['D3', 'W1'], ['W1', 'W2']]),
    );

    expect($layout['F1'])->toBe(['row' => 0, 'lane' => 0])
        ->and($layout['D3'])->toBe(['row' => 2, 'lane' => 0])
        ->and($layout['W1'])->toBe(['row' => 3, 'lane' => 0])
        ->and($layout['W2'])->toBe(['row' => 4, 'lane' => 0]);
});

test('edges to unknown keys are ignored', function () {
    $layout = UnlockTrackLayout::place(trackNodes([['A'], ['B']]), trackEdges([['A', 'B'], ['ZZ', 'A'], ['B', 'QQ']]));

    expect($layout)->toBe([
        'A' => ['row' => 0, 'lane' => 0],
        'B' => ['row' => 1, 'lane' => 0],
    ]);
});

test('on any random DAG every edge points down, no two nodes share a spot and the result is deterministic', function (int $seed) {
    mt_srand($seed);
    $count = mt_rand(5, 40);
    $groups = ['A', 'B', 'C'];
    $nodes = [];

    for ($index = 0; $index < $count; $index++) {
        $nodes[] = ['N'.$index, mt_rand(0, 3), $groups[mt_rand(0, 2)]];
    }

    // Edges only from a lower index to a higher one: acyclic by construction.
    $edges = [];

    for ($index = 0; $index < $count * 2; $index++) {
        $from = mt_rand(0, $count - 2);
        $to = mt_rand($from + 1, $count - 1);
        $edges[] = ['N'.$from, 'N'.$to];
    }

    $layout = UnlockTrackLayout::place(trackNodes($nodes), trackEdges($edges));

    foreach ($edges as [$from, $to]) {
        expect($layout[$to]['row'])->toBeGreaterThan($layout[$from]['row']);
    }

    $spots = [];

    foreach ($nodes as [$key, , $group]) {
        $spots[] = $group.':'.$layout[$key]['row'].':'.$layout[$key]['lane'];
        expect($layout[$key]['lane'])->toBeGreaterThanOrEqual(0);
    }

    expect(array_unique($spots))->toHaveCount($count)
        ->and(UnlockTrackLayout::place(trackNodes($nodes), trackEdges($edges)))->toBe($layout);
})->with(range(1, 25));

test('an edge longer than one row gets its own waypoint lane and never runs through a station of the row it crosses', function () {
    $track = UnlockTrackLayout::route(
        trackNodes([['A', 0], ['X', 1], ['Y', 1], ['D', 2]]),
        trackEdges([['A', 'D']]),
    );

    $route = $track['routes'][0];
    $crossing = $route['points'][1];

    expect(array_column($route['points'], 'row'))->toBe([0, 1, 2])
        ->and($crossing['lane'])->not->toBe($track['spots']['X']['lane'])
        ->and($crossing['lane'])->not->toBe($track['spots']['Y']['lane'])
        ->and($route['points'][0])->toBe(['row' => 0, 'lane' => $track['spots']['A']['lane'], 'group' => 'G'])
        ->and($route['points'][2])->toBe(['row' => 2, 'lane' => $track['spots']['D']['lane'], 'group' => 'G']);
});

test('a cross-group edge takes its waypoints in the dependent group', function () {
    $track = UnlockTrackLayout::route(
        trackNodes([['D1', 0, 'DIARIO'], ['D2', 0, 'DIARIO'], ['D3', 0, 'DIARIO'], ['W1', 0, 'WEB'], ['W2', 0, 'WEB']]),
        trackEdges([['D1', 'D2'], ['D2', 'D3'], ['D1', 'W2'], ['W1', 'W2']]),
    );

    $cross = collect($track['routes'])->firstWhere('to', 'W2');

    expect(array_column($cross['points'], 'group'))->toBe(['DIARIO', 'WEB'])
        ->and($track['spots']['W2']['row'])->toBe(1);
});

test('on any random DAG every route moves one row per hop, and a waypoint is never a station and is only shared by edges with a common end', function (int $seed) {
    mt_srand($seed);
    $count = mt_rand(5, 40);
    $groups = ['A', 'B', 'C'];
    $nodes = [];

    for ($index = 0; $index < $count; $index++) {
        $nodes[] = ['N'.$index, mt_rand(0, 3), $groups[mt_rand(0, 2)]];
    }

    $edges = [];

    for ($index = 0; $index < $count * 2; $index++) {
        $from = mt_rand(0, $count - 2);
        $edges[] = ['N'.$from, 'N'.mt_rand($from + 1, $count - 1)];
    }

    $track = UnlockTrackLayout::route(trackNodes($nodes), trackEdges($edges));
    $groupOf = array_column(trackNodes($nodes), 'group', 'key');
    $owners = [];

    foreach ($track['spots'] as $key => $spot) {
        $owners[$groupOf[$key].':'.$spot['row'].':'.$spot['lane']] = $key;
    }

    foreach ($track['routes'] as $route) {
        $points = $route['points'];

        foreach ($points as $at => $point) {
            if ($at > 0) {
                expect($point['row'] - $points[$at - 1]['row'])->toBe(1);
            }

            $spot = $point['group'].':'.$point['row'].':'.$point['lane'];

            if ($at === 0 || $at === count($points) - 1) {
                expect($owners[$spot])->toBe($at === 0 ? $route['from'] : $route['to']);
            } else {
                expect($owners[$spot] ?? [])->toBeArray();

                foreach ($owners[$spot] ?? [] as [$from, $to]) {
                    expect($from === $route['from'] || $to === $route['to'])->toBeTrue();
                }

                $owners[$spot][] = [$route['from'], $route['to']];
            }
        }
    }
})->with(range(1, 15));

test('only the transitive reduction is routed: an edge implied by a longer path is not drawn', function () {
    $track = UnlockTrackLayout::route(trackNodes([['A'], ['B'], ['C']]), trackEdges([['A', 'B'], ['B', 'C'], ['A', 'C']]));

    expect(array_map(fn (array $route): string => $route['from'].'>'.$route['to'], $track['routes']))->toBe(['A>B', 'B>C'])
        ->and($track['spots']['C'])->toBe(['row' => 2, 'lane' => 0]);
});

test('edges into the same dependent share their waypoints: they merge into one trunk', function () {
    // X (row 0) and Y (row 1) both unlock T (row 3), past the chain A → B → C → T.
    $track = UnlockTrackLayout::route(
        trackNodes([['A'], ['X'], ['B'], ['Y'], ['C'], ['T']]),
        trackEdges([['A', 'B'], ['B', 'C'], ['C', 'T'], ['X', 'T'], ['A', 'Y'], ['Y', 'T']]),
    );
    $routes = collect($track['routes'])->keyBy('from');

    expect($routes['X']['points'][2])->toBe($routes['Y']['points'][1])
        ->and($routes['X']['points'][2]['row'])->toBe(2)
        ->and(array_column($routes['X']['points'], 'row'))->toBe([0, 1, 2, 3]);
});

test('no row carries more than the cap of waypoint lanes; the longest edges beyond it are reported hidden', function () {
    // Six long edges from row 0 to row 3 over a two-row chain.
    $nodes = [['A'], ['B'], ['C'], ['D']];
    $edges = [['A', 'B'], ['B', 'C'], ['C', 'D']];

    foreach (range(1, 6) as $i) {
        $nodes[] = ["S{$i}"];
        $nodes[] = ["T{$i}", 1];
        $edges[] = ["S{$i}", "T{$i}"];
        $edges[] = ['C', "T{$i}"];
    }

    $track = UnlockTrackLayout::route(trackNodes($nodes), trackEdges($edges));
    $perRow = [];

    foreach ($track['routes'] as $route) {
        foreach (array_slice($route['points'], 1, -1) as $point) {
            $perRow[$point['row']][$point['lane']] = true;
        }
    }

    expect(max(array_map('count', $perRow ?: [[]])))->toBeLessThanOrEqual(UnlockTrackLayout::MAX_WAYPOINT_LANES)
        ->and($track['hidden'])->not->toBeEmpty()
        ->and(count($track['routes']) + count($track['hidden']))->toBe(3 + 12);
});

test('the cap hides the lowest-priority edges first and never a protected one, even beyond the cap', function () {
    $nodes = [['A'], ['B'], ['C'], ['D']];
    $edges = [['A', 'B'], ['B', 'C'], ['C', 'D']];
    $priority = [];

    foreach (range(1, 6) as $i) {
        $nodes[] = ["S{$i}"];
        $nodes[] = ["T{$i}", 1];
        $edges[] = ["S{$i}", "T{$i}"];
        $edges[] = ['C', "T{$i}"];
        $priority["S{$i}>T{$i}"] = $i <= 5 ? UnlockTrackLayout::PROTECTED : 0;
        $priority["C>T{$i}"] = UnlockTrackLayout::PROTECTED;
    }

    $hidden = array_map(
        fn (array $edge): string => $edge['from'].'>'.$edge['to'],
        UnlockTrackLayout::route(trackNodes($nodes), trackEdges($edges), $priority)['hidden'],
    );

    expect($hidden)->toBe(['S6>T6']);
});
