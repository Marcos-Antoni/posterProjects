<?php

namespace App\Actions\Items;

use App\Models\Item;
use Illuminate\Support\Collection;

/**
 * What checking an item did: the item (now done) and the items that became
 * available because of it (unlock-graph spec; `check-item` and the API
 * report them as `unlocked`).
 */
final readonly class CheckResult
{
    /**
     * @param  Collection<int, Item>  $unlocked
     */
    public function __construct(
        public Item $item,
        public Collection $unlocked,
    ) {}
}
