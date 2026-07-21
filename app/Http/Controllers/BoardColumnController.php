<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyBoardColumnRequest;
use App\Http\Requests\ReorderBoardColumnRequest;
use App\Http\Requests\StoreBoardColumnRequest;
use App\Http\Requests\UpdateBoardColumnRequest;
use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class BoardColumnController extends Controller
{
    /**
     * Add a new column to the end of the project's board. Owner only.
     */
    public function store(StoreBoardColumnRequest $request, Project $project): RedirectResponse
    {
        $project->boardColumns()->create([
            'name' => $request->validated('name'),
            'position' => BoardColumn::nextPositionInProject($project->id),
        ]);

        return back();
    }

    /**
     * Rename a column. Owner only.
     */
    public function update(UpdateBoardColumnRequest $request, Project $project, BoardColumn $boardColumn): RedirectResponse
    {
        $boardColumn->update($request->validated());

        return back();
    }

    /**
     * Move a column to a new position on the board, renumbering the rest
     * of the project's columns around it. Owner only.
     */
    public function reorder(ReorderBoardColumnRequest $request, Project $project, BoardColumn $boardColumn): RedirectResponse
    {
        BoardColumn::reorderColumns($project->id, $boardColumn->id, (int) $request->validated('position'));

        return back();
    }

    /**
     * Delete a column. Owner only. If the column still has issues, a
     * destination column (already validated as belonging to the same
     * project and different from the one being deleted) is required —
     * every issue moves to the bottom of its `(destination, sprint_id)`
     * scope before the column is deleted. `issues.board_column_id` is an
     * FK with `restrict` behavior, so this manual move-then-delete
     * transaction is required; there's no cascade to lean on.
     */
    public function destroy(DestroyBoardColumnRequest $request, Project $project, BoardColumn $boardColumn): RedirectResponse
    {
        $destinationId = $request->validated('destination_board_column_id');

        DB::transaction(function () use ($project, $boardColumn, $destinationId): void {
            if ($destinationId !== null) {
                foreach ($boardColumn->issues as $issue) {
                    $issue->update([
                        'board_column_id' => $destinationId,
                        'position' => Issue::nextPositionInColumn((int) $destinationId, $issue->sprint_id),
                    ]);
                }
            }

            $boardColumn->delete();

            BoardColumn::reindexPositions($project->id);
        });

        return back();
    }
}
