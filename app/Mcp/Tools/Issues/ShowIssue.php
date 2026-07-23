<?php

namespace App\Mcp\Tools\Issues;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Show a single issue with its full detail: description, labels, assignee, reporter, parent/children, and comments — the same data as the issue modal on the web board. Read-only, project members only. This inspects one issue; use board-view to see a whole column at once.')]
class ShowIssue extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

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

        Gate::forUser($user)->authorize('view', $project);

        $issue = Issue::resolveByKey($project, (string) $request->get('issue_key'))?->load([
            'labels:id,name',
            'assignee:id,name',
            'reporter:id,name',
            'parent:id,project_id,number,title',
            'children' => fn ($query) => $query->orderBy('position'),
            'comments' => fn ($query) => $query->orderBy('created_at')->with('author:id,name'),
        ]);

        if ($issue === null) {
            return Response::error("Issue not found: {$request->get('issue_key')}");
        }

        return Response::json([
            'issue' => $this->presentIssue($issue, $project),
        ]);
    }

    /**
     * Builds the same read payload as `IssueController::presentIssue()`,
     * plus the issue's absolute URL. `setRelation()` attaches the
     * already-known `$project` to the issue and its parent/children before
     * touching `Issue::key()` (which internally reads `$this->project`) —
     * avoids an N+1 lazy-load per row.
     *
     * @return array<string, mixed>
     */
    private function presentIssue(Issue $issue, Project $project): array
    {
        $issue->setRelation('project', $project);
        $issue->parent?->setRelation('project', $project);
        $issue->children->each(fn (Issue $child) => $child->setRelation('project', $project));

        return [
            'id' => $issue->id,
            'key' => $issue->key,
            'number' => $issue->number,
            'title' => $issue->title,
            'description' => $issue->description,
            'type' => $issue->type,
            'priority' => $issue->priority,
            'story_points' => $issue->story_points,
            'due_date' => $issue->due_date?->toDateString(),
            'board_column_id' => $issue->board_column_id,
            'sprint_id' => $issue->sprint_id,
            'parent_id' => $issue->parent_id,
            'labels' => $issue->labels->map(fn ($label) => [
                'id' => $label->id,
                'name' => $label->name,
            ])->all(),
            'assignee' => $issue->assignee === null ? null : [
                'id' => $issue->assignee->id,
                'name' => $issue->assignee->name,
            ],
            'reporter' => [
                'id' => $issue->reporter->id,
                'name' => $issue->reporter->name,
            ],
            'parent' => $issue->parent === null ? null : [
                'id' => $issue->parent->id,
                'key' => $issue->parent->key,
                'title' => $issue->parent->title,
            ],
            'children' => $issue->children->map(fn (Issue $child) => [
                'id' => $child->id,
                'key' => $child->key,
                'title' => $child->title,
                'type' => $child->type,
                'board_column_id' => $child->board_column_id,
            ])->all(),
            'comments' => $issue->comments->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
                'author' => [
                    'id' => $comment->author->id,
                    'name' => $comment->author->name,
                ],
            ])->all(),
            'url' => $this->links->issue($issue),
        ];
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
                ->description('Key of the issue to show (e.g. "PROJ-123").')
                ->required(),
        ];
    }
}
