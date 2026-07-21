<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Display the projects the authenticated user is a member of, each
     * annotated with its issue count.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $projects = $request->user()
            ->projects()
            ->withCount('issues')
            ->orderBy('name')
            ->get();

        return Inertia::render('projects/index', [
            'projects' => $projects,
        ]);
    }

    /**
     * Create a new project owned by the authenticated user. The default
     * board columns are created and the owner is auto-attached as a
     * member by `Project::createWithDefaultColumns()`.
     */
    public function store(StoreProjectRequest $request): RedirectResponse
    {
        Project::createWithDefaultColumns([
            ...$request->validated(),
            'owner_id' => $request->user()->id,
        ]);

        return redirect()->route('projects.index');
    }

    /**
     * Update a project's key, name, and description. Owner only —
     * enforced by `UpdateProjectRequest::authorize()`.
     */
    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        return redirect()->route('projects.index');
    }

    /**
     * Archive (soft delete) a project. It disappears from the index and
     * sidebar for every member. Owner only.
     */
    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('archive', $project);

        $project->delete();

        return redirect()->route('projects.index');
    }

    /**
     * Display the authenticated user's archived (owned) projects, each
     * annotated with how many issues and sprints it holds — shown in the
     * force-delete confirmation so the owner knows what they'd lose.
     */
    public function trash(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $projects = Project::onlyTrashed()
            ->where('owner_id', $request->user()->id)
            ->withCount(['issues', 'sprints'])
            ->orderBy('name')
            ->get();

        return Inertia::render('projects/trash', [
            'projects' => $projects,
        ]);
    }

    /**
     * Restore an archived project. Owner only.
     */
    public function restore(Project $project): RedirectResponse
    {
        Gate::authorize('restore', $project);

        $project->restore();

        return redirect()->route('projects.trash');
    }

    /**
     * Permanently delete an archived project and, via DB cascade, all of
     * its board columns, sprints, labels, memberships, issues, comments,
     * and issue-label pivots. Owner only.
     */
    public function forceDelete(Project $project): RedirectResponse
    {
        Gate::authorize('forceDelete', $project);

        $project->forceDelete();

        return redirect()->route('projects.trash');
    }
}
