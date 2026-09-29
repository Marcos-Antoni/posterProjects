<?php

namespace App\Http\Resources;

/**
 * Deterministic layering of the minimal vertical unlock track (design D16:
 * a custom SVG layout instead of React Flow + ELK, dependencies are frozen).
 *
 * Every node gets a row (0 = top) and a lane (0 = the main track, 1, 2, …
 * the parallel branches to its right) inside its group (one group per
 * objective, one column per group in the global graph):
 *
 * - rows follow the dependencies: a dependent is always at least one row
 *   below each prerequisite, in any group, so every edge points down;
 * - bands keep plans together: a node never sits above the last placed row
 *   of an earlier band (plan) of its own group;
 * - only the transitive reduction is drawn: an edge implied by a longer
 *   path is not routed;
 * - an edge longer than one row gets a waypoint (a "dummy", Sugiyama style)
 *   on every row it crosses, in the dependent's group, so it owns a lane
 *   there and never runs through a station it does not connect; edges into
 *   the same dependent share those waypoints and merge into one trunk;
 * - lanes are ordered per row by the barycenter heuristic (down, up and
 *   down sweeps), so branches keep their lane, split and rejoin, with few
 *   crossings; ties keep the input order, stations before waypoints.
 *
 * Nodes are placed in topological order (Kahn), always taking the ready
 * node that came first in the input, so the result depends only on the
 * input order (edge order is irrelevant). {@see route()} also returns every
 * edge as its list of waypoints, one per row, which the page draws with 0°,
 * 45° and 90° segments only.
 */
final class UnlockTrackLayout
{
    /**
     * Most waypoint lanes (long edges and trunks) a row may carry.
     */
    public const MAX_WAYPOINT_LANES = 4;

    /**
     * Edge priority that the lane cap never hides.
     */
    public const PROTECTED = PHP_INT_MAX;

    /**
     * Rows and lanes of the stations.
     *
     * @param  list<array{key: string, group: string, band: int}>  $nodes  in tie-break order
     * @param  list<array{from: string, to: string}>  $edges  prerequisite → dependent
     * @return array<string, array{row: int, lane: int}>
     */
    public static function place(array $nodes, array $edges): array
    {
        return self::route($nodes, $edges)['spots'];
    }

