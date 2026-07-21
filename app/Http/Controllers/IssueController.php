<?php

namespace App\Http\Controllers;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Http\Requests\StoreIssueRequest;
use App\Http\Requests\UpdateIssueRequest;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IssueController extends Controller
{
    /**
     * Quick-create an issue from the board's per-column form. Always a
     * Task, Medium priority, unassigned, reported by the authenticated
     * user, appended to the bottom of its column.
     */
    public function store(StoreIssueRequest $request, Project $project): RedirectResponse
    {
        $data = $request->validated();

        Issue::create([
            ...$data,
            'project_id' => $project->id,
            'number' => $project->allocateNextIssueNumber(),
            'type' => IssueType::Task,
            'priority' => IssuePriority::Medium,
            'reporter_id' => $request->user()->id,
            'assignee_id' => null,
            'position' => Issue::nextPositionInColumn($data['board_column_id'], $data['sprint_id'] ?? null),
        ]);

        return back();
    }

    /**
     * Deep-link route: /projects/{project:key}/issues/{issueKey}. Renders
     * the same `projects/board` page as `BoardController::show()`, plus an
     * `issue` prop — the frontend keeps the board mounted behind the modal.
     * F5-safe: `{issueKey}` is parsed by hand (it's the `Issue::key()`
     * accessor, not a bound column) and any malformed or mismatched input
     * 404s instead of erroring.
     */
    public function show(Request $request, Project $project, string $issueKey, BoardController $boardController): Response
    {
        Gate::authorize('view', $project);

        $issue = $this->resolveIssue($project, $issueKey);

        return Inertia::render('projects/board', [
            ...$boardController->boardProps($request, $project),
            'issue' => $this->presentIssue($issue, $project),
        ]);
    }

    /**
     * Update an issue's details from the modal. Any project member may
     * edit — not owner-only. Only the fields present in the request are
     * touched (`UpdateIssueRequest` validates each with `sometimes`), so
     * the frontend can auto-save one field at a time. `position` is scoped
     * by `(board_column_id, sprint_id)`, so the issue is appended to the
     * bottom of its new scope whenever EITHER field changes — not just
     * `board_column_id` — otherwise reassigning only the sprint (e.g. the
     * backlog↔sprint move on T-10.4's backlog page, which reuses this same
     * endpoint) would drag the old position into the new scope and collide
     * with whatever issue already occupies it there.
     */
    public function update(UpdateIssueRequest $request, Project $project, Issue $issue): RedirectResponse
    {
        $data = $request->validated();

        $boardColumnChanged = array_key_exists('board_column_id', $data) && (int) $data['board_column_id'] !== $issue->board_column_id;
        $sprintChanged = array_key_exists('sprint_id', $data) && $data['sprint_id'] !== $issue->sprint_id;

        if ($boardColumnChanged || $sprintChanged) {
            $boardColumnId = $boardColumnChanged ? (int) $data['board_column_id'] : $issue->board_column_id;
            $sprintId = array_key_exists('sprint_id', $data) ? $data['sprint_id'] : $issue->sprint_id;
            $data['position'] = Issue::nextPositionInColumn($boardColumnId, $sprintId);
        }

        $issue->update($data);

        return back();
    }

    /**
     * Resolves `{issueKey}` ("PROJ-123") against the project already bound
     * from the URL. Splits on the LAST "-" (project keys never contain one
     * — see `StoreProjectRequest`'s regex), so this is safe even if a
     * future key format changes. 404s (never 500s) whenever the key is
     * malformed, the prefix doesn't match the URL's project, or no issue
     * has that number in this project.
     */
    private function resolveIssue(Project $project, string $issueKey): Issue
    {
        $lastDashPosition = strrpos($issueKey, '-');

        abort_if($lastDashPosition === false, 404);

        $prefix = substr($issueKey, 0, $lastDashPosition);
        $numberPart = substr($issueKey, $lastDashPosition + 1);

        abort_if($prefix !== $project->key, 404);
        abort_if($numberPart === '' || ! ctype_digit($numberPart), 404);

        $issue = Issue::query()
            ->where('project_id', $project->id)
            ->where('number', (int) $numberPart)
            ->with([
                'labels:id,name',
                'assignee:id,name',
                'reporter:id,name',
                'parent:id,project_id,number,title',
                'children' => fn ($query) => $query->orderBy('position'),
                'comments' => fn ($query) => $query->orderBy('created_at')->with('author:id,name'),
            ])
            ->first();

        abort_if($issue === null, 404);

        return $issue;
    }

    /**
     * Builds the full read payload for the issue modal. `setRelation()` is
     * used to attach the already-known `$project` to the issue and its
     * parent/children before touching `Issue::key()` (which internally
     * accesses `$this->project`) — this avoids an N+1 lazy-load per row,
     * since every issue in this tree always belongs to the same project.
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
        ];
    }
}
