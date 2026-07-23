<?php

namespace App\Mcp\Tools\Labels;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Detach a label from an issue. Any project member may do this. The label must currently be attached to the issue.')]
class DetachIssueLabel extends Tool
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

        $issue = Issue::resolveByKey($project, (string) $request->get('issue_key'));

        if ($issue === null) {
            return Response::error("Issue not found: {$request->get('issue_key')}");
        }

        // Same scoping as the web route's `scopeBindings()`: the label must
        // currently be attached to THIS issue, not merely belong to the
        // project.
        $label = $issue->labels()->whereKey($request->get('label_id'))->first();

        if ($label === null) {
            return Response::error("Label not attached to issue: {$request->get('label_id')}");
        }

        Gate::forUser($user)->authorize('view', $project);

        $issue->labels()->detach($label->id);
        $issue->setRelation('project', $project);

        return Response::json([
            'issue_key' => $issue->key,
            'label_id' => $label->id,
            'url' => $this->links->issue($issue),
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
                ->description('Key of the issue\'s project (e.g. "PROJ").')
                ->required(),
            'issue_key' => $schema->string()
                ->description('Key of the issue to detach the label from (e.g. "PROJ-123").')
                ->required(),
            'label_id' => $schema->integer()
                ->description('Id of the label to detach. Must currently be attached to the issue.')
                ->required(),
        ];
    }
}
