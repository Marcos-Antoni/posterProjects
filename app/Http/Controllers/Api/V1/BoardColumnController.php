<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesProjectByKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\BoardColumnResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BoardColumnController extends Controller
{
    use ResolvesProjectByKey;

    /**
     * List `{project}`'s board columns, ordered by position.
     *
     * No eager load: `BoardColumnResource` reads four raw columns and zero
     * relations, so `->with(...)` anything would be pure cost (design D-5).
     * `boardColumns()` already orders by `position` (`Project.php:60-63`),
     * and `position` carries a DB-level `unique(project_id, position)`
     * index, so no tiebreaker is needed (design D-6).
     */
    public function index(Request $request, string $project): AnonymousResourceCollection
    {
        $resolvedProject = $this->resolveProject($request, $project);

        return BoardColumnResource::collection($resolvedProject->boardColumns()->get());
    }
}
