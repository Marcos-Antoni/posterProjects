<?php

namespace App\Mcp\Tools\Projects;

use App\Mcp\Support\ResourceLinker;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Restore an archived project from the trash, making it active again for every member. Owner only.')]
class RestoreProject extends Tool
{
    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $project = Project::onlyTrashed()->where('key', $request->get('project_key'))->first();

        if ($project === null) {
            return Response::error("Trashed project not found: {$request->get('project_key')}");
        }

        Gate::forUser($request->user())->authorize('restore', $project);

        $project->restore();

        return Response::json([
            'project' => [
                'id' => $project->id,
                'key' => $project->key,
                'name' => $project->name,
                'archived' => false,
                'url' => $this->links->project($project),
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
            'project_key' => $schema->string()
                ->description('Key of the trashed project to restore (e.g. "PROJ").')
                ->required(),
        ];
    }
}
