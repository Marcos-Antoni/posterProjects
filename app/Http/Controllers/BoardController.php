<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    /**
     * Display a project's Trello-style board: columns ordered by position,
     * each with the issues visible under the currently selected sprint
     * filter (or the backlog). Read-only — members only.
     */
    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/board', $this->boardProps($request, $project));
    }

    /**
     * Assembles the board's shared props (project header, columns, sprints,
     * project members). Shared by this controller's own route and by
     * `IssueController::show()` (the issue deep-link route), so the modal
     * always renders behind the exact same board state, including the
     * caller's `?sprint=` filter.
     *
     * Callers are responsible for their own authorization check — this
     * method does not gate access on its own.
     *
     * @return array<string, mixed>
     */
    public function boardProps(Request $request, Project $project): array
    {
        $sprints = $project->sprints()->orderByDesc('start_date')->get();
        $activeSprint = $this->resolveActiveSprint($sprints);
        $selectedSprintId = $this->resolveSelectedSprintId($request, $activeSprint);

        $columns = $project->boardColumns()
            ->with(['issues' => function ($query) use ($selectedSprintId) {
                $query->when(
                    $selectedSprintId === null,
                    fn ($q) => $q->whereNull('sprint_id'),
                    fn ($q) => $q->where('sprint_id', $selectedSprintId),
                )->with(['labels:id,name', 'assignee:id,name']);
            }])
            ->get();

        $members = $project->members()->orderBy('users.name')->get(['users.id', 'users.name']);
        $labels = $project->labels()->orderBy('name')->get(['id', 'name']);

        return [
            'project' => [
                'id' => $project->id,
                'key' => $project->key,
                'name' => $project->name,
                'owner_id' => $project->owner_id,
            ],
            'columns' => $columns,
            'sprints' => $sprints,
            'selectedSprintId' => $selectedSprintId,
            'activeSprintId' => $activeSprint?->id,
            'members' => $members,
            'labels' => $labels,
        ];
    }

    /**
     * The "active" sprint is the one whose date range contains today.
     * Falls back to the backlog (`null`) when none is active.
     *
     * @param  Collection<int, Sprint>  $sprints
     */
    private function resolveActiveSprint($sprints): ?Sprint
    {
        $today = today();

        return $sprints->first(
            fn (Sprint $sprint): bool => $sprint->start_date->lte($today) && $sprint->end_date->gte($today),
        );
    }

    /**
     * The board defaults to the active sprint (or the backlog if none is
     * active), but an explicit `?sprint=` query param always wins — an
     * empty value means "backlog", any other value is a sprint id.
     */
    private function resolveSelectedSprintId(Request $request, ?Sprint $activeSprint): ?int
    {
        if (! $request->has('sprint')) {
            return $activeSprint?->id;
        }

        $sprintParam = $request->query('sprint');

        return $sprintParam === '' || $sprintParam === null ? null : (int) $sprintParam;
    }
}
