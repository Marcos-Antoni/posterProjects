<?php

namespace App\Mcp\Tools\Issues;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Http\Requests\UpdateIssueRequest;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Edit an existing issue\'s fields — title, description, type, priority, story points, due date, assignee, sprint, column, or parent. Only the fields passed are changed. This edits fields; use move-issue for drag-and-drop reordering within or across board columns.')]
class UpdateIssue extends Tool
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

        $data = $this->formRequests->replay(
            UpdateIssueRequest::class,
            $request->all(),
            $user,
            ['project' => $project, 'issue' => $issue],
        )->validated();

        // Same reindexing rule as `IssueController::update()`: the issue is
        // appended to the bottom of its new (board_column_id, sprint_id)
        // scope whenever EITHER field changes, not just the column.
        $boardColumnChanged = array_key_exists('board_column_id', $data) && (int) $data['board_column_id'] !== $issue->board_column_id;
        $sprintChanged = array_key_exists('sprint_id', $data) && $data['sprint_id'] !== $issue->sprint_id;

        if ($boardColumnChanged || $sprintChanged) {
            $boardColumnId = $boardColumnChanged ? (int) $data['board_column_id'] : $issue->board_column_id;
            $sprintId = array_key_exists('sprint_id', $data) ? $data['sprint_id'] : $issue->sprint_id;
            $data['position'] = Issue::nextPositionInColumn($boardColumnId, $sprintId);
        }

        $issue->update($data);
        $issue->setRelation('project', $project);

        return Response::json([
            'issue' => [
                'id' => $issue->id,
                'key' => $issue->key,
                'title' => $issue->title,
                'description' => $issue->description,
                'type' => $issue->type,
                'priority' => $issue->priority,
                'story_points' => $issue->story_points,
                'due_date' => $issue->due_date?->toDateString(),
                'assignee_id' => $issue->assignee_id,
                'board_column_id' => $issue->board_column_id,
                'sprint_id' => $issue->sprint_id,
                'parent_id' => $issue->parent_id,
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
                ->description('Key of the issue to update (e.g. "PROJ-123").')
                ->required(),
            'title' => $schema->string()
                ->description('New title.'),
            'description' => $schema->string()
                ->description('New description. Pass null to clear it.')
                ->nullable(),
            'type' => $schema->string()
                ->description('New issue type.')
                ->enum(IssueType::class),
            'priority' => $schema->integer()
                ->description('New priority (1=Highest, 2=High, 3=Medium, 4=Low, 5=Lowest).')
                ->enum(IssuePriority::class),
            'story_points' => $schema->integer()
                ->description('New story point estimate. Pass null to clear it.')
                ->nullable(),
            'due_date' => $schema->string()
                ->description('New due date. Pass null to clear it.')
                ->nullable(),
            'assignee_id' => $schema->integer()
                ->description('User id to assign the issue to. Must be a project member. Pass null to unassign.')
                ->nullable(),
            'sprint_id' => $schema->integer()
                ->description('Id of the sprint to move the issue to. Pass null to move it back to the backlog.')
                ->nullable(),
            'board_column_id' => $schema->integer()
                ->description('Id of the board column to move the issue to.'),
            'parent_id' => $schema->integer()
                ->description('Id of the parent issue, to turn this into a sub-task. Pass null to remove its parent.')
                ->nullable(),
        ];
    }
}
