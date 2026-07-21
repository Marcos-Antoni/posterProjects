<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLabelRequest;
use App\Http\Requests\UpdateLabelRequest;
use App\Models\Label;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LabelController extends Controller
{
    /**
     * Owner-only label management screen: every project label with how
     * many issues currently wear it (`withCount('issues')`, feeds T-10.6's
     * UI). Any member may view the list — only renaming and deleting are
     * gated to the owner (see `LabelPolicy`).
     */
    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/labels', [
            'project' => [
                'key' => $project->key,
                'name' => $project->name,
                'owner_id' => $project->owner_id,
            ],
            'labels' => $project->labels()->withCount('issues')->orderBy('name')->get(),
        ]);
    }

    /**
     * Create a new label for the project. Any member may create one — not
     * gated by `LabelPolicy`, which only covers `update`/`delete`.
     */
    public function store(StoreLabelRequest $request, Project $project): RedirectResponse
    {
        $project->labels()->create($request->validated());

        return back();
    }

    /**
     * Rename a label. Owner only (`LabelPolicy::update()`).
     */
    public function update(UpdateLabelRequest $request, Project $project, Label $label): RedirectResponse
    {
        $label->update($request->validated());

        return back();
    }

    /**
     * Delete a label. Owner only (`LabelPolicy::delete()`).
     * `issue_label.label_id` has `cascadeOnDelete()` (T-8's cascade
     * migration), so the DELETE itself removes every attachment of this
     * label at the database level — no manual detach loop needed, verified
     * by a dedicated test.
     */
    public function destroy(Project $project, Label $label): RedirectResponse
    {
        Gate::authorize('delete', $label);

        $label->delete();

        return back();
    }
}
