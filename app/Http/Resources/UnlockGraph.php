<?php

namespace App\Http\Resources;

use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Read models of the unlock graphs (unlock-graph spec), shared by the web
 * screens 8 and 9 and the MCP `objective-graph` / `global-graph` tools so
 * both return the same nodes and edges:
 *
 * - {@see forObjective()}: every non-retired item of one objective as a node
 *   grouped by plan, every dependency touching them as an edge, and the
 *   cross-objective prerequisites/dependents as external stubs;
 * - {@see global()}: every active objective of the owner as a cluster with
 *   its non-retired items, every edge between them (cross-objective ones
 *   flagged), the Now task and what it unlocks.
 *
 * Retired items are never nodes, stubs or edge ends; they are listed apart
 * ("retired", beside the track, with their reason). A fixed number of
 * queries whatever the graph size. The web adds the track layout on top
 * ({@see objectiveTrack()}, {@see globalTrack()}).
 *
 * @phpstan-type GraphNode array{key: string, number: int, kind: string, title: string, state: string, plan_id: int, two_minute_version: string, completed_at: string|null}
 * @phpstan-type GraphRetired array{key: string, title: string, plan_id: int, reason: string|null}
 * @phpstan-type GraphStub array{key: string, kind: string, title: string, state: string, objective_key: string, objective_title: string}
 * @phpstan-type GraphNow array{key: string, title: string, objective_key: string, objective_title: string}
 * @phpstan-type GraphPlan array{id: int, title: string}
 * @phpstan-type ObjectiveGraph array{
 *     objective: array{key: string, title: string, state: string, is_writable: bool},
 *     goal: array{title: string, deadline: string|null, metric: array{name: string|null, current: float|null, target: float|null}|null},
 *     plans: list<GraphPlan>,
 *     nodes: list<GraphNode>,
 *     edges: list<array{from: string, to: string, external: bool}>,
 *     stubs: list<GraphStub>,
 *     retired: list<GraphRetired>,
 *     now: GraphNow|null,
 *     next_milestone: array{key: string, title: string, remaining: int}|null
 * }
 * @phpstan-type TrackPlan array{layout: array<string, array{row: int, lane: int}>, goals: array<string, array{row: int, lane: int}>, routes: list<array{from: string, to: string, points: list<array{row: int, lane: int, group: string}>}>, hidden: list<array{from: string, to: string}>}
 * @phpstan-type GlobalCluster array{key: string, title: string, line: int, progress: array{done: int, total: int, available: int}, plans: list<GraphPlan>, nodes: list<GraphNode>, retired: list<GraphRetired>}
 * @phpstan-type GlobalGraph array{
 *     objectives: list<GlobalCluster>,
 *     edges: list<array{from: string, to: string, cross: bool}>,
 *     now: GraphNow|null,
 *     now_unlocks: list<string>
 * }
 */
final class UnlockGraph
{
    /**
     * Objective states whose items can be drawn (as nodes or stubs).
     */
    private const VISIBLE_OBJECTIVE_STATES = [ObjectiveState::Active->value, ObjectiveState::Closed->value];

    /**
     * @return ObjectiveGraph
     */
    public static function forObjective(Objective $objective): array
    {
        $objective->loadMissing('controlPlan');

        $plans = Plan::query()
            ->where('objective_id', $objective->id)
            ->where('state', '!=', PlanState::Retired->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'title']);

        $keys = [$objective->id => $objective->key];
        $nodes = self::nodes(self::items([$objective->id]), $plans, $keys);
        $nodeKeys = array_flip(array_column($nodes, 'key'));

        $edges = [];
        $externalIds = [];

        foreach (self::edgeRows([$objective->id], touching: true) as $row) {
            $from = $row->prerequisite_key.'-'.$row->prerequisite_number;
            $to = $row->dependent_key.'-'.$row->dependent_number;
            $external = (int) $row->prerequisite_objective_id !== $objective->id || (int) $row->dependent_objective_id !== $objective->id;

            if ((int) $row->prerequisite_objective_id !== $objective->id) {
                $externalIds[] = (int) $row->prerequisite_id;
            }

            if ((int) $row->dependent_objective_id !== $objective->id) {
                $externalIds[] = (int) $row->dependent_id;
            }

            $edges[] = ['from' => $from, 'to' => $to, 'external' => $external];
        }

