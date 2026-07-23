<?php

namespace App\Mcp\Tools\Labels;

use App\Http\Requests\StoreIssueLabelRequest;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Attach an existing project label to an issue. Any project member may do this. Idempotent — attaching an already-attached label is a silent no-op. Use detach-issue-label to remove it.')]
class AttachIssueLabel extends Tool
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

        $issue = Issue::resolveByKey($project, (string) $request->get('issue_key'));

        if ($issue === null) {
            return Response::error("Issue not found: {$request->get('issue_key')}");
        }

        $validated = $this->formRequests->replay(
            StoreIssueLabelRequest::class,
            $request->all(),
            $user,
            ['project' => $project, 'issue' => $issue],
        )->validated();

        $issue->labels()->syncWithoutDetaching([$validated['label_id']]);
        $issue->setRelation('project', $project);

        return Response::json([
            'issue_key' => $issue->key,
            'label_id' => $validated['label_id'],
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
                ->description('Key of the issue to attach the label to (e.g. "PROJ-123").')
                ->required(),
            'label_id' => $schema->integer()
                ->description('Id of the label to attach. Must belong to the project.')
                ->required(),
        ];
    }
}