    /**
     * Rows and lanes of the stations plus the route of every DRAWN edge:
     * the transitive reduction of the graph (an edge implied by a longer
     * path is left out of the drawing; it stays in the data). A drawn edge
     * is its waypoints from the prerequisite to the dependent, one per row.
     * Edges into the same dependent share their waypoints — they merge into
     * one trunk that rejoins at the dependent, like the reference track.
     *
     * @param  list<array{key: string, group: string, band: int}>  $nodes  in tie-break order
     * @param  list<array{from: string, to: string}>  $edges  prerequisite → dependent
     * @param  array<string, int>  $priority  "from>to" → how much an edge must stay drawn when the lane cap
     *                                        forces hiding (lowest hides first; {@see PROTECTED} never hides,
     *                                        even if that exceeds the cap); missing = 0
     * @return array{spots: array<string, array{row: int, lane: int}>, routes: list<array{from: string, to: string, points: list<array{row: int, lane: int, group: string}>}>, hidden: list<array{from: string, to: string}>}
     */
    public static function route(array $nodes, array $edges, array $priority = []): array
    {
        $index = [];

        foreach ($nodes as $position => $node) {
            $index[$node['key']] = $position;
        }

        /** @var array<string, array{0: int, 1: int, 2: string, 3: string}> $unique */
        $unique = [];

        foreach ($edges as $edge) {
            if (! isset($index[$edge['from']], $index[$edge['to']]) || $edge['from'] === $edge['to']) {
                continue;
            }

            $unique[$edge['from'].'>'.$edge['to']] = [$index[$edge['from']], $index[$edge['to']], $edge['from'], $edge['to']];
        }

        usort($unique, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        /** @var array<string, list<string>> $prerequisites */
        $prerequisites = [];
        /** @var array<string, list<string>> $dependents */
        $dependents = [];

        foreach ($unique as [, , $from, $to]) {
            $prerequisites[$to][] = $from;
            $dependents[$from][] = $to;
        }

        $rows = self::rows($nodes, $index, $prerequisites, $dependents);
        $groupOf = array_column($nodes, 'group', 'key');
        $drawn = self::reduce($nodes, $rows, $dependents);
        $hidden = [];

        // Hard cap on waypoint lanes: while a row would need more than
        // MAX_WAYPOINT_LANES of them, the least important drawn edge crossing
        // it (lowest priority, then the longest, then the last) leaves the
        // drawing — it stays in the data and the side panel, and the page
        // marks its prerequisite with a "+N". Protected edges never leave:
        // the cap is exceeded for them instead.
        while (true) {
            [$slots, $before, $after, $chains, $crowded] = self::thread($nodes, $index, $rows, $groupOf, $drawn);

            if ($crowded === []) {
                break;
            }

            $victim = null;

            foreach ($drawn as $at => [$from, $to]) {
                $weight = $priority[$from.'>'.$to] ?? 0;

                if (str_starts_with($to, '#goal:') || $weight >= self::PROTECTED) {
                    continue;
                }

                $span = $rows[$to] - $rows[$from];
                $crosses = false;

                for ($row = $rows[$from] + 1; $row < $rows[$to]; $row++) {
                    if (isset($crowded[$groupOf[$to].'|'.$row]) || isset($crowded[$groupOf[$from].'|'.$row])) {
                        $crosses = true;
                        break;
                    }
                }

                if ($crosses && ($victim === null || [-$weight, $span] >= [-$victim[2], $victim[1]])) {
                    $victim = [$at, $span, $weight];
                }
            }

            if ($victim === null) {
                break;
            }

            $hidden[] = ['from' => $drawn[$victim[0]][0], 'to' => $drawn[$victim[0]][1]];
            array_splice($drawn, $victim[0], 1);
        }

        $lanes = self::order($slots, $before, $after);

        $spots = [];

        foreach ($nodes as $node) {
            $spots[$node['key']] = ['row' => $rows[$node['key']], 'lane' => $lanes[$node['key']]];
        }

        $routes = [];

        foreach ($chains as [$from, $to, $chain]) {
            $routes[] = ['from' => $from, 'to' => $to, 'points' => array_map(fn (string $id): array => [
                'row' => $slots[$id]['row'],
                'lane' => $lanes[$id],
                'group' => $slots[$id]['group'],
            ], $chain)];
        }

        return ['spots' => $spots, 'routes' => $routes, 'hidden' => $hidden];
    }

    /**
     * Thread the drawn edges through their waypoints. On each crossed row an
     * edge rides the trunk of its dependent ("~T@row", shared by the edges
     * into T that cross that row) or, when only it goes to T there but
     * several edges leave its prerequisite S across that row, the bundle of
     * its prerequisite ("^S@row"): lines that share a waypoint always share
     * an end, so they may run together. A cross-group edge always takes its
     * dependent's trunk. Also reports the (group|row) rows with more than
     * {@see MAX_WAYPOINT_LANES} waypoints.
     *
     * @param  list<array{key: string, group: string, band: int}>  $nodes
     * @param  array<string, int>  $index
     * @param  array<string, int>  $rows
     * @param  array<string, string>  $groupOf
     * @param  list<array{0: string, 1: string}>  $drawn
     * @return array{0: array<string, array{group: string, row: int, rank: array{0: int, 1: int}}>, 1: array<string, list<string>>, 2: array<string, list<string>>, 3: list<array{0: string, 1: string, 2: list<string>}>, 4: array<string, true>}
     */
    private static function thread(array $nodes, array $index, array $rows, array $groupOf, array $drawn): array
    {
        $intoCount = [];
        $outOfCount = [];

        foreach ($drawn as [$from, $to]) {
            for ($row = $rows[$from] + 1; $row < $rows[$to]; $row++) {
                $intoCount[$to][$row] = ($intoCount[$to][$row] ?? 0) + 1;
                $outOfCount[$from][$row] = ($outOfCount[$from][$row] ?? 0) + 1;
            }
        }

        $slots = [];
        $before = [];
        $after = [];

        foreach ($nodes as $position => $node) {
            $slots[$node['key']] = ['group' => $node['group'], 'row' => $rows[$node['key']], 'rank' => [0, $position]];
        }

        $chains = [];
        $waypoints = [];

        foreach ($drawn as [$from, $to]) {
            $chain = [$from];
            $cross = $groupOf[$from] !== $groupOf[$to];

            for ($row = $rows[$from] + 1; $row < $rows[$to]; $row++) {
                $bySource = ! $cross && $intoCount[$to][$row] < 2 && $outOfCount[$from][$row] >= 2;
                $id = $bySource ? "^{$from}@{$row}" : "~{$to}@{$row}";

                if (! isset($slots[$id])) {
                    $slots[$id] = [
                        'group' => $groupOf[$to],
                        'row' => $row,
                        'rank' => $bySource ? [2, $index[$from]] : [1, $index[$to]],
                    ];
                    $waypoints[$groupOf[$to].'|'.$row] = ($waypoints[$groupOf[$to].'|'.$row] ?? 0) + 1;
                }

                $chain[] = $id;
            }

            $chain[] = $to;
            $chains[] = [$from, $to, $chain];

            for ($at = 1; $at < count($chain); $at++) {
                $before[$chain[$at]][] = $chain[$at - 1];
                $after[$chain[$at - 1]][] = $chain[$at];
            }
        }

        $crowded = [];

        foreach ($waypoints as $key => $count) {
            if ($count > self::MAX_WAYPOINT_LANES) {
                $crowded[$key] = true;
            }
        }

        return [$slots, $before, $after, $chains, $crowded];
    }

    /**
     * The transitive reduction: an edge u → v is drawn only when v cannot be
     * reached from u through another dependent. Returned in input order.
     *
     * @param  list<array{key: string, group: string, band: int}>  $nodes
     * @param  array<string, int>  $rows
     * @param  array<string, list<string>>  $dependents
     * @return list<array{0: string, 1: string}>
     */
    private static function reduce(array $nodes, array $rows, array $dependents): array
    {
        /** @var array<string, array<string, true>> $reach nodes reachable in one or more steps */
        $reach = [];
        $byRowDesc = array_column($nodes, 'key');
        usort($byRowDesc, fn (string $a, string $b): int => $rows[$b] <=> $rows[$a]);

        foreach ($byRowDesc as $key) {
            $set = [];

            foreach ($dependents[$key] ?? [] as $next) {
                $set[$next] = true;

                if (($rows[$next] ?? 0) > ($rows[$key] ?? 0)) {
                    $set += $reach[$next] ?? [];
                }
            }

            $reach[$key] = $set;
        }

        $drawn = [];

        foreach ($nodes as $node) {
            $key = $node['key'];
            $next = array_values(array_unique($dependents[$key] ?? []));

            foreach ($next as $target) {
                $implied = false;

                foreach ($next as $other) {
                    if ($other !== $target && isset($reach[$other][$target]) && $rows[$other] > $rows[$key]) {
                        $implied = true;
                        break;
                    }
                }

                if (! $implied) {
                    $drawn[] = [$key, $target];
                }
            }
        }

        return $drawn;
    }

    /**
     * Lanes per (group, row): start in input order (stations before
     * waypoints), then sweep down, up and down again sorting each row by
     * the mean position of its neighbours in the previous row (barycenter
     * heuristic), which keeps a branch on its lane and cuts crossings.
     *
     * @param  array<string, array{group: string, row: int, rank: array{0: int, 1: int}}>  $slots
     * @param  array<string, list<string>>  $before
     * @param  array<string, list<string>>  $after
     * @return array<string, int>
     */
    private static function order(array $slots, array $before, array $after): array
    {
        /** @var array<string, array<int, list<string>>> $layers */
        $layers = [];

        foreach ($slots as $id => $slot) {
            $layers[$slot['group']][$slot['row']][] = $id;
        }

        $position = [];

        foreach ($layers as $group => $groupRows) {
            ksort($groupRows);

            foreach ($groupRows as $row => $ids) {
                usort($ids, fn (string $a, string $b): int => $slots[$a]['rank'] <=> $slots[$b]['rank']);
                $groupRows[$row] = $ids;

                foreach ($ids as $at => $id) {
                    $position[$id] = $at;
                }
            }

            $layers[$group] = $groupRows;
        }

        $sweep = function (string $rowOrder, array $neighbours) use (&$layers, &$position, $slots): void {
            foreach ($layers as $group => $groupRows) {
                foreach ($rowOrder === 'down' ? array_keys($groupRows) : array_reverse(array_keys($groupRows)) as $row) {
                    $ids = $groupRows[$row];
                    $key = [];

                    foreach ($ids as $id) {
                        $near = array_filter(
                            $neighbours[$id] ?? [],
                            fn (string $other): bool => $slots[$other]['group'] === $group && abs($slots[$other]['row'] - $row) === 1,
                        );
                        $key[$id] = $near === []
                            ? (float) $position[$id]
                            : array_sum(array_map(fn (string $other): int => $position[$other], $near)) / count($near);
                    }

                    usort($ids, fn (string $a, string $b): int => [$key[$a], $slots[$a]['rank']] <=> [$key[$b], $slots[$b]['rank']]);
                    $layers[$group][$row] = $ids;
                    $groupRows[$row] = $ids;

                    foreach ($ids as $at => $id) {
                        $position[$id] = $at;
                    }
                }
            }
        };

        $sweep('down', $before);
        $sweep('up', $after);
        $sweep('down', $before);

        return $position;
    }

    /**
     * @param  list<array{key: string, group: string, band: int}>  $nodes
     * @param  array<string, int>  $index
     * @param  array<string, list<string>>  $prerequisites
     * @param  array<string, list<string>>  $dependents
     * @return array<string, int>
     */
    private static function rows(array $nodes, array $index, array $prerequisites, array $dependents): array
    {
        $waiting = [];
        $ready = [];

        foreach ($nodes as $position => $node) {
            $waiting[$node['key']] = count(array_unique($prerequisites[$node['key']] ?? []));

            if ($waiting[$node['key']] === 0) {
                $ready[$position] = true;
            }
        }

        $rows = [];
        /** @var array<string, array<int, int>> $deepest group => band => deepest placed row */
        $deepest = [];

        while (count($rows) < count($nodes)) {
            if ($ready === []) {
                // Defensive: a cycle cannot be stored (AddDependency), but
                // never loop forever — release the first unplaced node.
                foreach ($nodes as $position => $node) {
                    if (! isset($rows[$node['key']])) {
                        $ready[$position] = true;
                        break;
                    }
                }
            }

            ksort($ready);
            $position = array_key_first($ready);

            if ($position === null) {
                break;
            }

            unset($ready[$position]);
            $node = $nodes[$position];
            $key = $node['key'];

            $row = 0;

            foreach ($deepest[$node['group']] ?? [] as $band => $deepestRow) {
                if ($band < $node['band']) {
                    $row = max($row, $deepestRow + 1);
                }
            }

            foreach ($prerequisites[$key] ?? [] as $prerequisite) {
                if (isset($rows[$prerequisite])) {
                    $row = max($row, $rows[$prerequisite] + 1);
                }
            }

            $rows[$key] = $row;
            $deepest[$node['group']][$node['band']] = max($deepest[$node['group']][$node['band']] ?? 0, $row);

            foreach (array_unique($dependents[$key] ?? []) as $dependent) {
                if (isset($rows[$dependent])) {
                    continue;
                }

                $waiting[$dependent]--;

                if ($waiting[$dependent] === 0) {
                    $ready[$index[$dependent]] = true;
                }
            }
        }

        return $rows;
    }
}
