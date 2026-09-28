<?php

namespace App\Mcp\Tools\Habits;

use App\Mcp\Support\PresentsHabits;
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

#[Description('Nivel IA: read. Show a habit\'s detail — same data as the web habit screen: its 2-minute version, identity statement (own or inherited from its objective), the tolerant streak ("never miss twice": current, best, state ok | at_risk | restart), identity votes for the last 7 and 30 UTC-6 days as a proportion (votes cast of possible — never a score), the level ladder with any level suggestion (only Marco applies it), the completion percent over a period and the daily series (date, scheduled, completion percent, completed, two_minute_logged, shown_up, planned-vs-actual delta). Owner only. Use log-habit-entry or log-two-minute to record progress.')]
class ShowHabit extends Tool
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

        $habit = $this->ownedHabit($user, $request->get('habit_id'));

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
                'two_minute_logged' => $row !== null && $row->two_minute_logged,
                'shown_up' => $row !== null && $row->isShownUp(),
                'planned_delta_minutes' => $row?->planned_delta_minutes,
            ];
        }

        $history = $habit->history($to);
        $streak = $history->streak();

        return Response::json([
            'habit' => $this->habitPayload($habit, $history, $this->links),
            'metrics' => [
                'current_streak' => $streak->current,
                'best_streak' => $streak->best,
                'streak_state' => $streak->state->value,
                'completion_percent' => $habit->completionForPeriod($from, $to),
                'votes_7' => ['cast' => $history->votes(7)->cast, 'possible' => $history->votes(7)->possible],
                'votes_30' => ['cast' => $history->votes(30)->cast, 'possible' => $history->votes(30)->possible],
                'level_suggestion' => $history->levelSuggestion()?->toArray(),
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
