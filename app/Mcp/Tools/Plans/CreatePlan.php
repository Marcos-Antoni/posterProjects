<?php

namespace App\Mcp\Tools\Plans;

use App\Actions\Plans\CreatePlan as CreatePlanAction;
use App\Actions\Support\Actor;
use App\Enums\ControlZone;
use App\Http\Requests\StorePlanRequest;
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

#[Description('Nivel IA: minor (applied directly and audited) — appends a new plan at the end of an existing objective, exactly like the web plan form. Saved as a draft unless `activate` is true, which requires the plan\'s own complete Control 5-point plan. Use `add-items` afterwards to populate it with tasks/milestones. Returns the new plan with its web URL.')]
class CreatePlan extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private CreatePlanAction $createPlan,
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

        $validated = $this->formRequests->replay(StorePlanRequest::class, $request->all(), $user)->validated();

        $plan = ($this->createPlan)(Actor::aiMcp($user), $objective, $validated);

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
                ->description('Key of the EXISTING objective this plan belongs to (e.g. "SALUD").')
                ->required(),
            'title' => $schema->string()->required(),
            'level' => $schema->integer()
                ->description('Optional level of the objective\'s ladder, 1-99.'),
            'activate' => $schema->boolean()
                ->description('Activate the plan immediately (requires its own complete Control 5-point plan). Defaults to false: saved as a draft.'),
            'outcome' => $schema->string()
                ->description('Part of the plan\'s own Control 5-point plan, optional on a draft.'),
            'deadline' => $schema->string()->description('Y-m-d.'),
            'metric' => $schema->object([
                'name' => $schema->string(),
                'target' => $schema->number(),
                'current' => $schema->number(),
            ])->description('The plan\'s single metric.'),
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
