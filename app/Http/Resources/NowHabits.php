<?php

namespace App\Http\Resources;

use App\Enums\RecurrenceType;
use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\HabitEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The compact "Hábitos de hoy" row of the Now screen (now-focus: "the
 * current habit checks for today in a compact row"; mockup 02): the owner's
 * non-archived habits scheduled today (UTC-6), in name order, and — set
 * apart, never as a miss — the weekday habits that do not apply today with
 * the next day they do. Two queries whatever the number of habits.
 *
 * `two_minute_version` is read defensively: the column arrives with Phase 4
 * (habits spec "Every Habit Has A 2-Minute Version"); until then it is null.
 */
final class NowHabits
{
    /**
     * @return array{scheduled: list<array<string, mixed>>, resting: list<array{id: int, name: string, next_weekday: int|null}>}
     */
    public static function forOwner(User $owner): array
    {
        $timezone = (string) config('habits.timezone');
        $today = Habit::todayLocalDate();
        $dayStart = Carbon::parse($today->toDateString(), $timezone)->utc();

        $habits = $owner->habits()
            ->whereNull('archived_at')
            ->with([
                'days' => fn ($query) => $query->where('entry_date', $today->toDateString()),
                'entries' => fn ($query) => $query
                    ->where('logged_at', '>=', $dayStart)
                    ->where('logged_at', '<', $dayStart->clone()->addDay())
                    ->orderBy('logged_at'),
            ])
            ->orderBy('name')
            ->get();

        $scheduled = [];
        $resting = [];

        foreach ($habits as $habit) {
            if (! $habit->isScheduledOn($today)) {
                $resting[] = [
                    'id' => $habit->id,
                    'name' => $habit->name,
                    'next_weekday' => self::nextWeekday($habit, $today->dayOfWeekIso),
                ];

                continue;
            }

            /** @var HabitDay|null $day */
            $day = $habit->days->first();
            /** @var HabitEntry|null $first */
            $first = $habit->entries->first();
            $twoMinute = $habit->getAttribute('two_minute_version');

            $scheduled[] = [
                'id' => $habit->id,
                'name' => $habit->name,
                'habit_type' => $habit->habit_type->value,
                'unit' => $habit->unit,
                'daily_target' => $habit->daily_target,
                'accumulated_amount' => $day->accumulated_amount ?? 0,
                'completed' => (bool) ($day->completed ?? false),
                'done_at' => $day?->completed ? $first?->logged_at->utc()->toIso8601String() : null,
                'planned_time' => $habit->planned_time === null ? null : substr($habit->planned_time, 0, 5),
                'two_minute_version' => is_string($twoMinute) && $twoMinute !== '' ? $twoMinute : null,
            ];
        }

        return ['scheduled' => $scheduled, 'resting' => $resting];
    }

    /**
     * The next ISO weekday (1 = Monday … 7 = Sunday) a specific-weekdays
     * habit applies after today.
     */
    private static function nextWeekday(Habit $habit, int $todayIso): ?int
    {
        if ($habit->recurrence_type !== RecurrenceType::SpecificWeekdays || empty($habit->weekdays)) {
            return null;
        }

        for ($offset = 1; $offset <= 7; $offset++) {
            $candidate = (($todayIso - 1 + $offset) % 7) + 1;

            if (in_array($candidate, array_map(intval(...), $habit->weekdays), true)) {
                return $candidate;
            }
        }

        return null;
    }
}
