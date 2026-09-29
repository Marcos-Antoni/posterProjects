<?php

namespace App\Mcp\Tools\Habits;

use App\Actions\Habits\UpdateHabit as UpdateHabitAction;
use App\Actions\Support\Actor;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Http\Requests\UpdateHabitRequest as UpdateHabitFormRequest;
use App\Mcp\Support\HabitInputSchema;
use App\Mcp\Support\PresentsHabits;
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

#[Description('Nivel IA: major — changing a habit (its schedule, level, 2-minute version or links) is a major operation: the AI never applies it on its own (it is refused and must be proposed to Marco). Same validation as the web form, which always submits the full set of fields: the 2-minute version stays required, and switching habit_type or recurrence_type clears whatever no longer applies. Owner only.')]
class UpdateHabit extends Tool
{
    use HabitInputSchema;
    use PresentsHabits;
    use ResolvesAuthenticatedUser;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private UpdateHabitAction $updateHabit,
        private ResourceLinker $links,
    ) {}

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

        $input = $request->all();
        unset($input['habit_id']);
        $payload = $this->habitPayloadFromInput($user, $input);

        if ($payload instanceof Response) {
            return $payload;
        }

        $validated = $this->formRequests->replay(
            UpdateHabitFormRequest::class,
            $payload,
            $user,
            ['habit' => $habit],
        )->validated();

        try {
            ($this->updateHabit)(Actor::aiMcp($user), $habit, $validated);
        } catch (MajorOperationRequiresProposal $exception) {
            return Response::error($exception->getMessage());
        }

        $habit->load(['days', 'schedulePeriods', 'objective']);

        return Response::json([
            'habit' => $this->habitPayload($habit, $habit->history(Habit::todayLocalDate()), $this->links),
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
            ...$this->habitSchema($schema),
        ];
    }
}
