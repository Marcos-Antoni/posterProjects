<?php

namespace App\Actions\Support;

use App\Models\Habit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Shared guards of the habit actions: the habit must belong to the actor's
 * owner (anything else is "not found", never "forbidden"), and a retired
 * habit keeps its history but rejects new entries (retirement protocol).
 */
trait GuardsHabits
{
    /**
     * @throws ModelNotFoundException<Habit>
     */
    protected function ensureHabitOwned(Actor $actor, Habit $habit): void
    {
        if ($habit->user_id !== $actor->user->id) {
            throw (new ModelNotFoundException)->setModel(Habit::class, [$habit->id]);
        }
    }

    /**
     * @throws ModelNotFoundException<Habit>|ValidationException
     */
    protected function ensureHabitLoggable(Actor $actor, Habit $habit): void
    {
        $this->ensureHabitOwned($actor, $habit);

        if ($habit->isRetired()) {
            throw ValidationException::withMessages(['habit' => 'No podés registrar en un hábito retirado.']);
        }
    }
}
