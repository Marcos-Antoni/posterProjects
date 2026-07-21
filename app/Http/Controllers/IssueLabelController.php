<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIssueLabelRequest;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class IssueLabelController extends Controller
{
    /**
     * Attach an existing project label to an issue. Any project member may
     * do this — the owner-only management screen ships in T-10.
     * Idempotent via `syncWithoutDetaching()`: attaching an already-attached
     * label is a silent no-op rather than a 422 or a raw unique-constraint
     * violation, since the picker UI can legitimately double-submit (e.g.
     * a fast double click).
     */
    public function store(StoreIssueLabelRequest $request, Project $project, Issue $issue): RedirectResponse
    {
        $issue->labels()->syncWithoutDetaching([$request->validated('label_id')]);

        return back();
    }

    /**
     * Detach a label from an issue. `{label}` is scoped to
     * `$issue->labels()` via the route's `scopeBindings()`, so a label
     * belonging to this project but not currently attached to this issue
     * 404s instead of silently no-op-ing.
     */
    public function destroy(Project $project, Issue $issue, Label $label): RedirectResponse
    {
        Gate::authorize('view', $project);

        $issue->labels()->detach($label->id);

        return back();
    }
}
