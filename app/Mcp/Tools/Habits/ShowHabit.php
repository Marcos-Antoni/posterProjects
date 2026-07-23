<?php

namespace App\Mcp\Tools\Habits;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Habit;
use App\Models\HabitDay;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Show a habit\'s detail: current and best streaks, the completion percent over a period, and its daily series (date, scheduled, completion percent, completed, planned-vs-actual delta) — same data as the habit detail page. Owner only. Read-only; use log-habit-entry to record progress.')]
class ShowHabit extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $habit = $user->habits()->whereKey($request->get('habit_id'))->first();

        if ($habit === null) {
            return Response::error("Habit not found: {$request->get('habit_id')}");
        }

        Gate::forUser($user)->authorize('view', $habit);

        $periodDays = min(365, max(7, (int) ($request->get('days') ?? 30)));

        $to = Habit::todayLocalDate();
        $from = $to->clone()->subDays($periodDays - 1);

        $dayRows = $habit->days()
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (HabitDay $day): string => $day->entry_date->toDateString());

        $series = [];

        for ($cursor = $from->clone(); $cursor->lte($to); $cursor->addDay()) {
            $row = $dayRows->get($cursor->toDateString());

            $series[] = [
                'date' => $cursor->toDateString(),
                'scheduled' => $habit->isScheduledOn($cursor),
                'completion_percent' => $row->completion_percent ?? 0,
                'completed' => $row !== null && $row->completed,
                'planned_delta_minutes' => $row?->planned_delta_minutes,
            ];
        }

        return Response::json([
            'habit' => [
                'id' => $habit->id,
                'name' => $habit->name,
                'habit_type' => $habit->habit_type,
                'unit' => $habit->unit,
                'daily_target' => $habit->daily_target,
                'recurrence_type' => $habit->recurrence_type,
                'weekdays' => $habit->weekdays,
                'times_per_week' => $habit->times_per_week,
                'planned_time' => $habit->planned_time,
                'archived_at' => $habit->archived_at?->toIso8601String(),
                'url' => $this->links->habit($habit),
            ],
            'metrics' => [
                'current_streak' => $habit->currentStreak(),
                'best_streak' => $habit->bestStreak(),
                'completion_percent' => $habit->completionForPeriod($from, $to),
            ],
            'series' => $series,
            'period_days' => $periodDays,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'habit_id' => $schema->integer()
                ->description('Id of the habit to show. Must belong to the caller.')
                ->required(),
            'days' => $schema->integer()
                ->description('Size of the period in days. Clamped between 7 and 365. Defaults to 30.'),
        ];
    }
}
