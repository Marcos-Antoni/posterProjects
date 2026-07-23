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

#[Description('PERMANENTLY delete an archived project and, by cascade, all of its board columns, sprints, labels, memberships, issues, comments and label assignments. Irreversible — the project must already be in the trash (archive-project first). Owner only.')]
class ForceDeleteProject extends Tool
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

        Gate::forUser($request->user())->authorize('forceDelete', $project);

        $project->forceDelete();

        return Response::json([
            'deleted' => true,
            'key' => $project->key,
            'url' => $this->links->project($project),
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
                ->description('Key of the trashed project to delete permanently (e.g. "PROJ").')
                ->required(),
        ];
    }
}
