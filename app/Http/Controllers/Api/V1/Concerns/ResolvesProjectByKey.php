<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Project;
use Illuminate\Http\Request;

trait ResolvesProjectByKey
{
    /**
     * Resolve `{project}` (a `key`, not an id) among the projects the
     * requesting user is a member of. Deliberately omits `withTrashed()`
     * (unlike `ProjectController::show`): an archived project's issues
     * 404, matching the web behaviour this API exposes.
     */
    protected function resolveProject(Request $request, string $projectKey): Project
    {
        return $request->user()->projects()->where('projects.key', $projectKey)->firstOrFail();
    }
}
