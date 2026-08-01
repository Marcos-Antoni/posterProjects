<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListProjectIssuesRequest;
use App\Http\Resources\IssueDetailResource;
use App\Http\Resources\IssueResource;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IssueController extends Controller
{
    /**
     * List `{project}`'s issues, board-ordered and paginated.
     *
     * `ListProjectIssuesRequest` validates before this method body runs
     * (query-shape `422`), so project resolution (`404`) always happens
     * second — matching the design's fail order: shape before existence.
     *
     * Ordering is `board_columns.position, issues.position, issues.id` —
     * the `issues.id` tiebreaker is mandatory because `position` is unique
     * only within a `(board_column_id, sprint_id)` scope, so two issues in
     * one column from different sprints may legitimately share a
     * `position`. `->select('issues.*')` is mandatory too: `board_columns`
     * and `issues` share the `id`/`project_id`/`position` column names, so
     * without it Eloquent hydrates the last value it sees for each —
     * silently reporting the column's id/position instead of the issue's
     * own.
     *
     * `setRelation('project', $project)` on every row avoids an N+1: the
     * `key` accessor reads `$this->project->key`, and every row here
     * provably belongs to the already-resolved `$project`.
     */
    public function index(ListProjectIssuesRequest $request, string $project): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $resolvedProject = $this->resolveProject($request, $project);

        $sprintParam = $validated['sprint'] ?? null;

        if ($sprintParam !== null && $sprintParam !== 'backlog') {
            $sprintExists = $resolvedProject->sprints()->whereKey((int) $sprintParam)->exists();

            abort_if(! $sprintExists, 404);
        }

        $issues = $resolvedProject->issues()
            ->join('board_columns', 'board_columns.id', '=', 'issues.board_column_id')
            ->select('issues.*')
            ->when($sprintParam === 'backlog', fn ($query) => $query->whereNull('issues.sprint_id'))
            ->when($sprintParam !== null && $sprintParam !== 'backlog', fn ($query) => $query->where('issues.sprint_id', (int) $sprintParam))
            ->orderBy('board_columns.position')
            ->orderBy('issues.position')
            ->orderBy('issues.id')
            ->with([
                'assignee:id,name',
                'labels' => fn ($query) => $query->orderBy('labels.name')->orderBy('labels.id'),
            ])
            ->paginate($validated['per_page'] ?? 50)
            ->withQueryString();

        $issues->getCollection()->each(fn (Issue $issue) => $issue->setRelation('project', $resolvedProject));

        return IssueResource::collection($issues);
    }

    /**
     * Resolve a single issue by its human key (`PROJ-123`) inside
     * `{project}`, embedding every relation the detail modal needs.
     *
     * `{issue}` never uses route-model binding: `key` is a computed
     * accessor (`app/Models/Issue.php:87-92`), not a column, so
     * `Issue::resolveByKey()` resolves it in code instead. It returns
     * `null` — never throws — for every malformed or cross-project form of
     * the key, all seven of which converge on the same `404` here
     * (design D-1).
     *
     * `setRelation('project', $project)` on the issue, its parent, and
     * each child avoids an N+1: every issue in one hierarchy always
     * belongs to the same already-resolved `$project`.
     */
    public function show(Request $request, string $project, string $issue): IssueDetailResource
    {
        $resolvedProject = $this->resolveProject($request, $project);

        $resolvedIssue = Issue::resolveByKey($resolvedProject, $issue)?->load([
            'labels' => fn ($query) => $query->orderBy('labels.name')->orderBy('labels.id'),
            'assignee:id,name',
            'reporter:id,name',
            'parent:id,project_id,number,title',
            'children' => fn ($query) => $query->orderBy('issues.position')->orderBy('issues.id'),
            'comments' => fn ($query) => $query->orderBy('comments.created_at')->orderBy('comments.id')->with('author:id,name'),
        ]);

        abort_if($resolvedIssue === null, 404);

        $resolvedIssue->setRelation('project', $resolvedProject);
        $resolvedIssue->parent?->setRelation('project', $resolvedProject);
        $resolvedIssue->children->each(fn (Issue $child) => $child->setRelation('project', $resolvedProject));

        return new IssueDetailResource($resolvedIssue);
    }

    /**
     * Resolve `{project}` (a `key`, not an id) among the projects the
     * requesting user is a member of. Deliberately omits `withTrashed()`
     * (unlike `ProjectController::show`): an archived project's issues
     * 404, matching the web behaviour this API exposes.
     */
    private function resolveProject(Request $request, string $projectKey): Project
    {
        return $request->user()->projects()->where('projects.key', $projectKey)->firstOrFail();
    }
}
