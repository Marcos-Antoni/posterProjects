<?php

namespace App\Mcp\Tools\Labels;

use App\Http\Requests\UpdateLabelRequest;
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

#[Description('Rename an existing project label. Owner only.')]
class RenameLabel extends Tool
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

        $label = $project->labels()->whereKey($request->get('label_id'))->first();

        if ($label === null) {
            return Response::error("Label not found: {$request->get('label_id')}");
        }

        $validated = $this->formRequests->replay(
            UpdateLabelRequest::class,
            $request->all(),
            $user,
            ['project' => $project, 'label' => $label],
        )->validated();

        $label->update($validated);

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
                ->description('Key of the label\'s project (e.g. "PROJ").')
                ->required(),
            'label_id' => $schema->integer()
                ->description('Id of the label to rename. Must belong to the project.')
                ->required(),
            'name' => $schema->string()
                ->description('New label name. Must be unique within the project.')
                ->required(),
        ];
    }
}
