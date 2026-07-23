<?php

namespace App\Mcp\Tools\Comments;

use App\Http\Requests\StoreCommentRequest;
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

#[Description('Post a new comment on an issue. Any project member may comment. Use update-comment or delete-comment afterward — only the comment\'s own author can edit or remove it.')]
class CreateComment extends Tool
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
            StoreCommentRequest::class,
            $request->all(),
            $user,
            ['project' => $project, 'issue' => $issue],
        )->validated();

        $comment = $issue->comments()->create([
            'user_id' => $user->id,
            'body' => $validated['body'],
        ]);

        $comment->setRelation('issue', $issue);
        $issue->setRelation('project', $project);

        return Response::json([
            'comment' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
                'url' => $this->links->comment($comment),
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
                ->description('Key of the issue\'s project (e.g. "PROJ").')
                ->required(),
            'issue_key' => $schema->string()
                ->description('Key of the issue to comment on (e.g. "PROJ-123").')
                ->required(),
            'body' => $schema->string()
                ->description('The comment text.')
                ->required(),
        ];
    }
}
