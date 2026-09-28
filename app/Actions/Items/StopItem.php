<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Models\Item;

/**
 * "Cerrar por hoy" (design proposal §2: finishing the 2 minutes offers
 * "Seguir" or "Cerrar por hoy", both wins): the active item stops being
 * active — it stays available and is suggested again on Now — and its open
 * focus session closes with `closed-for-today`. Stopping an item that is
 * not active changes nothing. The Now screen's "Cerrar por hoy" also
 * dismisses the item for the day (`DismissItemForToday`).
 */
class StopItem
{
    use GuardsObjectives;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Item $item): Item
    {
        $this->ensureItemWritable($actor, $item);

        if (! $item->is_active) {
            return $item;
        }

        return $this->transaction->run($actor, Operation::StopItem, $item, function () use ($item): Item {
            $item->update(['is_active' => false]);

            $item->focusSessions()->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'closed-for-today']);

            return $item->refresh();
        });
    }
}
