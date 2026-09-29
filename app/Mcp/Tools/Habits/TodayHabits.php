<?php

namespace App\Mcp\Tools\Habits;

use App\Enums\RecurrenceType;
use App\Mcp\Support\PresentsHabits;
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

#[Description('Nivel IA: read. List the authenticated user\'s active habits scheduled for today (UTC-6) — same data as the web "Hábitos de hoy" view: each habit\'s 2-minute version, today\'s progress (amount, completed, two_minute_logged, shown_up), the tolerant streak ("never miss twice": streak_current, streak_state ok | at_risk | restart — at_risk/restart mean "hoy toca volver" with the 2-minute version, never a debt), the objective it hangs from and, for weekly-quota habits, how many days of the current week are recorded. Retired habits are hidden (see retired-view). Use list-habits to see every non-retired habit.')]
class TodayHabits extends Tool
{
    use PresentsHabits;
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

        $habits = $user->habits()
            ->with(['days', 'schedulePeriods', 'objective'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Habit $habit): bool => $habit->isScheduledOn($today))
            ->values()
            ->map(fn (Habit $habit): array => [
                ...$this->habitPayload($habit, $habit->history($today), $this->links),
                'today' => $this->todayProgress($habit, $today),
                'week_recorded_days' => $habit->recurrence_type === RecurrenceType::TimesPerWeek
                    ? $habit->history($today)->recordedDaysThisWeek()
                    : null,
            ]);

        return Response::json([
            'date' => $today->toDateString(),
            'habits' => $habits->all(),
        ]);
    }

    /**
     * The habit's persisted aggregate for today, or null when nothing
     * has been logged yet.
     *
     * @return array{accumulated_amount: int, completion_percent: int, completed: bool, peak_amount: int, two_minute_logged: bool, shown_up: bool, planned_delta_minutes: int|null}|null
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
            'peak_amount' => $row->peak_amount,
            'two_minute_logged' => $row->two_minute_logged,
            'shown_up' => $row->isShownUp(),
            'planned_delta_minutes' => $row->planned_delta_minutes,
        ];
    }
}
