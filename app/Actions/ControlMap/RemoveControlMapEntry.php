<?php

namespace App\Actions\ControlMap;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Models\ControlMapEntry;

/**
 * Removes a control-map note. Control-map entries are plain notes of the
 * 5-point plan, not elements of the tree, so they are not subject to the
 * retirement protocol (which covers objectives, plans, items, habits and
 * captures).
 */
class RemoveControlMapEntry
{
    use GuardsObjectives;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, ControlMapEntry $entry): void
    {
        $objective = $entry->owningObjective();

        $this->ensureOwned($actor, $objective);
        $this->ensureWritable($objective);

        $this->transaction->run($actor, Operation::EditControlMap, $entry, function () use ($entry): void {
            $entry->delete();
        });
    }
}
