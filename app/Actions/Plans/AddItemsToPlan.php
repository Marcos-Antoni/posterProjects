<?php

namespace App\Actions\Plans;

use App\Actions\Items\AddDependency;
use App\Actions\Items\AddItem;
use App\Actions\Support\Actor;
use App\Actions\Support\LinksIndexedDependencies;
use App\Models\Item;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Items (and optional dependencies between them) added to an EXISTING plan
 * in one atomic unit, reusing `AddItem`/`AddDependency` exactly like
 * `CreateObjectiveTree`. Used by the `add-items` MCP tool (2026-09-29
 * decision: AI creates/edits structure directly, audited) — the same shape
 * the removed `add_items` proposal kind used to build at accept time.
 */
class AddItemsToPlan
{
    use LinksIndexedDependencies;

    public function __construct(
        private AddItem $addItem,
        private AddDependency $addDependency,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  already validated (`StoreItemRequest`)
     * @param  list<array<string, mixed>>  $dependencies  `{prerequisite, dependent}` 0-based indexes into `$items`, in declaration order
     * @return list<Item>
     */
    public function __invoke(Actor $actor, Plan $plan, array $items, array $dependencies): array
    {
        return DB::transaction(function () use ($actor, $plan, $items, $dependencies): array {
            $created = [];

            foreach ($items as $itemData) {
                $created[] = ($this->addItem)($actor, $plan, $itemData);
            }

            $this->linkIndexedDependencies($actor, $this->addDependency, $created, $dependencies);

            return $created;
        });
    }
}
