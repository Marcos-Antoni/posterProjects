<?php

namespace App\Actions\Retirement;

use App\Models\Item;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The result of `RetireElement`: the history row, the items that became
 * available because the element left (unlock-graph spec), the elements a
 * split created, and how many children were moved or retired along.
 */
final readonly class RetirementResult
{
    /**
     * @param  Collection<int, Item>  $unlocked
     * @param  Collection<int, Model>  $created
     */
    public function __construct(
        public Retirement $retirement,
        public Collection $unlocked,
        public Collection $created,
        public int $moved,
        public int $cascaded,
    ) {}
}
