<?php

namespace App\Actions\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Moves one model a single place within its ordered siblings and rewrites
 * every sibling's `position` as 0..n-1 (manual order of plans and items).
 */
final class Reorderer
{
    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $siblings  ordered by position
     * @param  TModel  $moving
     */
    public static function move(Collection $siblings, Model $moving, int $direction): void
    {
        $ordered = $siblings->values()->all();
        $index = collect($ordered)->search(fn (Model $sibling): bool => $sibling->is($moving));

        if ($index === false) {
            return;
        }

        $target = max(0, min(count($ordered) - 1, $index + ($direction < 0 ? -1 : 1)));

        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        foreach ($ordered as $position => $sibling) {
            if ((int) $sibling->getAttribute('position') !== $position) {
                $sibling->setAttribute('position', $position);
                $sibling->save();
            }
        }
    }
}
