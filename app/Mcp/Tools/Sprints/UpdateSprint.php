<?php

namespace App\Mcp\Tools\Sprints;

use App\Http\Requests\UpdateSprintRequest;
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

#[Description('Rename or reschedule an existing sprint. Owner only.')]
class UpdateSprint extends Tool
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

        $sprint = $project->sprints()->whereKey($request->get('sprint_id'))->first();

        if ($sprint === null) {
            return Response::error("Sprint not found: {$request->get('sprint_id')}");
        }

        $validated = $this->formRequests->replay(
            UpdateSprintRequest::class,
            $request->all(),
            $user,
            ['project' => $project, 'sprint' => $sprint],
        )->validated();

        $sprint->update($validated);

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
                ->description('Key of the sprint\'s project (e.g. "PROJ").')
                ->required(),
            'sprint_id' => $schema->integer()
                ->description('Id of the sprint to update. Must belong to the project.')
                ->required(),
            'name' => $schema->string()
                ->description('New sprint name.')
                ->required(),
            'goal' => $schema->string()
                ->description('New sprint goal. Omit to clear it.'),
            'start_date' => $schema->string()
                ->description('New start date (e.g. "2025-01-01").')
                ->required(),
            'end_date' => $schema->string()
                ->description('New end date. Must be on or after the start date.')
                ->required(),
        ];
    }
}
