<?php

namespace App\Mcp\Tools\Sprints;

use App\Http\Requests\StoreSprintRequest;
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

#[Description('Create a new sprint for a project. Owner only.')]
class CreateSprint extends Tool
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

        $project = Project::query()->where('key', $request->get('project_key'))->first();

        if ($project === null) {
            return Response::error("Project not found: {$request->get('project_key')}");
        }

        $validated = $this->formRequests->replay(
            StoreSprintRequest::class,
            $request->all(),
            $user,
            ['project' => $project],
        )->validated();

        $sprint = $project->sprints()->create($validated);

        return Response::json([
            'sprint' => [
                'id' => $sprint->id,
                'name' => $sprint->name,
                'goal' => $sprint->goal,
                'start_date' => $sprint->start_date->toDateString(),
                'end_date' => $sprint->end_date->toDateString(),
                'url' => $this->links->sprint($sprint),
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
                ->description('Key of the project to create the sprint in (e.g. "PROJ").')
                ->required(),
            'name' => $schema->string()
                ->description('Sprint name.')
                ->required(),
            'goal' => $schema->string()
                ->description('Optional sprint goal.'),
            'start_date' => $schema->string()
                ->description('Sprint start date (e.g. "2025-01-01").')
                ->required(),
            'end_date' => $schema->string()
                ->description('Sprint end date. Must be on or after the start date.')
                ->required(),
        ];
    }
}
