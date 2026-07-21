<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveIssueRequest;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class IssueMoveController extends Controller
{
    /**
     * Drag-and-drop endpoint: moves `$issue` to `board_column_id` at
     * `position` (0-indexed within that column's currently visible,
     * sprint-scoped list). `sprint_id` is never touched — the board's
     * sprint filter is a client-side concern, not something a drag can
     * change. Transactional: when the column changes, the origin scope is
     * reindexed first to close the gap, then the destination scope is
     * reindexed around the inserted issue.
     */
    public function move(MoveIssueRequest $request, Project $project, Issue $issue): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($issue, $data): void {
            $sprintId = $issue->sprint_id;
            $originColumnId = $issue->board_column_id;
            $destinationColumnId = (int) $data['board_column_id'];

            if ($originColumnId !== $destinationColumnId) {
                Issue::closeGapInScope($originColumnId, $sprintId, $issue->id);
            }

            Issue::reorderScope($destinationColumnId, $sprintId, $issue, (int) $data['position']);
        });

        return back();
    }
}
