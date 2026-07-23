<?php

namespace App\Mcp\Tools\Issues;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Http\Requests\StoreIssueRequest;
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

#[Description('Quick-create an issue in a project column, the same way the board\'s per-column "+" form does. Always created as a Task, Medium priority, unassigned, reported by the caller, appended to the bottom of the column. This only creates — use update-issue to change type, priority, or other fields afterward.')]
class CreateIssue extends Tool
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
            StoreIssueRequest::class,
            $request->all(),
            $user,
            ['project' => $project],
        )->validated();

        $issue = Issue::create([
            ...$validated,
            'project_id' => $project->id,
            'number' => $project->allocateNextIssueNumber(),
            'type' => IssueType::Task,
            'priority' => IssuePriority::Medium,
            'reporter_id' => $user->id,
            'assignee_id' => null,
            'position' => Issue::nextPositionInColumn($validated['board_column_id'], $validated['sprint_id'] ?? null),
        ]);

        $issue->setRelation('project', $project);

        return Response::json([
            'issue' => [
                'id' => $issue->id,
                'key' => $issue->key,
                'title' => $issue->title,
                'type' => $issue->type,
                'priority' => $issue->priority,
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
                ->description('Key of the project to create the issue in (e.g. "PROJ").')
                ->required(),
            'title' => $schema->string()
                ->description('Title of the new issue.')
                ->required(),
            'board_column_id' => $schema->integer()
                ->description('Id of the board column to create the issue in. Must belong to the project.')
                ->required(),
            'sprint_id' => $schema->integer()
                ->description('Id of the sprint to add the issue to. Omit or pass null for the backlog.')
                ->nullable(),
            'parent_id' => $schema->integer()
                ->description('Id of the parent issue, to create this as a sub-task. The parent must not itself be a sub-task.')
                ->nullable(),
        ];
    }
}
