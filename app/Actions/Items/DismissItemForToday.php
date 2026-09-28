<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Models\Item;
use App\Models\NowDismissal;

/**
 * "Cerrar por hoy" on a suggestion: Now stops suggesting this item for the
 * rest of the UTC-6 day. Nothing else changes; it can still be started.
 */
class DismissItemForToday
{
    use GuardsObjectives;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Item $item): Item
    {
        $this->ensureItemWritable($actor, $item);

        return $this->transaction->run($actor, Operation::DismissItemForToday, $item, function () use ($actor, $item): Item {
            NowDismissal::recordToday($actor->user, $item);

            return $item;
        });
    }
}
