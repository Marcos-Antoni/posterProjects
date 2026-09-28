<?php

namespace App\Actions\Habits;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsHabits;
use App\Actions\Support\Operation;
use App\Models\Habit;
use App\Models\HabitDay;

/**
 * Records that the habit's 2-minute version was done today (UTC-6): the day
 * counts as shown-up for the tolerant streak and identity votes, but is never
 * marked `completed` by it. Idempotent within the day. Also the "volver"
 * (restart) action after a miss. A retired habit rejects it. Minor for AI.
 */
class LogTwoMinute
{
    use GuardsHabits;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Habit $habit): HabitDay
    {
        $this->ensureHabitLoggable($actor, $habit);

        return $this->transaction->run($actor, Operation::LogHabitTwoMinute, $habit, function () use ($habit): HabitDay {
            $today = Habit::todayLocalDate()->toDateString();

            HabitDay::query()->insertOrIgnore([
                'habit_id' => $habit->id,
                'entry_date' => $today,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /** @var HabitDay $day */
            $day = $habit->days()->where('entry_date', $today)->lockForUpdate()->firstOrFail();

            if (! $day->two_minute_logged) {
                $day->update(['two_minute_logged' => true]);
            }

            return $day;
        });
    }
}
