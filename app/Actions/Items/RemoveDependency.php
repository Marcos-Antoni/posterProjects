<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Models\Item;
use App\Models\ItemDependency;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Removes the edge prerequisite → dependent. The dependent's state is
 * derived, so it may become available immediately. An edge is a relation,
 * not an element, so removing it is not a retirement.
 */
class RemoveDependency
{
    use GuardsObjectives;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Item $prerequisite, Item $dependent): void
    {
        $this->ensureOwned($actor, $dependent->objective);
        $this->ensureWritable($dependent->objective);

        $edge = ItemDependency::query()
            ->where('prerequisite_id', $prerequisite->id)
            ->where('dependent_id', $dependent->id)
            ->first();

        if ($edge === null) {
            throw (new ModelNotFoundException)->setModel(ItemDependency::class);
        }

        $this->transaction->run($actor, Operation::RemoveDependency, $dependent, function () use ($edge): void {
            $edge->delete();
        });
    }
}
