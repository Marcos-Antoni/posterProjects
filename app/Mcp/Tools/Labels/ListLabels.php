<?php

namespace App\Mcp\Tools\Labels;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Label;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List every label defined in a project, with how many issues currently wear each one — the same data as the label management screen. Read-only, any project member. Renaming and deleting labels are owner only.')]
class ListLabels extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $project = Project::query()->where('key', $request->get('project_key'))->first();

        if ($project === null) {
            return Response::error("Project not found: {$request->get('project_key')}");
        }

        Gate::forUser($user)->authorize('view', $project);

        $labels = $project->labels()->withCount('issues')->orderBy('name')->get();

        return Response::json([
            'labels' => $labels->map(fn (Label $label): array => [
                'id' => $label->id,
                'name' => $label->name,
                'issues_count' => $label->issues_count,
                'url' => $this->links->label($label),
            ])->all(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Key of the project whose labels to list (e.g. "PROJ").')
                ->required(),
        ];
    }
}
