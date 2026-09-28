<?php

namespace App\Actions\Habits;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsHabits;
use App\Actions\Support\Operation;
use App\Models\Habit;
use App\Models\HabitDay;
use Illuminate\Validation\ValidationException;

/**
 * The "Deshacer" of the 2-minute toast: clears today's (UTC-6) 2-minute flag
 * and removes the day row when nothing else was recorded on it. Only today,
 * never `completed`, never a past day.
 */
class UndoTwoMinute
{
    use GuardsHabits;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Habit $habit): HabitDay
    {
        $this->ensureHabitLoggable($actor, $habit);

        return $this->transaction->run($actor, Operation::UndoHabitTwoMinute, $habit, function () use ($habit): HabitDay {
            $day = $habit->days()->where('entry_date', Habit::todayLocalDate()->toDateString())->lockForUpdate()->first();

            if ($day === null || ! $day->two_minute_logged) {
                throw ValidationException::withMessages(['habit' => 'Hoy no hay una versión de 2 minutos para deshacer.']);
            }

            $day->update(['two_minute_logged' => false]);

            // A row left with nothing recorded (no amount, no check, no
            // entries) is removed, so no count ever sees an empty day.
            if (! $day->hasRecord() && $day->planned_delta_minutes === null) {
                $day->delete();
            }

            return $day;
        });
    }
}
