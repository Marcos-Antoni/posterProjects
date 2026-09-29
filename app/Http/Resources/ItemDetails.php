<?php

namespace App\Http\Resources;

use App\Enums\ItemKind;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Loads and presents one item with everything its screens need, in a fixed
 * number of queries: the item (with its objective, plan and evidence), its
 * prerequisites and its dependents, each with the derived state. Shared by
 * the web item page, the API item endpoints and the MCP item tools so the
 * three surfaces show one item, not three dialects of it.
 */
final class ItemDetails
{
    /**
     * Eager-load the relations `present()` reads. Neighbours carry their
     * derived state (`withState()`) and their objective (for the key).
     */
    public static function load(Item $item): Item
    {
        // Each neighbour carries its objective key from a join, so the query
        // count is the same with zero, one or many neighbours.
        $neighbours = fn ($query) => $query
            ->withState()
            ->join('objectives as neighbour_objectives', 'neighbour_objectives.id', '=', 'items.objective_id')
            ->addSelect('neighbour_objectives.key as neighbour_objective_key')
            ->orderBy('items.id');

        $item->loadMissing(['objective', 'plan', 'evidence']);
        $item->load(['prerequisites' => $neighbours, 'dependents' => $neighbours]);

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Item $item): array
    {
        return [
            'id' => $item->id,
            'key' => $item->key,
            'kind' => $item->kind->value,
            'title' => $item->title,
            'description' => $item->description,
            'two_minute_version' => $item->two_minute_version,
            'state' => $item->state->value,
            'target_date' => $item->target_date?->toDateString(),
            'plan' => [
                'id' => $item->plan->id,
                'title' => $item->plan->title,
            ],
            'prerequisites' => self::neighbours($item->prerequisites->whereNull('retired_at')),
            'unlocks' => self::neighbours($item->dependents->whereNull('retired_at')),
            'completed_at' => $item->completed_at?->toIso8601String(),
            'evidence' => $item->kind === ItemKind::Milestone && $item->evidence !== null ? [
                'text' => $item->evidence->text,
                'link' => $item->evidence->link,
                'image_url' => $item->evidence->image_path !== null ? Storage::disk('public')->url($item->evidence->image_path) : null,
            ] : null,
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return list<array{key: string, title: string, state: string}>
     */
    public static function neighbours(Collection $items): array
    {
        return array_values($items->map(fn (Item $neighbour): array => [
            'key' => self::keyOf($neighbour),
            'title' => $neighbour->title,
            'state' => $neighbour->state->value,
        ])->all());
    }

    /**
     * The public key, from the joined objective key when `load()` selected it
     * (no lazy load), else from the objective relation.
     */
    private static function keyOf(Item $item): string
    {
        $objectiveKey = $item->getAttribute('neighbour_objective_key');

        return is_string($objectiveKey) ? sprintf('%s-%d', $objectiveKey, $item->number) : $item->key;
    }
}
