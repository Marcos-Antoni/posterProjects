<?php

use App\Models\Habit;
use App\Models\HabitDay;
use Illuminate\Support\Carbon;

/*
| Phase 4 (habits) test helpers. Required with `require_once` by the habit
| tests; the file name does not end in `Test.php`, so Pest never runs it.
*/

/**
 * Freeze the clock at noon of the given UTC-6 (America/Guatemala) date.
 */
function p4Today(string $date, string $time = '12:00'): Carbon
{
    $moment = Carbon::parse("{$date} {$time}", 'Etc/GMT+6');

    test()->travelTo($moment);

    return $moment;
}

/**
 * Record a habit day row directly. `$how` is `done` (completed), `two`
 * (only the 2-minute version logged) or `partial` (some amount, neither
 * completed nor 2-minute: a miss).
 */
function p4Day(Habit $habit, string $date, string $how = 'done'): HabitDay
{
    return HabitDay::factory()->create([
        'habit_id' => $habit->id,
        'entry_date' => $date,
        'accumulated_amount' => $how === 'done' ? 1 : 0,
        'peak_amount' => $how === 'done' ? 1 : 0,
        'completion_percent' => $how === 'done' ? 100 : 0,
        'completed' => $how === 'done',
        'two_minute_logged' => $how === 'two',
    ]);
}

/**
 * Record the same outcome on every date of an inclusive range.
 */
function p4Days(Habit $habit, string $from, string $to, string $how = 'done'): void
{
    for ($cursor = Carbon::parse($from); $cursor->lte(Carbon::parse($to)); $cursor->addDay()) {
        p4Day($habit, $cursor->toDateString(), $how);
    }
}

/**
 * A habit created (UTC-6) on the given date, so its opportunities start there.
 *
 * @param  array<string, mixed>  $attributes
 */
function p4Habit(string $createdOn, array $attributes = [], ?Closure $state = null): Habit
{
    $factory = Habit::factory();

    if ($state !== null) {
        $factory = $state($factory);
    }

    return $factory->create([
        'created_at' => Carbon::parse("{$createdOn} 08:00", 'Etc/GMT+6'),
        ...$attributes,
    ]);
}
