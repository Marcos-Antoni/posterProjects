<?php

namespace App\Mcp\Tools\Graphs;

use App\Http\Resources\UnlockGraph;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResolvesObjectiveItems;
use App\Mcp\Support\ResourceLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Nivel IA: read. The unlock graph of one objective by key — the same nodes and edges as the web map of that objective (screen 8): every non-retired task and milestone as a node (key, kind, title, derived state locked/available/active/done, plan, 2-minute version), grouped by plan in order; every dependency touching them as an edge from prerequisite to dependent ("completing A unlocks B", external = crosses objectives); the items of other objectives it connects to as stubs; retired items apart with their reason; the Now task; and the next milestone with the stations left. Works for active and closed objectives. Read-only; includes the web url of the map.')]
class ObjectiveGraph extends Tool
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

        $graph = UnlockGraph::forObjective($objective);
        $graph['objective']['url'] = $this->links->objective($objective);

        return Response::json([
            ...$graph,
            'url' => route('map.show', $objective->key),
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
