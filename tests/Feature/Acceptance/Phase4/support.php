<?php

use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
| Phase 4 acceptance (independent tester) helpers. Required with
| `require_once`; the file name does not end in `Test.php`, so Pest never
| runs it. Every date is a UTC-6 (Etc/GMT+6, America/Guatemala without DST)
| calendar day.
*/

/**
 * Freeze the clock at a UTC-6 wall-clock moment.
 */
function p4aAt(string $date, string $time = '12:00'): Carbon
{
    $moment = Carbon::parse("{$date} {$time}", 'Etc/GMT+6');

    test()->travelTo($moment);

    return $moment;
}

/**
 * A habit whose creation instant is 08:00 UTC-6 of the given day.
 *
 * @param  array<string, mixed>  $attributes
 */
function p4aHabit(string $createdOn, array $attributes = [], ?Closure $state = null, ?User $user = null): Habit
{
    $factory = Habit::factory();

    if ($user !== null) {
        $factory = $factory->for($user);
    }

    if ($state !== null) {
        $factory = $state($factory);
    }

    return $factory->create([
        'created_at' => Carbon::parse("{$createdOn} 08:00", 'Etc/GMT+6'),
        ...$attributes,
    ]);
}

/**
 * Record a day row. `done` completed, `two` only the 2-minute version,
 * `partial` some amount but neither completed nor 2-minute (a miss).
 */
function p4aDay(Habit $habit, string $date, string $how = 'done'): HabitDay
{
    return HabitDay::factory()->create([
        'habit_id' => $habit->id,
        'entry_date' => $date,
        'accumulated_amount' => match ($how) {
            'done' => max(1, (int) $habit->daily_target),
            'partial' => 1,
            default => 0,
        },
        'peak_amount' => match ($how) {
            'done' => max(1, (int) $habit->daily_target),
            'partial' => 1,
            default => 0,
        },
        'completion_percent' => $how === 'done' ? 100 : 0,
        'completed' => $how === 'done',
        'two_minute_logged' => $how === 'two',
    ]);
}

/**
 * Record the given outcome on each listed date.
 *
 * @param  list<string>  $dates
 */
function p4aDays(Habit $habit, array $dates, string $how = 'done'): void
{
    foreach ($dates as $date) {
        p4aDay($habit, $date, $how);
    }
}

/**
 * Every date of an inclusive range.
 *
 * @return list<string>
 */
function p4aRange(string $from, string $to): array
{
    $dates = [];

    for ($cursor = Carbon::parse($from); $cursor->lte(Carbon::parse($to)); $cursor->addDay()) {
        $dates[] = $cursor->toDateString();
    }

    return $dates;
}

/**
 * Bearer headers with a fresh token of the given abilities.
 *
 * @param  list<string>  $abilities
 * @return array<string, string>
 */
function p4aBearer(User $user, string $name = 'mobile', array $abilities = ['mobile']): array
{
    return ['Authorization' => 'Bearer '.$user->createToken($name, $abilities)->plainTextToken, 'Accept' => 'application/json'];
}
