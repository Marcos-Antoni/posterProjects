<?php

namespace App\Mcp\Tools\Graphs;

use App\Http\Resources\UnlockGraph;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Nivel IA: read. The global unlock graph — the same clusters, nodes and edges as the web global map (screen 9): every ACTIVE objective in manual order as a cluster (key, title, line 1–3, progress done/total/available, plans, non-retired nodes with derived state, retired items apart), every dependency between their items as an edge from prerequisite to dependent (cross = between two objectives), the current Now task and the keys of the items it unlocks. Read-only; includes the web url of the map.')]
class GlobalGraph extends Tool
{
    use ResolvesAuthenticatedUser;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        return Response::json([
            ...UnlockGraph::global($this->authenticatedUser($request)),
            'url' => route('map.index'),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
