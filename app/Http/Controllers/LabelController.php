<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLabelRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class LabelController extends Controller
{
    /**
     * Create a new label for the project. Any member may create one — the
     * owner-only management screen (rename/delete, `index`/`update`/
     * `destroy`) is left for T-10.5 to add to this same resource-style
     * controller.
     */
    public function store(StoreLabelRequest $request, Project $project): RedirectResponse
    {
        $project->labels()->create($request->validated());

        return back();
    }
}
