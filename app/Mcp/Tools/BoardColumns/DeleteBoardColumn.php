<?php

namespace App\Mcp\Tools\BoardColumns;

use App\Http\Requests\DestroyBoardColumnRequest;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Delete a board column. If it still has issues, a destination column from the same project is required — every issue moves there before the column is removed. The rest of the board is reindexed afterward. Owner only. Irreversible.')]
class DeleteBoardColumn extends Tool
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
            DestroyBoardColumnRequest::class,
            $request->all(),
            $user,
            ['project' => $project, 'boardColumn' => $boardColumn],
        )->validated();

        $destinationId = $validated['destination_board_column_id'] ?? null;

        $boardColumn->setRelation('project', $project);
        $url = $this->links->boardColumn($boardColumn);

        // Same strict order as `BoardColumnController::destroy()`: move
        // issues to the destination column first, then delete the column,
        // then reindex the project's remaining columns. `board_column_id`
        // is a `restrict` FK, so this manual move-then-delete transaction
        // is required to avoid leaving issues orphaned.
        DB::transaction(function () use ($project, $boardColumn, $destinationId): void {
            if ($destinationId !== null) {
                foreach ($boardColumn->issues as $issue) {
                    $issue->update([
                        'board_column_id' => $destinationId,
                        'position' => Issue::nextPositionInColumn((int) $destinationId, $issue->sprint_id),
                    ]);
                }
            }

            $boardColumn->delete();

            BoardColumn::reindexPositions($project->id);
        });

        return Response::json([
            'deleted' => true,
            'id' => $boardColumn->id,
            'url' => $url,
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
                ->description('Id of the column to delete. Must belong to the project.')
                ->required(),
            'destination_board_column_id' => $schema->integer()
                ->description('Id of the column that receives this column\'s issues. Required only when the column still has issues; must belong to the same project and be different from the column being deleted.'),
        ];
    }
}
