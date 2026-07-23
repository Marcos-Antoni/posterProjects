<?php

namespace App\Mcp\Tools\Habits;

use App\Enums\RecurrenceType;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Habit;
use App\Models\HabitDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the authenticated user\'s active habits scheduled for today (UTC-6), with each one\'s progress for the day and, for weekly-quota habits, how many days of the current week are already recorded. Same data as the "Today" view. Use list-habits to see every habit, including archived ones.')]
class TodayHabits extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        Gate::forUser($user)->authorize('viewAny', Habit::class);

        $today = Habit::todayLocalDate();
        $weekStart = $today->clone()->startOfWeek(CarbonInterface::MONDAY);

        $habits = $user->habits()
            ->whereNull('archived_at')
            ->with(['days' => fn ($query) => $query->whereBetween(
                'entry_date',
                [$weekStart->toDateString(), $today->toDateString()],
            )])
            ->orderBy('name')
            ->get()
            ->filter(fn (Habit $habit): bool => $habit->isScheduledOn($today))
            ->values()
            ->map(fn (Habit $habit): array => [
                'id' => $habit->id,
                'name' => $habit->name,
                'habit_type' => $habit->habit_type,
                'unit' => $habit->unit,
                'daily_target' => $habit->daily_target,
                'recurrence_type' => $habit->recurrence_type,
                'weekdays' => $habit->weekdays,
                'times_per_week' => $habit->times_per_week,
                'planned_time' => $habit->planned_time,
                'today' => $this->todayProgress($habit, $today),
                'week_recorded_days' => $habit->recurrence_type === RecurrenceType::TimesPerWeek
                    ? $habit->days->count()
                    : null,
                'url' => $this->links->habit($habit),
            ]);

        return Response::json([
            'date' => $today->toDateString(),
            'habits' => $habits->all(),
        ]);
    }

    /**
     * The habit's persisted aggregate for today, or null when nothing
     * has been logged yet. Reads the eager-loaded current-week days.
     *
     * @return array{accumulated_amount: int, completion_percent: int, completed: bool, planned_delta_minutes: int|null}|null
     */
    private function todayProgress(Habit $habit, CarbonInterface $today): ?array
    {
        $row = $habit->days->first(
            fn (HabitDay $day): bool => $day->entry_date->isSameDay($today),
        );

        if ($row === null) {
            return null;
        }

        return [
            'accumulated_amount' => $row->accumulated_amount,
            'completion_percent' => $row->completion_percent,
            'completed' => $row->completed,
            'planned_delta_minutes' => $row->planned_delta_minutes,
        ];
    }
}
