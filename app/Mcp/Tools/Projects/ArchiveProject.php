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

#[Description('Archive (soft delete) an active project: it moves to the trash and disappears from the index and sidebar for every member, keeping all its data. Owner only. Reversible with restore-project — for permanent deletion use force-delete-project.')]
class ArchiveProject extends Tool
{
    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $project = Project::query()->where('key', $request->get('project_key'))->first();

        if ($project === null) {
            return Response::error("Project not found: {$request->get('project_key')}");
        }

        Gate::forUser($request->user())->authorize('archive', $project);

        $project->delete();

        return Response::json([
            'project' => [
                'id' => $project->id,
                'key' => $project->key,
                'name' => $project->name,
                'archived' => true,
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
                ->description('Key of the active project to archive (e.g. "PROJ").')
                ->required(),
        ];
    }
}
