<?php

namespace App\Mcp\Tools\Plans;

use App\Actions\Plans\UpdatePlan as UpdatePlanAction;
use App\Actions\Support\Actor;
use App\Enums\ControlZone;
use App\Http\Requests\UpdatePlanRequest;
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

#[Description('Nivel IA: minor (applied directly and audited) — edits a plan\'s title, level and Control 5-point plan, exactly like the web plan form. Only the fields sent change. An active (or done) plan can never lose one of its five points. Returns the updated plan with its web URL.')]
class UpdatePlan extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private UpdatePlanAction $updatePlan,
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

        $plan = $this->planOrError($objective, $request->get('plan_id'));

        if ($plan instanceof Response) {
            return $plan;
        }

        $validated = $this->formRequests->replay(UpdatePlanRequest::class, $request->all(), $user)->validated();

        $plan = ($this->updatePlan)(Actor::aiMcp($user), $plan, $validated);

        return Response::json([
            'plan' => [
                'id' => $plan->id,
                'title' => $plan->title,
                'level' => $plan->level,
                'state' => $plan->state->value,
                'control_plan' => ObjectiveTree::controlPlan($plan->controlPlan),
                'url' => $this->links->plan($plan),
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
                ->description('Key of the plan\'s objective (e.g. "SALUD").')
                ->required(),
            'plan_id' => $schema->integer()
                ->description('Id of the plan to update. Must belong to objective_key.')
                ->required(),
            'title' => $schema->string(),
            'level' => $schema->integer()->description('1-99, or omit to leave unchanged.'),
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
