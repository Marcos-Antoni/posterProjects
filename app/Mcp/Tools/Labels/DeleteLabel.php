<?php

namespace App\Mcp\Tools\Labels;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('PERMANENTLY delete a project label, detaching it from every issue that currently has it. Irreversible. Owner only.')]
class DeleteLabel extends Tool
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

        $label = $project->labels()->whereKey($request->get('label_id'))->first();

        if ($label === null) {
            return Response::error("Label not found: {$request->get('label_id')}");
        }

        Gate::forUser($user)->authorize('delete', $label);

        $url = $this->links->label($label);
        $label->delete();

        return Response::json([
            'deleted' => true,
            'id' => $label->id,
            'url' => $url,
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
                ->description('Id of the label to delete. Must belong to the project.')
                ->required(),
        ];
    }
}
