<?php

namespace App\Mcp\Tools\Habits;

use App\Actions\Habits\CreateHabit as CreateHabitAction;
use App\Actions\Support\Actor;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Http\Requests\StoreHabitRequest;
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

#[Description('Nivel IA: major — creating a habit is a major operation: the AI never applies it on its own (it is refused and must be proposed to Marco). Same validation as the web form: a 2-minute version is required; yes/no or quantitative, on a daily, specific-weekdays or times-per-week recurrence; optional identity statement, objective (by key) and plan link, and level ladder. Fields that do not apply to the chosen type or recurrence are dropped.')]
class CreateHabit extends Tool
{
    use HabitInputSchema;
    use PresentsHabits;
    use ResolvesAuthenticatedUser;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private CreateHabitAction $createHabit,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $payload = $this->habitPayloadFromInput($user, $request->all());

        if ($payload instanceof Response) {
            return $payload;
        }

        $validated = $this->formRequests
            ->replay(StoreHabitRequest::class, $payload, $user)
            ->validated();

        try {
            $habit = ($this->createHabit)(Actor::aiMcp($user), $validated);
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
        return $this->habitSchema($schema);
    }
}
