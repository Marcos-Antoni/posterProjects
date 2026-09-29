<?php

namespace App\Actions\Habits;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsHabits;
use App\Actions\Support\Operation;
use App\Models\Habit;

/**
 * Updates a habit from the full form (habits spec): the 2-minute version stays
 * required; fields that no longer apply to the type or recurrence are reset;
 * relinking or unlinking the objective/plan never touches history or streak.
 * Major for AI.
 */
class UpdateHabit
{
    use GuardsHabits;

    public function __construct(
        private DomainTransaction $transaction,
        private HabitWriter $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Actor $actor, Habit $habit, array $data): Habit
    {
        $this->ensureHabitOwned($actor, $habit);

        $attributes = $this->writer->attributes($actor, $data, $habit);

        return $this->transaction->run($actor, Operation::UpdateHabit, $habit, function () use ($habit, $attributes): Habit {
            $this->keepPastSchedule($habit, $attributes);

            $habit->update($attributes);

            return $habit;
        });
    }

    /**
     * A schedule change applies from today on: the schedule in force until
     * yesterday is stored (effective-dated) so past days keep being evaluated
     * with it — editing never turns past rest days into misses. Nothing is
     * stored when the old schedule never governed a past day (the habit
     * started today, or the schedule was already changed today).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function keepPastSchedule(Habit $habit, array $attributes): void
    {
        $changed = $habit->recurrence_type !== $attributes['recurrence_type']
            || ($habit->weekdays ?? []) !== ($attributes['weekdays'] ?? [])
            || $habit->times_per_week !== $attributes['times_per_week'];

        if (! $changed) {
            return;
        }

        $yesterday = Habit::todayLocalDate()->subDay();
        $start = $habit->history()->startDate();
        $alreadyChangedToday = $habit->schedulePeriods()->where('valid_until', '>=', $yesterday->toDateString())->exists();

        if ($yesterday->lt($start) || $alreadyChangedToday) {
            return;
        }

        $habit->schedulePeriods()->create([
            'recurrence_type' => $habit->recurrence_type,
            'weekdays' => $habit->weekdays,
            'times_per_week' => $habit->times_per_week,
            'valid_until' => $yesterday->toDateString(),
        ]);

        $habit->unsetRelation('schedulePeriods');
    }
}
