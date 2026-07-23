<?php

namespace App\Mcp\Tools\Views;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description("Show a project's backlog: every sprint (with its goal, date range, story point sum, and issues) plus the unassigned backlog section. Read-only, project members only — same data as the web backlog. This inspects the backlog, it does not create sprints or reassign issues.")]
class BacklogView extends Tool
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

        $sprints = $project->sprints()
            ->withSum('issues as story_points_sum', 'story_points')
            ->with(['issues' => fn ($query) => $query->orderBy('position')])
            ->orderByDesc('start_date')
            ->get();

        $backlogIssues = $project->issues()
            ->whereNull('sprint_id')
            ->orderBy('position')
            ->get();

        return Response::json([
            'project' => [
                'id' => $project->id,
                'key' => $project->key,
                'name' => $project->name,
                'owner_id' => $project->owner_id,
            ],
            'sprints' => $sprints->map(fn (Sprint $sprint): array => [
                'id' => $sprint->id,
                'name' => $sprint->name,
                'goal' => $sprint->goal,
                'start_date' => $sprint->start_date->toDateString(),
                'end_date' => $sprint->end_date->toDateString(),
                'story_points_sum' => (int) ($sprint->story_points_sum ?? 0),
                'issues' => $this->presentIssues($sprint->issues, $project),
            ])->all(),
            'backlog_issues' => $this->presentIssues($backlogIssues, $project),
        ]);
    }

    /**
     * Trims each issue down to the backlog's row shape, plus its absolute
     * URL. Attaches the already-known `$project` before touching
     * `Issue::key()` (which internally reads `$this->project`) — same
     * technique as `BoardView::presentIssue()`, avoids an N+1 per row.
     *
     * @param  Collection<int, Issue>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function presentIssues(Collection $issues, Project $project): array
    {
        return $issues->map(function (Issue $issue) use ($project): array {
            $issue->setRelation('project', $project);

            return [
                'id' => $issue->id,
                'key' => $issue->key,
                'title' => $issue->title,
                'type' => $issue->type,
                'priority' => $issue->priority,
                'story_points' => $issue->story_points,
                'sprint_id' => $issue->sprint_id,
                'url' => $this->links->issue($issue),
            ];
        })->values()->all();
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
                ->description('Key of the project whose backlog to show (e.g. "PROJ").')
                ->required(),
        ];
    }
}
