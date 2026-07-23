<?php

namespace App\Mcp\Tools\Sprints;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Delete a sprint. Every issue currently in it is returned to the backlog (sprint_id cleared) — issues themselves are never deleted. Irreversible. Owner only.')]
class DeleteSprint extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $project = Project::query()->where('key', $request->get('project_key'))->first();

        if ($project === null) {
            return Response::error("Project not found: {$request->get('project_key')}");
        }

        $sprint = $project->sprints()->whereKey($request->get('sprint_id'))->first();

        if ($sprint === null) {
            return Response::error("Sprint not found: {$request->get('sprint_id')}");
        }

        Gate::forUser($user)->authorize('delete', $sprint);

        $url = $this->links->sprint($sprint);
        $sprint->delete();

        return Response::json([
            'deleted' => true,
            'id' => $sprint->id,
            'url' => $url,
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
            'project_key' => $schema->string()
                ->description('Key of the sprint\'s project (e.g. "PROJ").')
                ->required(),
            'sprint_id' => $schema->integer()
                ->description('Id of the sprint to delete. Must belong to the project.')
                ->required(),
        ];
    }
}
