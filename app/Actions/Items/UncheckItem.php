<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Actions\Support\PlanStateRecalculator;
use App\Models\FocusSession;
use App\Models\Item;

/**
 * Undoes a check without penalty (issues spec "A Checked Item Can Be
 * Unchecked Without Penalty"). Dependents that are not done become locked
 * again; an active dependent stops being the Now task and its open focus
 * session is closed, keeping its history. A done plan returns to active.
 * Unchecking an item that is not done is a no-op.
 */
class UncheckItem
{
    use GuardsObjectives;

    public function __construct(
        private DomainTransaction $transaction,
        private PlanStateRecalculator $planStates,
    ) {}

    public function __invoke(Actor $actor, Item $item): Item
    {
        $this->ensureItemWritable($actor, $item);

        if ($item->completed_at === null) {
            return $item;
        }

        return $this->transaction->run($actor, Operation::UncheckItem, $item, function () use ($item): Item {
            $item->update(['completed_at' => null]);

            $relocked = $item->dependents()
                ->whereNull('items.completed_at')
                ->whereNull('items.retired_at')
                ->where('items.is_active', true)
                ->pluck('items.id');

            if ($relocked->isNotEmpty()) {
                Item::query()->whereIn('id', $relocked)->update(['is_active' => false]);

                FocusSession::query()
                    ->whereIn('item_id', $relocked)
                    ->whereNull('ended_at')
                    ->update(['ended_at' => now(), 'end_reason' => 'relocked']);
            }

            $this->planStates->recalculate($item->plan);

            return $item->refresh();
        });
    }
}
