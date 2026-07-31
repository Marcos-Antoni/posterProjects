<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    /**
     * List the authenticated user's active (non-archived) projects, ordered
     * by name then id. No pagination — the entire result set ships in one
     * response.
     *
     * Both `orderBy` columns MUST stay table-qualified: `project_members`
     * (the pivot behind `projects()`) has its own `id` and timestamps, so a
     * bare `orderBy('id')` is an ambiguous-column SQL error.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Project::class);

        $projects = $request->user()
            ->projects()
            ->withCount('issues')
            ->orderBy('projects.name')
            ->orderBy('projects.id')
            ->get();

        return ProjectResource::collection($projects);
    }

    /**
     * Resolve a single project by key — active or archived — among the
     * projects the requesting user is a member of.
     *
     * Unknown key, non-member key, and force-deleted key all fall through
     * the same `firstOrFail()` -> `ModelNotFoundException` path, so none of
     * the three can be distinguished from the response (non-disclosure).
     */
    public function show(Request $request, string $project): ProjectResource
    {
        $found = $request->user()
            ->projects()
            ->withTrashed()
            ->withCount('issues')
            ->where('projects.key', $project)
            ->firstOrFail();

        return new ProjectResource($found);
    }
}
