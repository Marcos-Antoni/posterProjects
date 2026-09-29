<?php

namespace App\Actions\Objectives;

use App\Actions\Items\AddDependency;
use App\Actions\Items\AddItem;
use App\Actions\Plans\CreatePlan;
use App\Actions\Support\Actor;
use App\Actions\Support\LinksIndexedDependencies;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * A new objective, its complete 5-point control plan, and any nested
 * plans/items/dependencies — one atomic unit built entirely from the
 * existing per-resource domain actions (`CreateObjective`, `CreatePlan`,
 * `AddItem`, `AddDependency`), each already tiered, gated and audited on its
 * own. Items are created first, in payload order, into a flat list;
 * `dependencies` then resolves by index into that list. Used by the
 * `create-objective` MCP tool (2026-09-29 decision: AI creates/edits
 * structure directly, audited) — the same shape the removed `create_objective`
 * proposal kind used to build at accept time.
 */
class CreateObjectiveTree
{
    use LinksIndexedDependencies;

    public function __construct(
        private CreateObjective $createObjective,
        private CreatePlan $createPlan,
        private AddItem $addItem,
        private AddDependency $addDependency,
    ) {}

    /**
     * @param  array<string, mixed>  $objectiveData  validated (`StoreObjectiveRequest`)
     * @param  list<array<string, mixed>>  $plans  each `{title, level?, items?}`, already validated (`StorePlanRequest`/`StoreItemRequest`)
     * @param  list<array<string, mixed>>  $dependencies  `{prerequisite, dependent}` 0-based indexes into every plan's items, in declaration order
     * @return array{objective: Objective, plans: list<Plan>, items: list<Item>}
     */
    public function __invoke(Actor $actor, array $objectiveData, array $plans, array $dependencies): array
    {
        return DB::transaction(function () use ($actor, $objectiveData, $plans, $dependencies): array {
            $objective = ($this->createObjective)($actor, $objectiveData);

            $createdPlans = [];
            $items = [];

            foreach ($plans as $planData) {
                $plan = ($this->createPlan)($actor, $objective, $planData);
                $createdPlans[] = $plan;

                foreach ((array) ($planData['items'] ?? []) as $itemData) {
                    $items[] = ($this->addItem)($actor, $plan, (array) $itemData);
                }
            }

            $this->linkIndexedDependencies($actor, $this->addDependency, $items, $dependencies);

            return [
                'objective' => $objective->fresh(['controlPlan', 'controlMapEntries']) ?? $objective,
                'plans' => $createdPlans,
                'items' => $items,
            ];
        });
    }
}
