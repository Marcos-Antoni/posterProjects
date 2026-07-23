<?php

namespace App\Mcp\Tools\Comments;

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

#[Description('Delete a comment. Author only — not even the project owner can delete another member\'s comment. Irreversible.')]
class DeleteComment extends Tool
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

        $comment = $issue->comments()->whereKey($request->get('comment_id'))->first();

        if ($comment === null) {
            return Response::error("Comment not found: {$request->get('comment_id')}");
        }

        Gate::forUser($user)->authorize('delete', $comment);

        $comment->setRelation('issue', $issue);
        $issue->setRelation('project', $project);
        $url = $this->links->comment($comment);

        $comment->delete();

        return Response::json([
            'deleted' => true,
            'id' => $comment->id,
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
                ->description('Key of the issue\'s project (e.g. "PROJ").')
                ->required(),
            'issue_key' => $schema->string()
                ->description('Key of the comment\'s issue (e.g. "PROJ-123").')
                ->required(),
            'comment_id' => $schema->integer()
                ->description('Id of the comment to delete. Must belong to the issue and to the caller.')
                ->required(),
        ];
    }
}
