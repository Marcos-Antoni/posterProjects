<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Enums\TwoMinuteSource;
use App\Models\Item;
use Illuminate\Validation\ValidationException;

/**
 * "Estoy trabado" (now-focus "I'm Stuck Returns One Smaller Physical
 * Action"): the one smaller physical action — written by the owner in the
 * no-AI fallback, or produced by the AI through `shrink-step` (a MINOR
 * operation, ai-operations spec) — replaces ONLY the item's 2-minute
 * version; the previous one goes to its history with who replaced it.
 */
class ShrinkTwoMinuteVersion
{
    use GuardsObjectives;

    public const MISSING = 'Escribí una sola acción física, la más chica que puedas hacer ahora.';

    public const SAME = 'Escribí una acción distinta de la actual: más chica todavía.';

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Item $item, string $smallerAction): Item
    {
        $this->ensureItemWritable($actor, $item);

        if ($item->completed_at !== null) {
            throw ValidationException::withMessages([
                'item' => 'Esta tarea ya está hecha: no hace falta achicarla.',
            ]);
        }

        $smallerAction = trim($smallerAction);

        if ($smallerAction === '') {
            throw ValidationException::withMessages(['two_minute_version' => self::MISSING]);
        }

        if ($smallerAction === $item->two_minute_version) {
            throw ValidationException::withMessages(['two_minute_version' => self::SAME]);
        }

        return $this->transaction->run($actor, Operation::ShrinkStep, $item, function () use ($actor, $item, $smallerAction): Item {
            $item->twoMinuteHistory()->create([
                'text' => $item->two_minute_version,
                'source' => $actor->isAi() ? TwoMinuteSource::Ai : TwoMinuteSource::Owner,
                'replaced_at' => now(),
            ]);

            $item->update(['two_minute_version' => $smallerAction]);

            return $item->refresh();
        });
    }
}
