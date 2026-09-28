<?php

namespace App\Actions\Habits;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsHabits;
use App\Actions\Support\Operation;
use App\Models\Habit;
use App\Models\HabitDay;

/**
 * Subtracts one from today's (UTC-6) amount — see `Habit::decrementToday()`:
 * never below zero, never un-completing the day. An archived habit rejects it.
 */
class DecrementHabitEntry
{
    use GuardsHabits;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Habit $habit): HabitDay
    {
        $this->ensureHabitLoggable($actor, $habit);

        return $this->transaction->run($actor, Operation::DecrementHabitEntry, $habit, fn (): HabitDay => $habit->decrementToday());
    }
}
