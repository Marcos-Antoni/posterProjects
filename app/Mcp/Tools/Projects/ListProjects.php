<?php

namespace App\Mcp\Tools\Projects;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Project;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the active projects the authenticated user is a member of, with issue counts and board URLs. Trashed (archived) projects are not included — use list-trashed-projects for those.')]
class ListProjects extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $projects = $this->authenticatedUser($request)
            ->projects()
            ->withCount('issues')
            ->orderBy('name')
            ->get();

        return Response::json([
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'key' => $project->key,
                'name' => $project->name,
                'description' => $project->description,
                'issues_count' => $project->issues_count,
                'url' => $this->links->project($project),
            ])->all(),
        ]);
    }
}
