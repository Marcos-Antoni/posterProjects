<?php

namespace App\Mcp\Tools\BoardColumns;

use App\Http\Requests\UpdateBoardColumnRequest;
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

#[Description('Rename a board column. Owner only. Use reorder-board-column to change its position instead — this only renames.')]
class UpdateBoardColumn extends Tool
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

        $boardColumn = $project->boardColumns()->whereKey($request->get('board_column_id'))->first();

        if ($boardColumn === null) {
            return Response::error("Board column not found: {$request->get('board_column_id')}");
        }

        $validated = $this->formRequests->replay(
            UpdateBoardColumnRequest::class,
            $request->all(),
            $user,
            ['project' => $project, 'boardColumn' => $boardColumn],
        )->validated();

        $boardColumn->update($validated);
        $boardColumn->setRelation('project', $project);

        return Response::json([
            'board_column' => [
                'id' => $boardColumn->id,
                'name' => $boardColumn->name,
                'position' => $boardColumn->position,
                'url' => $this->links->boardColumn($boardColumn),
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
                ->description('Key of the column\'s project (e.g. "PROJ").')
                ->required(),
            'board_column_id' => $schema->integer()
                ->description('Id of the column to rename. Must belong to the project.')
                ->required(),
            'name' => $schema->string()
                ->description('New column name.')
                ->required(),
        ];
    }
}
