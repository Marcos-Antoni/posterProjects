<?php

namespace App\Mcp\Tools\Objectives;

use App\Http\Resources\ObjectiveResource;
use App\Http\Resources\ObjectiveTree;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResolvesObjectiveItems;
use App\Mcp\Support\ResourceLinker;
use App\Models\Objective;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Nivel IA: read. Show one objective by key with its whole tree — the same data as the web objective screen: the Control 5-point plan, the control map (mine / influence / outside), and its plans in order with their non-retired tasks and milestones (key, kind, 2-minute version, derived state locked/available/active/done, prerequisites). Retired elements are hidden. Works for active and closed objectives. Read-only.')]
class ShowObjective extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $objective = $this->objectiveOrError($this->authenticatedUser($request), $request->get('objective_key'));

        if ($objective instanceof Response) {
            return $objective;
        }

        $objective = Objective::query()->whereKey($objective->id)->withProgress()->with(['controlPlan', 'controlMapEntries'])->firstOrFail();

        return Response::json([
            'objective' => [
                ...(new ObjectiveResource($objective))->resolve(),
                'control_plan' => ObjectiveTree::controlPlan($objective->controlPlan),
                'control_map' => $objective->controlMapEntries->map(fn ($entry): array => [
                    'zone' => $entry->zone->value,
                    'text' => $entry->text,
                    'can_become_task' => $entry->zone->canBecomeTask(),
                ])->all(),
                'plans' => collect(ObjectiveTree::plans($objective))->map(fn (array $plan): array => [
                    'id' => $plan['id'],
                    'title' => $plan['title'],
                    'state' => $plan['state'],
                    'level' => $plan['level'],
                    'items' => collect($plan['items'])->map(fn (array $item): array => [
                        'key' => $item['key'],
                        'kind' => $item['kind'],
                        'title' => $item['title'],
                        'two_minute_version' => $item['two_minute_version'],
                        'state' => $item['state'],
                        'prerequisite_keys' => $item['prerequisite_keys'],
                        'url' => $this->links->itemKey($item['key']),
                    ])->all(),
                ])->all(),
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
                ->description('Key of the objective (e.g. "SALUD").')
                ->required(),
        ];
    }
}
