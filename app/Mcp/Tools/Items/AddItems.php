<?php

namespace App\Mcp\Tools\Items;

use App\Actions\Plans\AddItemsToPlan;
use App\Actions\Support\Actor;
use App\Enums\ItemKind;
use App\Http\Requests\StoreItemRequest;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResolvesObjectiveItems;
use App\Mcp\Support\ResourceLinker;
use App\Models\Item;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: minor (applied directly and audited) — adds one or more tasks/milestones to an EXISTING plan, exactly like the web "Agregar tarea" form (a 2-minute version is required for each), plus optional dependencies between them by 0-based position in declaration order. All in one atomic operation. Returns the new items with their web URLs.')]
class AddItems extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private AddItemsToPlan $addItemsToPlan,
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

        $items = [];

        foreach ((array) $request->get('items', []) as $itemData) {
            $items[] = $this->formRequests
                ->replay(StoreItemRequest::class, (array) $itemData, $user)
                ->validated();
        }

        $dependencies = array_values(array_filter(
            (array) $request->get('dependencies', []),
            fn ($dependency): bool => is_array($dependency),
        ));

        $created = ($this->addItemsToPlan)(Actor::aiMcp($user), $plan, $items, $dependencies);

        return Response::json([
            'plan' => ['id' => $plan->id, 'title' => $plan->title, 'url' => $this->links->plan($plan)],
            'items' => array_values(array_map(fn (Item $item): array => [
                'key' => $item->key,
                'title' => $item->title,
                'url' => $this->links->item($item, $objective),
            ], $created)),
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

        return [
            'objective_key' => $schema->string()
                ->description('Key of the objective that owns the plan (e.g. "SALUD").')
                ->required(),
            'plan_id' => $schema->integer()
                ->description('Id of the EXISTING plan the items land on. Must belong to objective_key.')
                ->required(),
            'items' => $schema->array()->items($itemSchema())
                ->description('Tasks/milestones to add, at least one.')
                ->required(),
            'dependencies' => $schema->array()
                ->items($schema->object([
                    'prerequisite' => $schema->integer()->required(),
                    'dependent' => $schema->integer()->required(),
                ]))
                ->description('"Completing prerequisite unlocks dependent" edges between items of THIS SAME call, each referenced by its 0-based position in `items`.'),
        ];
    }
}
