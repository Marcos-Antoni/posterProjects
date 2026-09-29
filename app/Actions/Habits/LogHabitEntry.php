<?php

namespace App\Actions\Habits;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsHabits;
use App\Actions\Support\Operation;
use App\Models\Habit;
use App\Models\HabitEntry;

/**
 * Records an entry against a habit for the current UTC-6 day (see
 * `Habit::recordEntry()`). Yes/no habits log 1. A retired habit rejects
 * it. Minor for AI (only for what Marco named).
 */
class LogHabitEntry
{
    use GuardsHabits;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Habit $habit, int $amount = 1): HabitEntry
    {
        $this->ensureHabitLoggable($actor, $habit);

        return $this->transaction->run($actor, Operation::LogHabitEntry, $habit, fn (): HabitEntry => $habit->recordEntry(max(1, $amount)));
    }
}
