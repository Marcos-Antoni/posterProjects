<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesProjectByKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\SprintResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SprintController extends Controller
{
    use ResolvesProjectByKey;

    /**
     * List `{project}`'s sprints, newest first.
     *
     * `withCount('issues')` emits one correlated sub-select, never a join,
     * so `issues_count` costs nothing extra per row (design D-5). Ordering
     * is `start_date DESC, id DESC` — the `id` tiebreaker is mandatory
     * because `start_date` is a `date` and two sprints may legitimately
     * share one (design D-6). Both columns stay table-qualified so the
     * API has one rule, not two, matching `IssueController`.
     */
    public function index(Request $request, string $project): AnonymousResourceCollection
    {
        $resolvedProject = $this->resolveProject($request, $project);

        $sprints = $resolvedProject->sprints()
            ->withCount('issues')
            ->orderByDesc('sprints.start_date')
            ->orderByDesc('sprints.id')
            ->get();

        return SprintResource::collection($sprints);
    }
}