        $controlPlan = $objective->controlPlan;

        return [
            'objective' => [
                'key' => $objective->key,
                'title' => $objective->title,
                'state' => $objective->state->value,
                'is_writable' => $objective->state === ObjectiveState::Active,
            ],
            'goal' => [
                'title' => $objective->title,
                'deadline' => $controlPlan?->deadline?->toDateString(),
                'metric' => $controlPlan === null ? null : [
                    'name' => $controlPlan->metric_name,
                    'current' => $controlPlan->metric_current === null ? null : (float) $controlPlan->metric_current,
                    'target' => $controlPlan->metric_target === null ? null : (float) $controlPlan->metric_target,
                ],
            ],
            'plans' => self::presentPlans($plans),
            'nodes' => $nodes,
            'edges' => $edges,
            'stubs' => self::stubs(array_values(array_unique($externalIds))),
            'retired' => self::retired([$objective->id], $keys)[$objective->id] ?? [],
            'now' => self::now($objective->user_id),
            'next_milestone' => self::nextMilestone($nodes, array_values(array_filter(
                $edges,
                fn (array $edge): bool => isset($nodeKeys[$edge['from']], $nodeKeys[$edge['to']]),
            ))),
        ];
    }

    /**
     * @return GlobalGraph
     */
    public static function global(User $owner): array
    {
        $objectives = Objective::query()->where('user_id', $owner->id)->active()->get(['id', 'key', 'title']);
        $now = self::now($owner->id);

        if ($objectives->isEmpty()) {
            return ['objectives' => [], 'edges' => [], 'now' => $now, 'now_unlocks' => []];
        }

        /** @var list<int> $ids */
        $ids = array_values($objectives->map(fn (Objective $objective): int => $objective->id)->all());
        $keys = $objectives->mapWithKeys(fn (Objective $objective): array => [$objective->id => $objective->key])->all();

        $plans = Plan::query()
            ->whereIn('objective_id', $ids)
            ->where('state', '!=', PlanState::Retired->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'objective_id', 'title'])
            ->groupBy('objective_id');
        $items = self::items($ids)->groupBy('objective_id');
        $retired = self::retired($ids, $keys);

        $edges = [];

        foreach (self::edgeRows($ids, touching: false) as $row) {
            $edges[] = [
                'from' => $row->prerequisite_key.'-'.$row->prerequisite_number,
                'to' => $row->dependent_key.'-'.$row->dependent_number,
                'cross' => (int) $row->prerequisite_objective_id !== (int) $row->dependent_objective_id,
            ];
        }

        $clusters = [];

        foreach ($objectives->values() as $index => $objective) {
            /** @var Collection<int, Plan> $objectivePlans */
            $objectivePlans = $plans->get($objective->id, new Collection);
            $nodes = self::nodes($items->get($objective->id, new Collection), $objectivePlans, $keys);
            $states = array_column($nodes, 'state');

            $clusters[] = [
                'key' => $objective->key,
                'title' => $objective->title,
                'line' => ($index % 3) + 1,
                'progress' => [
                    'done' => count(array_keys($states, ItemState::Done->value, true)),
                    'total' => count($nodes),
                    'available' => count(array_keys($states, ItemState::Available->value, true)),
                ],
                'plans' => self::presentPlans($objectivePlans),
                'nodes' => $nodes,
                'retired' => $retired[$objective->id] ?? [],
            ];
        }

        $nowUnlocks = $now === null ? [] : array_values(array_map(
            fn (array $edge): string => $edge['to'],
            array_filter($edges, fn (array $edge): bool => $edge['from'] === $now['key']),
        ));

        return ['objectives' => $clusters, 'edges' => $edges, 'now' => $now, 'now_unlocks' => $nowUnlocks];
    }

    /**
     * The track of a per-objective graph: rows and lanes of its nodes (plans
     * as bands), the goal one row below everything (every sink joins it on
     * its own lane), and the route of every internal edge and sink → goal
     * edge as one waypoint per row. External stubs are placed by the page,
     * left of the track.
     *
     * @param  ObjectiveGraph  $graph
     * @return TrackPlan
     */
    public static function objectiveTrack(array $graph): array
    {
        $group = $graph['objective']['key'];
        $bands = array_flip(array_column($graph['plans'], 'id'));
        $nodes = array_map(fn (array $node): array => ['key' => $node['key'], 'group' => $group, 'band' => $bands[$node['plan_id']] ?? 0], $graph['nodes']);
        $keys = array_flip(array_column($graph['nodes'], 'key'));
        $edges = array_values(array_map(
            fn (array $edge): array => ['from' => $edge['from'], 'to' => $edge['to']],
            array_filter($graph['edges'], fn (array $edge): bool => isset($keys[$edge['from']], $keys[$edge['to']])),
        ));

        return self::withGoals($nodes, $edges, [$group => count($graph['plans'])], self::priorities($graph['nodes'], $edges, $graph['now']['key'] ?? null));
    }

    /**
     * The track of the global graph: one column (group) per active objective,
     * rows shared across columns so a cross-objective edge always points
     * down; a cross edge takes its waypoints in the dependent's column. A
     * collapsed objective is a single node ("#goal:KEY") that takes over the
     * cross-objective edges of its items, routed like any other edge.
     *
     * @param  GlobalGraph  $graph
     * @param  list<string>  $collapsed  keys of collapsed objectives
     * @return TrackPlan
     */
    public static function globalTrack(array $graph, array $collapsed = []): array
    {
        $nodes = [];
        $goalBands = [];
        $folded = [];

        foreach ($graph['objectives'] as $cluster) {
            if (in_array($cluster['key'], $collapsed, true)) {
                $nodes[] = ['key' => '#goal:'.$cluster['key'], 'group' => $cluster['key'], 'band' => 0];

                foreach ($cluster['nodes'] as $node) {
                    $folded[$node['key']] = '#goal:'.$cluster['key'];
                }

                continue;
            }

            $bands = array_flip(array_column($cluster['plans'], 'id'));
            $goalBands[$cluster['key']] = count($cluster['plans']);

            foreach ($cluster['nodes'] as $node) {
                $nodes[] = ['key' => $node['key'], 'group' => $cluster['key'], 'band' => $bands[$node['plan_id']] ?? 0];
            }
        }

        $edges = [];

        foreach ($graph['edges'] as $edge) {
            $from = $folded[$edge['from']] ?? $edge['from'];
            $to = $folded[$edge['to']] ?? $edge['to'];

            if ($from !== $to) {
                $edges[$from.'>'.$to] = ['from' => $from, 'to' => $to];
            }
        }

        $allNodes = array_merge(...array_map(fn (array $cluster): array => $cluster['nodes'], $graph['objectives'] ?: [['nodes' => []]]));
        $track = self::withGoals($nodes, array_values($edges), $goalBands, self::priorities($allNodes, array_values($edges), $graph['now']['key'] ?? null));

        foreach ($collapsed as $key) {
            if (isset($track['layout']['#goal:'.$key])) {
                $track['goals'][$key] = $track['layout']['#goal:'.$key];
                unset($track['layout']['#goal:'.$key]);
            }
        }

        return $track;
    }

    /**
     * How much each edge must stay drawn when the lane cap forces hiding:
     * never an edge touching the Now task or on its way from the Now task to
     * the next milestone (protected); then edges touching an available or
     * active item; then edges between future items; last, edges between
     * done items.
     *
     * @param  list<GraphNode>  $nodes
     * @param  list<array{from: string, to: string}>  $edges
     * @return array<string, int>
     */
    private static function priorities(array $nodes, array $edges, ?string $now): array
    {
        $byKey = [];

        foreach ($nodes as $node) {
            $byKey[$node['key']] = $node;
        }

        $dependents = [];

        foreach ($edges as $edge) {
            $dependents[$edge['from']][] = $edge['to'];
        }

        // The Now task and everything on its way to the next milestone.
        $ahead = [];

        if ($now !== null && isset($byKey[$now])) {
            $ahead[$now] = true;
            $queue = [$now];

            while ($queue !== []) {
                $key = array_shift($queue);

                if ($key !== $now && ($byKey[$key]['kind'] ?? null) === ItemKind::Milestone->value) {
                    continue;
                }

                foreach ($dependents[$key] ?? [] as $next) {
                    if (! isset($ahead[$next]) && isset($byKey[$next])) {
                        $ahead[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
        }

        $state = fn (string $key): ?string => $byKey[$key]['state'] ?? null;
        $priority = [];

        foreach ($edges as $edge) {
            [$from, $to] = [$edge['from'], $edge['to']];

            $priority[$from.'>'.$to] = match (true) {
                $from === $now || $to === $now || (isset($ahead[$from]) && isset($ahead[$to])) => UnlockTrackLayout::PROTECTED,
                in_array($state($from), ['available', 'active'], true) || in_array($state($to), ['available', 'active'], true) => 3,
                $state($from) === 'done' && $state($to) === 'done' => 0,
                default => 1,
            };
        }

        return $priority;
    }

    /**
     * Add one goal per group ("#goal:GROUP", after every band) fed by the
     * group's sinks, lay everything out and split the result.
     *
     * @param  list<array{key: string, group: string, band: int}>  $nodes
     * @param  list<array{from: string, to: string}>  $edges
     * @param  array<string, int>  $goalBands
     * @param  array<string, int>  $priority  see {@see UnlockTrackLayout::route()}
     * @return TrackPlan
     */
    private static function withGoals(array $nodes, array $edges, array $goalBands, array $priority = []): array
    {
        $groupOf = array_column($nodes, 'group', 'key');
        $feeds = [];

        foreach ($edges as $edge) {
            if (isset($groupOf[$edge['from']], $groupOf[$edge['to']]) && $groupOf[$edge['from']] === $groupOf[$edge['to']]) {
                $feeds[$edge['from']] = true;
            }
        }

        $all = $nodes;
        $goalEdges = [];

        foreach ($goalBands as $group => $band) {
            $all[] = ['key' => '#goal:'.$group, 'group' => $group, 'band' => $band];
        }

        foreach ($nodes as $node) {
            if (! isset($feeds[$node['key']]) && isset($goalBands[$node['group']])) {
                $goalEdges[] = ['from' => $node['key'], 'to' => '#goal:'.$node['group']];
            }
        }

        $track = UnlockTrackLayout::route($all, [...$edges, ...$goalEdges], $priority);
        $layout = [];
        $goals = [];

        foreach ($track['spots'] as $key => $spot) {
            if (str_starts_with($key, '#goal:') && isset($goalBands[substr($key, 6)])) {
                $goals[substr($key, 6)] = $spot;
            } else {
                $layout[$key] = $spot;
            }
        }

        return ['layout' => $layout, 'goals' => $goals, 'routes' => $track['routes'], 'hidden' => $track['hidden']];
    }

    /**
     * The non-retired items of the objectives, with their derived state.
     *
     * @param  list<int>  $objectiveIds
     * @return Collection<int, Item>
     */
    private static function items(array $objectiveIds): Collection
    {
        return Item::query()
            ->withState()
            ->whereIn('objective_id', $objectiveIds)
            ->whereNull('retired_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Nodes in plan order, then item order; items of a retired plan are left
     * out with their plan.
     *
     * @param  Collection<int, Item>  $items
     * @param  Collection<int, Plan>  $plans
     * @param  array<int, string>  $objectiveKeys
     * @return list<GraphNode>
     */
    private static function nodes(Collection $items, Collection $plans, array $objectiveKeys): array
    {
        $byPlan = $items->groupBy('plan_id');
        $nodes = [];

        foreach ($plans as $plan) {
            foreach ($byPlan->get($plan->id, new Collection) as $item) {
                $nodes[] = [
                    'key' => $objectiveKeys[$item->objective_id].'-'.$item->number,
                    'number' => $item->number,
                    'kind' => $item->kind->value,
                    'title' => $item->title,
                    'state' => $item->state->value,
                    'plan_id' => $item->plan_id,
                    'two_minute_version' => $item->two_minute_version,
                    'completed_at' => $item->completed_at?->toIso8601String(),
                ];
            }
        }

        return $nodes;
    }

    /**
     * @param  Collection<int, Plan>  $plans
     * @return list<GraphPlan>
     */
    private static function presentPlans(Collection $plans): array
    {
        return array_values($plans->map(fn (Plan $plan): array => ['id' => $plan->id, 'title' => $plan->title])->all());
    }

    /**
     * Dependency rows whose two ends are visible — not retired, not in a
     * retired plan, and in an active or closed objective (a retired one is
     * hidden everywhere, a draft is still being negotiated and has no page;
     * the same objectives the `{objective}` binder opens) — touching the
     * objectives (either end inside) or among them (both ends inside), in
     * the order they were drawn.
     *
     * @param  list<int>  $objectiveIds
     * @return Collection<int, stdClass>
     */
    private static function edgeRows(array $objectiveIds, bool $touching): Collection
    {
        $query = DB::table('item_dependencies')
            ->join('items as prerequisites', 'prerequisites.id', '=', 'item_dependencies.prerequisite_id')
            ->join('items as dependents', 'dependents.id', '=', 'item_dependencies.dependent_id')
            ->join('objectives as prerequisite_objectives', 'prerequisite_objectives.id', '=', 'prerequisites.objective_id')
            ->join('objectives as dependent_objectives', 'dependent_objectives.id', '=', 'dependents.objective_id')
            ->join('plans as prerequisite_plans', 'prerequisite_plans.id', '=', 'prerequisites.plan_id')
            ->join('plans as dependent_plans', 'dependent_plans.id', '=', 'dependents.plan_id')
            ->whereNull('prerequisites.retired_at')
            ->whereNull('dependents.retired_at')
            ->where('prerequisite_plans.state', '!=', PlanState::Retired->value)
            ->where('dependent_plans.state', '!=', PlanState::Retired->value)
            ->whereIn('prerequisite_objectives.state', self::VISIBLE_OBJECTIVE_STATES)
            ->whereIn('dependent_objectives.state', self::VISIBLE_OBJECTIVE_STATES)
            ->orderBy('item_dependencies.id');

        if ($touching) {
            $query->where(fn ($where) => $where
                ->whereIn('prerequisites.objective_id', $objectiveIds)
                ->orWhereIn('dependents.objective_id', $objectiveIds));
        } else {
            $query->whereIn('prerequisites.objective_id', $objectiveIds)->whereIn('dependents.objective_id', $objectiveIds);
        }

        return $query->get([
            'item_dependencies.prerequisite_id',
            'item_dependencies.dependent_id',
            'prerequisites.number as prerequisite_number',
            'prerequisites.objective_id as prerequisite_objective_id',
            'prerequisite_objectives.key as prerequisite_key',
            'dependents.number as dependent_number',
            'dependents.objective_id as dependent_objective_id',
            'dependent_objectives.key as dependent_key',
        ]);
    }

    /**
     * External items drawn as stubs, ordered by objective key and number.
     *
     * @param  list<int>  $itemIds
     * @return list<GraphStub>
     */
    private static function stubs(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return array_values(Item::query()
            ->withState()
            ->join('objectives', 'objectives.id', '=', 'items.objective_id')
            ->addSelect(['objectives.key as objective_key', 'objectives.title as objective_title'])
            ->whereIn('items.id', $itemIds)
            ->orderBy('objectives.key')
            ->orderBy('items.number')
            ->get()
            ->map(fn (Item $item): array => [
                'key' => $item->getAttribute('objective_key').'-'.$item->number,
                'kind' => $item->kind->value,
                'title' => $item->title,
                'state' => $item->state->value,
                'objective_key' => (string) $item->getAttribute('objective_key'),
                'objective_title' => (string) $item->getAttribute('objective_title'),
            ])
            ->all());
    }

    /**
     * Retired items of the objectives with the reason of their current
     * retirement (the `retirements` row that was not restored), per
     * objective id.
     *
     * @param  list<int>  $objectiveIds
     * @param  array<int, string>  $objectiveKeys
     * @return array<int, list<GraphRetired>>
     */
    private static function retired(array $objectiveIds, array $objectiveKeys): array
    {
        $reason = DB::table('retirements')
            ->select('reason')
            ->whereColumn('retirements.retirable_id', 'items.id')
            ->whereIn('retirements.retirable_type', ['item', Item::class])
            ->whereNull('retirements.restored_at')
            ->orderByDesc('retirements.retired_at')
            ->orderByDesc('retirements.id')
            ->limit(1);

        $rows = DB::table('items')
            ->whereIn('objective_id', $objectiveIds)
            ->whereNotNull('retired_at')
            ->orderBy('number')
            ->select(['id', 'objective_id', 'number', 'plan_id', 'title'])
            ->selectSub($reason, 'reason')
            ->get();

        $retired = [];

        foreach ($rows as $row) {
            $retired[(int) $row->objective_id][] = [
                'key' => $objectiveKeys[(int) $row->objective_id].'-'.$row->number,
                'title' => (string) $row->title,
                'plan_id' => (int) $row->plan_id,
                'reason' => $row->reason === null ? null : (string) $row->reason,
            ];
        }

        return $retired;
    }

    /**
     * The owner's Now task: the item marked active (at most one, see
     * now-focus) in one of the owner's active objectives, not done nor
     * retired.
     *
     * @return GraphNow|null
     */
    private static function now(int $ownerId): ?array
    {
        $row = DB::table('items')
            ->join('objectives', 'objectives.id', '=', 'items.objective_id')
            ->where('objectives.user_id', $ownerId)
            ->where('objectives.state', ObjectiveState::Active->value)
            ->where('items.is_active', true)
            ->whereNull('items.retired_at')
            ->whereNull('items.completed_at')
            ->orderBy('items.id')
            ->first(['items.number', 'items.title', 'objectives.key', 'objectives.title as objective_title']);

        if ($row === null) {
            return null;
        }

        return [
            'key' => $row->key.'-'.$row->number,
            'title' => (string) $row->title,
            'objective_key' => (string) $row->key,
            'objective_title' => (string) $row->objective_title,
        ];
    }

    /**
     * "Faltan N estaciones para el mojón X": the open milestone with the
     * fewest open stations before it (its not-done prerequisites, transitively
     * inside the objective), ties by track order.
     *
     * @param  list<GraphNode>  $nodes
     * @param  list<array{from: string, to: string, external: bool}>  $edges  internal edges only
     * @return array{key: string, title: string, remaining: int}|null
     */
    private static function nextMilestone(array $nodes, array $edges): ?array
    {
        $byKey = [];

        foreach ($nodes as $node) {
            $byKey[$node['key']] = $node;
        }

        $prerequisites = [];

        foreach ($edges as $edge) {
            $prerequisites[$edge['to']][] = $edge['from'];
        }

        $best = null;

        foreach ($nodes as $node) {
            if ($node['kind'] !== ItemKind::Milestone->value || $node['state'] === ItemState::Done->value) {
                continue;
            }

            $seen = [];
            $stack = $prerequisites[$node['key']] ?? [];

            while ($stack !== []) {
                $key = array_pop($stack);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                array_push($stack, ...($prerequisites[$key] ?? []));
            }

            $remaining = count(array_filter(array_keys($seen), fn (string $key): bool => $byKey[$key]['state'] !== ItemState::Done->value));

            if ($best === null || $remaining < $best['remaining']) {
                $best = ['key' => $node['key'], 'title' => $node['title'], 'remaining' => $remaining];
            }
        }

        return $best;
    }
}
