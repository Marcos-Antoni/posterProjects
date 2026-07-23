<?php

namespace App\Mcp\Tools\Habits;

use App\Http\Requests\StoreHabitEntryRequest;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Habit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Record an entry against a habit for today (UTC-6). Yes/no habits always log 1 (a single check-in); quantitative habits require a positive amount, which accumulates into the day\'s total. Archived habits reject new entries — reactivate first with unarchive-habit. Owner only.')]
class LogHabitEntry extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private ResourceLinker $links,
    ) {}

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

        $validated = $this->formRequests->replay(
            StoreHabitEntryRequest::class,
            $request->all(),
            $user,
            ['habit' => $habit],
        )->validated();

        // Same call as `HabitEntryController::store()` — the UTC-6 rollup
        // transaction lives entirely in `Habit::recordEntry()`.
        $amount = $validated['amount'] ?? null;

        // Same cast as HabitEntryController: the `integer` rule lets
        // numeric strings through, so is_int() would silently log 1.
        $habit->recordEntry(is_numeric($amount) ? (int) $amount : 1);

        $today = Habit::todayLocalDate();
        $day = $habit->days()->where('entry_date', $today->toDateString())->firstOrFail();

        return Response::json([
            'day' => [
                'entry_date' => $day->entry_date->toDateString(),
                'accumulated_amount' => $day->accumulated_amount,
                'completion_percent' => $day->completion_percent,
                'completed' => $day->completed,
                'planned_delta_minutes' => $day->planned_delta_minutes,
            ],
            'url' => $this->links->habit($habit),
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
                ->description('Id of the habit to log an entry against. Must belong to the caller.')
                ->required(),
            'amount' => $schema->integer()
                ->description('Partial amount to log. Required for quantitative habits; omit for yes/no habits, which always log 1.'),
        ];
    }
}
