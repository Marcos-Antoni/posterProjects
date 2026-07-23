<?php

namespace App\Mcp\Tools\Projects;

use App\Http\Requests\UpdateProjectRequest;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update an active project\'s key, name, or description. Owner only. This edits project metadata — it does not move issues nor archive anything.')]
class UpdateProject extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $project = Project::query()->where('key', $request->get('project_key'))->first();

        if ($project === null) {
            return Response::error("Project not found: {$request->get('project_key')}");
        }

        $validated = $this->formRequests->replay(
            UpdateProjectRequest::class,
            $request->all(),
            $this->authenticatedUser($request),
            ['project' => $project],
        )->validated();

        $project->update($validated);

        return Response::json([
            'project' => [
                'id' => $project->id,
                'key' => $project->key,
                'name' => $project->name,
                'description' => $project->description,
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
                ->description('Key of the project to update (its current key, e.g. "PROJ").')
                ->required(),
            'key' => $schema->string()
                ->description('New project key: uppercase letters and digits, starting with a letter, max 10 chars. Pass the current key to keep it.')
                ->required(),
            'name' => $schema->string()
                ->description('New project name.')
                ->required(),
            'description' => $schema->string()
                ->description('New free-text description. Omit to clear it.'),
        ];
    }
}
