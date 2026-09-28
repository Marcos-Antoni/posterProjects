<?php

namespace App\Mcp\Tools\Habits;

use App\Actions\Habits\LogTwoMinute as LogTwoMinuteAction;
use App\Actions\Support\Actor;
use App\Mcp\Support\PresentsHabits;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Habit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: minor (applied directly and audited) — only when Marco explicitly said he did the habit\'s 2-minute version. Log the 2-minute version for today (UTC-6), exactly like "Solo los 2 minutos" / "Retomar con 2 minutos" on the web: the day counts as shown-up for the tolerant streak and as an identity vote, but it is never marked completed and the amount does not change. Logging it twice the same day changes nothing. A retired habit is hidden and rejects it. Returns the day and the streak.')]
class LogTwoMinute extends Tool
{
    use PresentsHabits;
    use ResolvesAuthenticatedUser;

    public function __construct(
        private LogTwoMinuteAction $logTwoMinute,
        private ResourceLinker $links,
    ) {}

    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);
        $habit = $this->ownedHabit($user, $request->get('habit_id'));

        if ($habit === null) {
            return Response::error("Habit not found: {$request->get('habit_id')}");
        }

        $day = ($this->logTwoMinute)(Actor::aiMcp($user), $habit);

        $habit->load(['days', 'schedulePeriods', 'objective']);

        return Response::json([
            'day' => [
                'entry_date' => $day->entry_date->toDateString(),
                'accumulated_amount' => $day->accumulated_amount,
                'completed' => $day->completed,
                'two_minute_logged' => $day->two_minute_logged,
                'shown_up' => $day->isShownUp(),
            ],
            'habit' => $this->habitPayload($habit, $habit->history(Habit::todayLocalDate()), $this->links),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'habit_id' => $schema->integer()
                ->description('Id of the habit whose 2-minute version Marco did today. Must belong to the caller.')
                ->required(),
        ];
    }
}
