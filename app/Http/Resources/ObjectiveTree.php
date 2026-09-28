<?php

namespace App\Http\Resources;

use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Enums\PlanState;
use App\Models\ControlPlan;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Read model of an objective's tree (projects spec "The Objective Screen
 * Shows Its Whole Tree"): its non-retired plans in manual order, each with
 * its non-retired items in manual order, every item with its derived state
 * and prerequisites. A fixed number of queries whatever the tree size, so
 * the web screen, `GET /api/v1/objectives/{objective}` and the MCP
 * `show-objective` tool share one shape and never N+1.
 *
 * @phpstan-type WaitingOnRow array{key: string, kind: string, title: string, objective_key: string, objective_title: string, external: bool}
 * @phpstan-type TreeItemRow array{id: int, key: string, number: int, kind: string, title: string, two_minute_version: string, state: string, target_date: string|null, completed_at: string|null, prerequisite_keys: list<string>, waiting_on: list<WaitingOnRow>}
 * @phpstan-type TreePlanRow array{id: int, title: string, state: string, level: int|null, position: int, progress: array{done: int, total: int}, next_milestone: array{title: string, remaining: int}|null, retired_titles: list<string>, items: list<TreeItemRow>}
 */
final class ObjectiveTree
{
    /**
     * @return list<TreePlanRow>
     */
    public static function plans(Objective $objective): array
    {
        $plans = $objective->plans()->where('state', '!=', PlanState::Retired->value)->get();

        $items = Item::query()
            ->withState()
            ->where('objective_id', $objective->id)
            ->whereNull('retired_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->each(fn (Item $item) => $item->setRelation('objective', $objective));

        $retired = Item::query()
            ->where('objective_id', $objective->id)
            ->whereNotNull('retired_at')
            ->orderBy('number')
            ->get(['id', 'plan_id', 'title'])
            ->groupBy('plan_id');

        $prerequisites = self::prerequisitesOf(array_values($items->map(fn (Item $item): int => $item->id)->all()));

        $itemsByPlan = $items->groupBy('plan_id');

        return array_values($plans->map(function (Plan $plan) use ($itemsByPlan, $prerequisites, $retired): array {
            /** @var Collection<int, Item> $planItems */
            $planItems = $itemsByPlan->get($plan->id, new Collection);

            return [
                'id' => $plan->id,
                'title' => $plan->title,
                'state' => $plan->state->value,
                'level' => $plan->level,
                'position' => $plan->position,
                'progress' => [
                    'done' => $planItems->where('state', ItemState::Done)->count(),
                    'total' => $planItems->count(),
                ],
                'next_milestone' => self::nextMilestone($planItems),
                'retired_titles' => array_values($retired->get($plan->id, new Collection)->map(fn (Item $retiredItem): string => $retiredItem->title)->all()),
                'items' => array_values($planItems->map(
                    fn (Item $item): array => self::item($item, $prerequisites->get($item->id, new Collection)),
                )->all()),
            ];
        })->all());
    }

    /**
     * One row of the tree.
     *
     * @param  Collection<int, stdClass>  $prerequisites
     * @return TreeItemRow
     */
    public static function item(Item $item, Collection $prerequisites): array
    {
        $open = $prerequisites->filter(fn (stdClass $row): bool => $row->completed_at === null);

        return [
            'id' => $item->id,
            'key' => $item->key,
            'number' => $item->number,
            'kind' => $item->kind->value,
            'title' => $item->title,
            'two_minute_version' => $item->two_minute_version,
            'state' => $item->state->value,
            'target_date' => $item->target_date?->toDateString(),
            'completed_at' => $item->completed_at?->toIso8601String(),
            'prerequisite_keys' => array_values($prerequisites->map(fn (stdClass $row): string => $row->objective_key.'-'.$row->number)->all()),
            'waiting_on' => array_values($open->map(fn (stdClass $row): array => [
                'key' => $row->objective_key.'-'.$row->number,
                'kind' => $row->kind,
                'title' => $row->title,
                'objective_key' => $row->objective_key,
                'objective_title' => $row->objective_title,
                'external' => (int) $row->objective_id !== $item->objective_id,
            ])->all()),
        ];
    }

    /**
     * The non-retired prerequisites of the given items, grouped by dependent
     * id, in one query (with their objective key for the public key).
     *
     * @param  list<int>  $itemIds
     * @return Collection<array-key, Collection<int, stdClass>>
     */
    public static function prerequisitesOf(array $itemIds): Collection
    {
        if ($itemIds === []) {
            return new Collection;
        }

        return DB::table('item_dependencies')
            ->join('items as prerequisites', 'prerequisites.id', '=', 'item_dependencies.prerequisite_id')
            ->join('objectives as prerequisite_objectives', 'prerequisite_objectives.id', '=', 'prerequisites.objective_id')
            ->whereIn('item_dependencies.dependent_id', $itemIds)
            ->whereNull('prerequisites.retired_at')
            ->orderBy('prerequisites.id')
            ->get([
                'item_dependencies.dependent_id',
                'prerequisites.id',
                'prerequisites.number',
                'prerequisites.title',
                'prerequisites.kind',
                'prerequisites.completed_at',
                'prerequisites.objective_id',
                'prerequisite_objectives.key as objective_key',
                'prerequisite_objectives.title as objective_title',
            ])
            ->groupBy('dependent_id');
    }

    /**
     * "Faltan N estaciones para el hito X": the first open milestone of the
     * plan and how many open items come before it.
     *
     * @param  Collection<int, Item>  $items
     * @return array{title: string, remaining: int}|null
     */
    private static function nextMilestone(Collection $items): ?array
    {
        $remaining = 0;

        foreach ($items as $item) {
            if ($item->state === ItemState::Done) {
                continue;
            }

            if ($item->kind === ItemKind::Milestone) {
                return ['title' => $item->title, 'remaining' => $remaining];
            }

            $remaining++;
        }

        return null;
    }

    /**
     * The 5-point plan as the screens show it (null points stay null).
     *
     * @return array<string, mixed>|null
     */
    public static function controlPlan(?ControlPlan $controlPlan): ?array
    {
        if ($controlPlan === null) {
            return null;
        }

        return [
            'outcome' => $controlPlan->outcome,
            'deadline' => $controlPlan->deadline?->toDateString(),
            'metric' => [
                'name' => $controlPlan->metric_name,
                'target' => $controlPlan->metric_target === null ? null : (float) $controlPlan->metric_target,
                'current' => $controlPlan->metric_current === null ? null : (float) $controlPlan->metric_current,
                'progress_percent' => $controlPlan->metricProgressPercent(),
            ],
            'risks' => $controlPlan->risks ?? [],
            'contingency' => $controlPlan->contingency,
            'missing' => array_keys($controlPlan->missingPoints()),
        ];
    }
}
