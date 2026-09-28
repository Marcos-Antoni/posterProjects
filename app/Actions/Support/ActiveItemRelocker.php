<?php

namespace App\Actions\Support;

use App\Models\FocusSession;
use App\Models\Item;

/**
 * A locked task cannot be active (now-focus spec). After any change that can
 * give an item a new open prerequisite (restoring one, re-hanging dependents
 * on another task, splitting into open parts), the owner's active item is
 * released exactly as `UncheckItem` does: `is_active = false` and its open
 * focus session closed with end reason `relocked`.
 */
class ActiveItemRelocker
{
    /**
     * @return list<int> ids of the items released
     */
    public function relockLockedActive(int $ownerId): array
    {
        $relocked = array_values(Item::query()
            ->withState()
            ->where('items.is_active', true)
            ->whereHas('objective', fn ($query) => $query->where('user_id', $ownerId))
            ->get()
            ->filter(fn (Item $item): bool => $item->hasOpenPrerequisite())
            ->map(fn (Item $item): int => $item->id)
            ->all());

        if ($relocked === []) {
            return [];
        }

        Item::query()->whereIn('id', $relocked)->update(['is_active' => false]);

        FocusSession::query()
            ->whereIn('item_id', $relocked)
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'end_reason' => 'relocked']);

        return $relocked;
    }
}
