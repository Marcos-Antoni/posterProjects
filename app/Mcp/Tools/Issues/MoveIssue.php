<?php

namespace App\Mcp\Tools\Issues;

use App\Http\Requests\MoveIssueRequest;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description("Drag-and-drop an issue to a board column and position (0-indexed within that column's currently visible, sprint-scoped list). Never changes the issue's sprint. This reorders/relocates on the board; use update-issue to edit an issue's fields instead.")]
class MoveIssue extends Tool
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

        $issue = Issue::resolveByKey($project, (string) $request->get('issue_key'));

        if ($issue === null) {
            return Response::error("Issue not found: {$request->get('issue_key')}");
        }

        $validated = $this->formRequests->replay(
            MoveIssueRequest::class,
            $request->all(),
            $user,
            ['project' => $project],
        )->validated();

        // Same transaction as `IssueMoveController::move()`: when the
        // column changes, the origin scope is reindexed first to close the
        // gap, then the destination scope is reindexed around the insert.
        DB::transaction(function () use ($issue, $validated): void {
            $sprintId = $issue->sprint_id;
            $originColumnId = $issue->board_column_id;
            $destinationColumnId = (int) $validated['board_column_id'];

            if ($originColumnId !== $destinationColumnId) {
                Issue::closeGapInScope($originColumnId, $sprintId, $issue->id);
            }

            Issue::reorderScope($destinationColumnId, $sprintId, $issue, (int) $validated['position']);
        });

        $issue->setRelation('project', $project);

        return Response::json([
            'issue' => [
                'id' => $issue->id,
                'key' => $issue->key,
                'board_column_id' => $issue->board_column_id,
                'sprint_id' => $issue->sprint_id,
                'position' => $issue->position,
                'url' => $this->links->issue($issue),
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
                ->description('Key of the issue\'s project (e.g. "PROJ").')
                ->required(),
            'issue_key' => $schema->string()
                ->description('Key of the issue to move (e.g. "PROJ-123").')
                ->required(),
            'board_column_id' => $schema->integer()
                ->description('Id of the destination board column. Must belong to the project.')
                ->required(),
            'position' => $schema->integer()
                ->description('0-indexed target position within the destination column\'s currently visible (sprint-scoped) list.')
                ->required(),
        ];
    }
}
