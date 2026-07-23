<?php

namespace App\Mcp\Tools\Labels;

use App\Http\Requests\StoreLabelRequest;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new label in a project. Any project member may create one. Use attach-issue-label afterward to apply it to an issue.')]
class CreateLabel extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private ResourceLinker $links,
    ) {}

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

        $validated = $this->formRequests->replay(
            StoreLabelRequest::class,
            $request->all(),
            $user,
            ['project' => $project],
        )->validated();

        $label = $project->labels()->create($validated);

        return Response::json([
            'label' => [
                'id' => $label->id,
                'name' => $label->name,
                'url' => $this->links->label($label),
            ],
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
                ->description('Key of the project to create the label in (e.g. "PROJ").')
                ->required(),
            'name' => $schema->string()
                ->description('Label name. Must be unique within the project.')
                ->required(),
        ];
    }
}
