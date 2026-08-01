<?php

namespace App\Http\Resources;

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use App\Models\Habit;
use App\Models\HabitDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * The single assembler for both the "today" list and the two tap-write
 * responses (design D-4): `today()` maps it over the collection, and both
 * `increment()`/`decrement()` call it on the single habit after
 * `$habit->load([...])`. One class, one method, so the write responses are
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
     * Pinned to exactly these 12 fields. The four day-derived fields are
     * explicitly cast and flattened to their zero/false value when no
     * `habit_days` row exists yet for `$today` — the contract is
     * "flattened, never null" (design: Resource — exactly 12 fields).
     * `week_recorded_days` is populated only for `TimesPerWeek` habits;
     * every other recurrence reports `null`.
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

        $target = $habit->habit_type === HabitType::Quantitative
            ? max(1, (int) $habit->daily_target)
            : 1;

        return [
            'id' => $habit->id,
            'date' => $today->toDateString(),
            'name' => $habit->name,
            'habit_type' => $habit->habit_type->value,
            'unit' => $habit->unit,
            'target' => $target,
            'accumulated_amount' => (int) ($day?->accumulated_amount ?? 0),
            'completion_percent' => (int) ($day?->completion_percent ?? 0),
            'completed' => (bool) ($day?->completed ?? false),
            'peak_amount' => (int) ($day?->peak_amount ?? 0),
            'times_per_week' => $habit->times_per_week,
            'week_recorded_days' => $habit->recurrence_type === RecurrenceType::TimesPerWeek
                ? $habit->days->count()
                : null,
        ];
    }
}
