<?php

namespace App\Actions\Support;

use App\Actions\Items\AddDependency;
use App\Models\Item;
use Illuminate\Validation\ValidationException;

/**
 * Resolves `{prerequisite, dependent}` pairs by their 0-based position in a
 * flat, just-created list of items and links them with `AddDependency`
 * (self-edges and cycles are `AddDependency`'s own guards). Shared by
 * `CreateObjectiveTree` and `AddItemsToPlan`, which both build a flat item
 * list in declaration order before resolving `dependencies` against it —
 * exactly like the (now removed) `create_objective`/`add_items` proposal
 * kinds did.
 */
trait LinksIndexedDependencies
{
    /**
     * @param  list<Item>  $items
     * @param  list<array<string, mixed>>  $dependencies
     *
     * @throws ValidationException
     */
    private function linkIndexedDependencies(Actor $actor, AddDependency $addDependency, array $items, array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            $prerequisite = $items[(int) ($dependency['prerequisite'] ?? -1)] ?? null;
            $dependent = $items[(int) ($dependency['dependent'] ?? -1)] ?? null;

            if ($prerequisite === null || $dependent === null) {
                throw ValidationException::withMessages(['dependencies' => 'Una dependencia apunta a una tarea que no está en esta lista.']);
            }

            $addDependency($actor, $prerequisite, $dependent);
        }
    }
}
