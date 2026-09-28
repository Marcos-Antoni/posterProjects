<?php

namespace App\Actions\Support;

use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Shared guards of the domain actions: the target must belong to the
 * actor's owner (anything else is "not found", never "forbidden") and the
 * objective must be writable (closed objectives are read-only, retired ones
 * are hidden).
 */
trait GuardsObjectives
{
    /**
     * @throws ModelNotFoundException<Objective>
     */
    protected function ensureOwned(Actor $actor, Objective $objective): void
    {
        if ($objective->user_id !== $actor->user->id) {
            throw (new ModelNotFoundException)->setModel(Objective::class, [$objective->id]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function ensureWritable(Objective $objective): void
    {
        $message = match ($objective->state) {
            ObjectiveState::Closed => 'El objetivo está cerrado: reabrilo para cambiarlo.',
            ObjectiveState::Retired => 'El objetivo está retirado: restauralo desde Retirados para cambiarlo.',
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['objective' => $message]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function ensurePlanWritable(Plan $plan): void
    {
        if ($plan->state === PlanState::Retired) {
            throw ValidationException::withMessages(['plan' => 'El plan está retirado: restauralo desde Retirados para usarlo.']);
        }
    }

    /**
     * An item the actor may act on: owned, not retired, in a writable
     * objective.
     *
     * @throws ModelNotFoundException<Item>|ValidationException
     */
    protected function ensureItemWritable(Actor $actor, Item $item): void
    {
        $this->ensureOwned($actor, $item->objective);

        if ($item->retired_at !== null) {
            throw (new ModelNotFoundException)->setModel(Item::class, [$item->id]);
        }

        $this->ensureWritable($item->objective);
    }
}
