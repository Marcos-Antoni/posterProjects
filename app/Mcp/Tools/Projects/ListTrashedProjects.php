<?php

namespace App\Mcp\Tools\Projects;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Project;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the authenticated user\'s archived (trashed) projects, with the issue and sprint counts that would be lost on permanent deletion. Only projects the user owns appear here.')]
class ListTrashedProjects extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $projects = Project::onlyTrashed()
            ->where('owner_id', $this->authenticatedUser($request)->id)
            ->withCount(['issues', 'sprints'])
            ->orderBy('name')
            ->get();

        return Response::json([
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'key' => $project->key,
                'name' => $project->name,
                'issues_count' => $project->issues_count,
                'sprints_count' => $project->sprints_count,
                'url' => $this->links->project($project),
            ])->all(),
        ]);
    }
}
