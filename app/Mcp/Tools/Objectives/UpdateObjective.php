<?php

namespace App\Mcp\Tools\Objectives;

use App\Actions\Objectives\UpdateObjective as UpdateObjectiveAction;
use App\Actions\Support\Actor;
use App\Enums\ControlZone;
use App\Http\Requests\UpdateObjectiveRequest;
use App\Http\Resources\ObjectiveTree;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResolvesObjectiveItems;
use App\Mcp\Support\ResourceLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: minor (applied directly and audited) — edits an objective\'s title, identity statement and Control 5-point plan, exactly like the web form. Only the fields sent change; the key never changes. An active objective can never lose one of its five points. Returns the updated objective with its web URL.')]
class UpdateObjective extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private UpdateObjectiveAction $updateObjective,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);
        $objective = $this->objectiveOrError($user, $request->get('objective_key'));

        if ($objective instanceof Response) {
            return $objective;
        }

        $validated = $this->formRequests->replay(UpdateObjectiveRequest::class, $request->all(), $user)->validated();

        $objective = ($this->updateObjective)(Actor::aiMcp($user), $objective, $validated);

        return Response::json([
            'objective' => [
                'key' => $objective->key,
                'title' => $objective->title,
                'identity_statement' => $objective->identity_statement,
                'control_plan' => ObjectiveTree::controlPlan($objective->controlPlan),
                'url' => $this->links->objective($objective),
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
            'objective_key' => $schema->string()
                ->description('Key of the objective to update (e.g. "SALUD").')
                ->required(),
            'title' => $schema->string(),
            'identity_statement' => $schema->string(),
            'outcome' => $schema->string(),
            'deadline' => $schema->string()->description('Y-m-d.'),
            'metric' => $schema->object([
                'name' => $schema->string(),
                'target' => $schema->number(),
                'current' => $schema->number(),
            ]),
            'risks' => $schema->array()->items($schema->string()),
            'contingency' => $schema->string(),
            'control_map' => $schema->array()
                ->items($schema->object([
                    'zone' => $schema->string()->enum(ControlZone::class)->required(),
                    'text' => $schema->string()->required(),
                ])),
        ];
    }
}
