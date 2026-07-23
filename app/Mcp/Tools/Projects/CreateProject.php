<?php

namespace App\Mcp\Tools\Projects;

use App\Http\Requests\StoreProjectRequest;
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

#[Description('Create a new project owned by the authenticated user. The default board columns (To Do, In Progress, Done) are created automatically and the owner is attached as a member.')]
class CreateProject extends Tool
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
        $user = $this->authenticatedUser($request);

        $validated = $this->formRequests
            ->replay(StoreProjectRequest::class, $request->all(), $user)
            ->validated();

        $project = Project::createWithDefaultColumns([
            ...$validated,
            'owner_id' => $user->id,
        ]);

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
            'key' => $schema->string()
                ->description('Unique project key: uppercase letters and digits, starting with a letter, max 10 chars (e.g. "PROJ").')
                ->required(),
            'name' => $schema->string()
                ->description('Human-readable project name.')
                ->required(),
            'description' => $schema->string()
                ->description('Optional free-text description of the project.'),
        ];
    }
}
