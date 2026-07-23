<?php

namespace App\Mcp\Tools\Habits;

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use App\Http\Requests\UpdateHabitRequest as UpdateHabitFormRequest;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update an existing personal habit — name, type, recurrence, and their dependent fields. Owner only. The edit always submits the full set of fields: switching habit_type or recurrence_type clears whatever no longer applies (e.g. changing to yes/no clears unit and daily_target), mirroring the web form.')]
class UpdateHabit extends Tool
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
            UpdateHabitFormRequest::class,
            $request->all(),
            $user,
            ['habit' => $habit],
        )->validated();

        // Same reset as `HabitController::update()`: conditional fields
        // are cleared first so switching type or recurrence never leaves
        // stale values behind — the validated payload overrides whichever
        // ones still apply.
        $habit->update([
            'unit' => null,
            'daily_target' => null,
            'weekdays' => null,
            'times_per_week' => null,
            ...$validated,
        ]);

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
                'url' => $this->links->habit($habit),
            ],
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
                ->description('Id of the habit to update. Must belong to the caller.')
                ->required(),
            'name' => $schema->string()
                ->description('Habit name.')
                ->required(),
            'habit_type' => $schema->string()
                ->description('How completion is measured.')
                ->enum(HabitType::class)
                ->required(),
            'unit' => $schema->string()
                ->description('Unit of measurement (e.g. "pages"). Required when habit_type is "quantitative"; ignored otherwise.'),
            'daily_target' => $schema->integer()
                ->description('Daily target amount. Required when habit_type is "quantitative"; ignored otherwise.'),
            'recurrence_type' => $schema->string()
                ->description('How often the habit is expected.')
                ->enum(RecurrenceType::class)
                ->required(),
            'weekdays' => $schema->array()
                ->items($schema->integer()->min(1)->max(7))
                ->description('ISO-8601 weekdays (1=Monday..7=Sunday), at least one, no duplicates. Required when recurrence_type is "specific_weekdays"; ignored otherwise.'),
            'times_per_week' => $schema->integer()
                ->description('Times per week, between 1 and 7. Required when recurrence_type is "times_per_week"; ignored otherwise.'),
            'planned_time' => $schema->string()
                ->description('Optional planned time of day, "H:i" (e.g. "07:30").'),
        ];
    }
}
