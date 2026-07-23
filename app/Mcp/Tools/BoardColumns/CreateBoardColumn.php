<?php

namespace App\Mcp\Tools\BoardColumns;

use App\Http\Requests\StoreBoardColumnRequest;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\BoardColumn;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a new column to the end of a project\'s board. Owner only.')]
class CreateBoardColumn extends Tool
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
            StoreBoardColumnRequest::class,
            $request->all(),
            $user,
            ['project' => $project],
        )->validated();

        $column = $project->boardColumns()->create([
            'name' => $validated['name'],
            'position' => BoardColumn::nextPositionInProject($project->id),
        ]);

        $column->setRelation('project', $project);

        return Response::json([
            'board_column' => [
                'id' => $column->id,
                'name' => $column->name,
                'position' => $column->position,
                'url' => $this->links->boardColumn($column),
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
                ->description('Key of the project to add the column to (e.g. "PROJ").')
                ->required(),
            'name' => $schema->string()
                ->description('Column name.')
                ->required(),
        ];
    }
}
