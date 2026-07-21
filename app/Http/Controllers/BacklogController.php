<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BacklogController extends Controller
{
    /**
     * Display a project's backlog: every sprint (collapsible on the
     * frontend, with its goal/date range/story-point sum) plus the
     * Backlog section itself (issues with no sprint). Read-only — this
     * controller has no `store`/`update`/`destroy`. Reassigning an issue
     * between the backlog and a sprint reuses `IssueController::update`
     * (`PATCH projects.issues.update`, sending only `sprint_id`) instead
     * of a sibling endpoint here, per T-10's "Ajustes post-T-9". Any
     * project member may view; sprint management (create/edit/delete) is
     * gated client-side by `isOwner`, the same pattern the board uses for
     * column management.
     */
    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $sprints = $project->sprints()
            ->withSum('issues as story_points_sum', 'story_points')
            ->with(['issues' => fn ($query) => $query->orderBy('position')])
            ->orderByDesc('start_date')
            ->get();

        $backlogIssues = $project->issues()
            ->whereNull('sprint_id')
            ->orderBy('position')
            ->get();

        return Inertia::render('projects/backlog', [
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
            ])->values(),
            'backlogIssues' => $this->presentIssues($backlogIssues, $project),
        ]);
    }

    /**
     * Trims each issue down to what the backlog's rows need, and attaches
     * the already-known `$project` before touching `Issue::key()` (which
     * internally reads `$this->project`) — avoids an N+1 per row, the same
     * technique `IssueController::presentIssue()` uses.
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
            ];
        })->values()->all();
    }
}
