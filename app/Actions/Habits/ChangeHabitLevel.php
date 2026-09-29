<?php

namespace App\Actions\Habits;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsHabits;
use App\Actions\Support\Operation;
use App\Enums\HabitType;
use App\Models\Habit;
use Illuminate\Validation\ValidationException;

/**
 * Applies a level of the habit's ladder (habits spec "Habits Scale
 * Progressively"): the habit takes that level's 2-minute version and, for a
 * quantitative habit, its target. The streak is never reset — it is derived
 * from the day history, which this never touches. Only the owner applies it;
 * for AI it is a major operation.
 */
class ChangeHabitLevel
{
    use GuardsHabits;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Habit $habit, int $level): Habit
    {
        $this->ensureHabitOwned($actor, $habit);

        $ladder = $habit->level_ladder ?? [];

        if ($level < 1 || $level > count($ladder)) {
            throw ValidationException::withMessages(['level' => 'El nivel actual tiene que ser uno de la escalera.']);
        }

        return $this->transaction->run($actor, Operation::ChangeHabitLevel, $habit, function () use ($habit, $ladder, $level): Habit {
            $step = $ladder[$level - 1];
            $attributes = [
                'level' => $level,
                'level_started_on' => Habit::todayLocalDate()->toDateString(),
                'two_minute_version' => $step['two_minute_version'] !== '' ? $step['two_minute_version'] : $habit->two_minute_version,
            ];

            if ($habit->habit_type === HabitType::Quantitative && ($step['target'] ?? null) !== null) {
                $attributes['daily_target'] = (int) $step['target'];
            }

            $habit->update($attributes);

            return $habit;
        });
    }
}
