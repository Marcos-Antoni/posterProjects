<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesProjectByKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\LabelResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LabelController extends Controller
{
    use ResolvesProjectByKey;

    /**
     * List `{project}`'s complete label catalogue, ordered by name.
     *
     * `withCount('issues')` is a single correlated sub-select (design D-3),
     * so a zero-issue label still appears with `issues_count: 0` instead of
     * being silently dropped. `name` is a total order within one project
     * (`unique(['project_id', 'name'])` at the DB level), so no tiebreaker
     * is needed (design D-4).
     */
    public function index(Request $request, string $project): AnonymousResourceCollection
    {
        $resolvedProject = $this->resolveProject($request, $project);

        return LabelResource::collection(
            $resolvedProject->labels()->withCount('issues')->orderBy('labels.name')->get()
        );
    }
}
