<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Actions\Support\Reorderer;
use App\Models\Item;

/**
 * Moves an item one place up (-1) or down (+1) within its plan. The order
 * is manual; execution order comes from dependencies, not from this.
 */
class MoveItem
{
    use GuardsObjectives;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Item $item, int $direction): Item
    {
        $this->ensureItemWritable($actor, $item);

        return $this->transaction->run($actor, Operation::ReorderItem, $item, function () use ($item, $direction): Item {
            $siblings = Item::query()
                ->where('plan_id', $item->plan_id)
                ->whereNull('retired_at')
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            Reorderer::move($siblings, $item, $direction);

            return $item->refresh();
        });
    }
}
