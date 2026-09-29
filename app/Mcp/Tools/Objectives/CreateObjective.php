<?php

namespace App\Mcp\Tools\Objectives;

use App\Actions\Objectives\CreateObjectiveTree;
use App\Actions\Support\Actor;
use App\Enums\ControlZone;
use App\Enums\ItemKind;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\StoreObjectiveRequest;
use App\Http\Requests\StorePlanRequest;
use App\Http\Resources\ObjectiveTree as ObjectiveTreeResource;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Item;
use App\Models\Plan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: minor (applied directly and audited) — creates a new objective with its complete Control 5-point plan (outcome, deadline, metric, risks, contingency — all required, exactly like the web form) and, optionally, its plans and their tasks/milestones with dependencies between them, all in one atomic operation. `dependencies` references items by their 0-based position in declaration order (every plan\'s items, in order). Refused (nothing written) if the key is taken, the shape is wrong, or a dependency index is out of range or closes a cycle. Returns the new objective, plans and items with their web URLs.')]
class CreateObjective extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private CreateObjectiveTree $createObjectiveTree,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);
        $actor = Actor::aiMcp($user);

        $objectiveData = $this->formRequests
            ->replay(StoreObjectiveRequest::class, $request->all(), $user)
            ->validated();

        $plans = [];

        foreach ((array) $request->get('plans', []) as $planData) {
            $planData = (array) $planData;

            $validatedPlan = $this->formRequests
                ->replay(StorePlanRequest::class, $planData, $user)
                ->validated();

            $validatedPlan['items'] = [];

            foreach ((array) ($planData['items'] ?? []) as $itemData) {
                $validatedPlan['items'][] = $this->formRequests
                    ->replay(StoreItemRequest::class, (array) $itemData, $user)
                    ->validated();
            }

            $plans[] = $validatedPlan;
        }

        $dependencies = array_values(array_filter(
            (array) $request->get('dependencies', []),
            fn ($dependency): bool => is_array($dependency),
        ));

        $result = ($this->createObjectiveTree)($actor, $objectiveData, $plans, $dependencies);

        return Response::json([
            'objective' => [
                'key' => $result['objective']->key,
                'title' => $result['objective']->title,
                'control_plan' => ObjectiveTreeResource::controlPlan($result['objective']->controlPlan),
                'url' => $this->links->objective($result['objective']),
            ],
            'plans' => array_values(array_map(fn (Plan $plan): array => [
                'id' => $plan->id,
                'title' => $plan->title,
                'url' => $this->links->plan($plan),
            ], $result['plans'])),
            'items' => array_values(array_map(fn (Item $item): array => [
                'key' => $item->key,
                'title' => $item->title,
                'url' => $this->links->item($item, $result['objective']),
            ], $result['items'])),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $itemSchema = fn () => $schema->object([
            'title' => $schema->string()->required(),
            'two_minute_version' => $schema->string()->required(),
            'description' => $schema->string(),
            'kind' => $schema->string()->enum(ItemKind::class),
            'target_date' => $schema->string()->description('Y-m-d'),
        ]);

        $dependencySchema = fn () => $schema->object([
            'prerequisite' => $schema->integer()->required(),
            'dependent' => $schema->integer()->required(),
        ]);

        return [
            'key' => $schema->string()
                ->description('New objective\'s key, 2-10 uppercase letters (e.g. "SALUD").')
                ->required(),
            'title' => $schema->string()->required(),
            'identity_statement' => $schema->string()
                ->description('Optional identity statement.'),
            'outcome' => $schema->string()
                ->description('What is true when it is done. Required, part of the Control 5-point plan.')
                ->required(),
            'deadline' => $schema->string()
                ->description('Y-m-d. Required, part of the Control 5-point plan.')
                ->required(),
            'metric' => $schema->object([
                'name' => $schema->string()->required(),
                'target' => $schema->number()->required(),
                'current' => $schema->number(),
            ])->description('The single metric. Required, part of the Control 5-point plan.')
                ->required(),
            'risks' => $schema->array()->items($schema->string())
                ->description('What can go wrong, at least one.')
                ->required(),
            'contingency' => $schema->string()
                ->description('What to do if a risk happens. Required, part of the Control 5-point plan.')
                ->required(),
            'control_map' => $schema->array()
                ->items($schema->object([
                    'zone' => $schema->string()->enum(ControlZone::class)->required(),
                    'text' => $schema->string()->required(),
                ]))
                ->description('Optional control map entries.'),
            'plans' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()->required(),
                    'level' => $schema->integer()->description('1-99.'),
                    'items' => $schema->array()->items($itemSchema())
                        ->description('Tasks/milestones of this plan.'),
                ]))
                ->description('Optional plans (created as drafts) under the new objective, each with its own items.'),
            'dependencies' => $schema->array()
                ->items($dependencySchema())
                ->description('"Completing prerequisite unlocks dependent" edges between items of THIS SAME call, each referenced by its 0-based position in declaration order (every plan\'s items, in order).'),
        ];
    }
}
