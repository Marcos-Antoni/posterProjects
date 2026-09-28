<?php

namespace App\Mcp\Support;

use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;

/**
 * The single place that maps a model to the absolute web URL (APP_URL
 * based) every MCP tool response links to.
 */
class ResourceLinker
{
    public function objective(Objective $objective): string
    {
        return route('objectives.show', $objective->key);
    }

    public function plan(Plan $plan): string
    {
        return route('objectives.plans.show', [$plan->objective->key, $plan->id]);
    }

    /**
     * An item's deep link. Pass the objective when the caller already has
     * it, so the key accessor never lazy-loads.
     */
    public function item(Item $item, ?Objective $objective = null): string
    {
        $objective ??= $item->objective;

        return route('objectives.items.show', [$objective->key, sprintf('%s-%d', $objective->key, $item->number)]);
    }

    /**
     * An item's deep link from its public key ("SALUD-7").
     */
    public function itemKey(string $itemKey): string
    {
        return route('objectives.items.show', [substr($itemKey, 0, (int) strrpos($itemKey, '-')), $itemKey]);
    }

    /**
     * The Retired view, optionally filtered (phase 6).
     *
     * @param  array<string, string|null>  $filters
     */
    public function retired(array $filters = []): string
    {
        return route('retired.index', array_filter($filters, fn (?string $value): bool => $value !== null));
    }

    public function habit(Habit $habit): string
    {
        return route('habits.show', ['habit' => $habit->id]);
    }
}
