<?php

namespace App\Mcp\Tools\Objectives;

use App\Http\Resources\ObjectiveResource;
use App\Mcp\Support\ResolvesAuthenticatedUser;
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
#[Description('Nivel IA: read. List Marco\'s ACTIVE objectives in his manual order — the same list as the web Objectives screen — each with its 5-point plan summary (outcome, deadline, single metric) and progress over non-retired items. Closed and retired objectives are not listed. Read-only; use show-objective for one objective\'s tree.')]
class ListObjectives extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $objectives = $user->objectives()->active()->withProgress()->with('controlPlan')->get();

        return Response::json([
            'objectives' => $objectives->map(fn (Objective $objective): array => [
                ...(new ObjectiveResource($objective))->resolve(),
                'url' => $this->links->objective($objective),
            ])->all(),
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
