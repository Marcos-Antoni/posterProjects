<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSprintRequest;
use App\Http\Requests\UpdateSprintRequest;
use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class SprintController extends Controller
{
    /**
     * Create a sprint for the project. Owner only.
     */
    public function store(StoreSprintRequest $request, Project $project): RedirectResponse
    {
        $project->sprints()->create($request->validated());

        return back();
    }

    /**
     * Rename/reschedule a sprint. Owner only.
     */
    public function update(UpdateSprintRequest $request, Project $project, Sprint $sprint): RedirectResponse
    {
        $sprint->update($request->validated());

        return back();
    }

    /**
     * Delete a sprint. Owner only. No manual transaction needed —
     * `issues.sprint_id` has an FK `nullOnDelete()` (added by T-8's
     * cascade migration), so the DELETE itself atomically returns every
     * issue in this sprint to the backlog (`sprint_id = null`). Issues are
     * never deleted.
     */
    public function destroy(Project $project, Sprint $sprint): RedirectResponse
    {
        Gate::authorize('delete', $sprint);

        $sprint->delete();

        return back();
    }
}
