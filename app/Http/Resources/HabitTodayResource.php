<?php

namespace App\Http\Resources;

use App\Enums\RecurrenceType;
use App\Models\Habit;
use App\Models\HabitDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * The single assembler for the "today" list and the tap-write responses
 * (increment, decrement, two-minute): `today()` maps it over the collection,
 * and the writes call it on the single habit after `$habit->load([...])`. One class, one method, so the write responses are
 * shape-identical to a list element by construction, not by convention.
 *
 * Reads the day row from the already eager-loaded `days` relation — never
 * issues a query of its own.
 *
 * @mixin Habit
 */
class HabitTodayResource extends JsonResource
{
    private function __construct(private readonly Habit $habitModel, private readonly Carbon $today)
    {
        parent::__construct($habitModel);
    }

    public static function forHabit(Habit $habit, Carbon $today): self
    {
        return new self($habit, $today);
    }

    /**
     * Transform the resource into an array.
     *
     * Pinned to exactly 17 fields: the 12 legacy fields (names, types and
     * semantics unchanged, so an unmodified posterMobile keeps working) plus
     * `two_minute_version`, `shown_up`, `streak_current`, `streak_state` and
     * `objective_key` (api-habits spec). The day-derived fields are cast and
     * flattened to their zero/false value when no `habit_days` row exists yet
     * for `$today` — "flattened, never null". `week_recorded_days` is
     * populated only for `TimesPerWeek` habits (days of the current
     * Monday-based week with a record); every other recurrence reports
     * `null`. Expects `days` (all of them: the tolerant streak reads the
     * history) and `objective` loaded.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $habit = $this->habitModel;
        $today = $this->today;

        $day = $habit->days->first(
            fn (HabitDay $day): bool => $day->entry_date->isSameDay($today),
        );

        $history = $habit->history($today);
        $streak = $history->streak();

        return [
            'id' => $habit->id,
            'date' => $today->toDateString(),
            'name' => $habit->name,
            'habit_type' => $habit->habit_type->value,
            'unit' => $habit->unit,
            'target' => $habit->targetAmount(),
            'accumulated_amount' => (int) ($day?->accumulated_amount ?? 0),
            'completion_percent' => (int) ($day?->completion_percent ?? 0),
            'completed' => (bool) ($day?->completed ?? false),
            'peak_amount' => (int) ($day?->peak_amount ?? 0),
            'times_per_week' => $habit->times_per_week,
            'week_recorded_days' => $habit->recurrence_type === RecurrenceType::TimesPerWeek
                ? $history->recordedDaysThisWeek()
                : null,
            'two_minute_version' => (string) $habit->two_minute_version,
            'shown_up' => $day !== null && $day->isShownUp(),
            'streak_current' => $streak->current,
            'streak_state' => $streak->state->value,
            'objective_key' => $habit->objective?->key,
        ];
    }
}
