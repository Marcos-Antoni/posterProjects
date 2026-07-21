<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    /**
     * Display the authenticated user's global calendar: every issue with a
     * due date, across every project they are a member of, for the given
     * month (`?month=YYYY-MM`, defaults to the current month). Archived
     * (soft-deleted) projects are excluded automatically — `$user->projects()`
     * is a `BelongsToMany` to `Project`, which carries the `SoftDeletes`
     * global scope, so a trashed project's id never appears in the
     * membership query below.
     */
    public function index(Request $request): Response
    {
        $month = $this->resolveMonth($request);

        $projectIds = $request->user()->projects()->pluck('projects.id');

        $issues = Issue::query()
            ->whereNotNull('due_date')
            ->whereIn('project_id', $projectIds)
            ->whereBetween('due_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->with('project:id,key')
            ->orderBy('due_date')
            ->get();

        return Inertia::render('calendar', [
            'month' => $month->format('Y-m'),
            'issues' => $issues->map(fn (Issue $issue): array => [
                'id' => $issue->id,
                'key' => $issue->key,
                'title' => $issue->title,
                'type' => $issue->type,
                'priority' => $issue->priority,
                'due_date' => $issue->due_date?->toDateString(),
                'project' => [
                    'key' => $issue->project->key,
                ],
            ])->values(),
        ]);
    }

    /**
     * Parses `?month=YYYY-MM` into the first day of that month. Falls back
     * to the current month on a missing or malformed value instead of
     * erroring — same defensive posture as
     * `BoardController::resolveSelectedSprintId()`.
     */
    private function resolveMonth(Request $request): Carbon
    {
        $monthParam = $request->query('month');

        if (is_string($monthParam) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam) === 1) {
            return Carbon::createFromFormat('Y-m-d', $monthParam.'-01')->startOfMonth();
        }

        return Carbon::now()->startOfMonth();
    }
}
