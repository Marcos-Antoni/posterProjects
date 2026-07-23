<?php

namespace App\Mcp\Tools\BoardColumns;

use App\Http\Requests\ReorderBoardColumnRequest;
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

#[Description('Move a board column to a new position on the board, renumbering the rest of the project\'s columns around it. Owner only.')]
class ReorderBoardColumn extends Tool
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
            ReorderBoardColumnRequest::class,
            $request->all(),
            $user,
            ['project' => $project, 'boardColumn' => $boardColumn],
        )->validated();

        BoardColumn::reorderColumns($project->id, $boardColumn->id, (int) $validated['position']);

        $boardColumn->setRelation('project', $project);

        $columns = $project->boardColumns()->orderBy('position')->get();

        return Response::json([
            'url' => $this->links->boardColumn($boardColumn),
            'board_columns' => $columns->map(fn (BoardColumn $column) => [
                'id' => $column->id,
                'name' => $column->name,
                'position' => $column->position,
            ])->all(),
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
                ->description('Id of the column to move. Must belong to the project.')
                ->required(),
            'position' => $schema->integer()
                ->description('New 0-indexed target position on the board. Clamped to the number of columns.')
                ->required(),
        ];
    }
}
