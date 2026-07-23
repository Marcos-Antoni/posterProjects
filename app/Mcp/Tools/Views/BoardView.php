<?php

namespace App\Mcp\Tools\Views;

use App\Http\Controllers\BoardController;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description("Show a project's board: its columns in order, each with the issues currently visible under the selected sprint filter (or the backlog). Read-only, project members only — same data as the web board. This inspects the board, it does not create, edit or move issues.")]
class BoardView extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private BoardController $board,
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

        Gate::forUser($user)->authorize('view', $project);

        // `BoardController::boardProps()` expects a regular HTTP request so it
        // can read the `?sprint=` query param the same way the web board does.
        $httpRequest = HttpRequest::create('/', 'GET', $this->sprintQuery($request));

        $props = $this->board->boardProps($httpRequest, $project);

        return Response::json([
            'project' => $props['project'],
            'columns' => $props['columns']->map(
                fn (BoardColumn $column): array => [
                    'id' => $column->id,
                    'name' => $column->name,
                    'position' => $column->position,
                    'issues' => $column->issues->map(
                        fn (Issue $issue): array => $this->presentIssue($issue, $project),
                    )->all(),
                ],
            )->all(),
            'sprints' => $props['sprints']->map(fn (Sprint $sprint): array => [
                'id' => $sprint->id,
                'name' => $sprint->name,
                'start_date' => $sprint->start_date->toDateString(),
                'end_date' => $sprint->end_date->toDateString(),
            ])->all(),
            'selected_sprint_id' => $props['selectedSprintId'],
            'active_sprint_id' => $props['activeSprintId'],
        ]);
    }

    /**
     * Translates the tool's `sprint_id` argument into the `?sprint=` query
     * param `boardProps()` reads, matching `BoardController::resolveSelectedSprintId()`:
     * an explicit `null` means "backlog", an omitted argument keeps the
     * controller's own default (the active sprint, if any).
     *
     * @return array<string, string>
     */
    private function sprintQuery(Request $request): array
    {
        if (! $request->has('sprint_id')) {
            return [];
        }

        $sprintId = $request->get('sprint_id');

        return ['sprint' => $sprintId === null ? '' : (string) $sprintId];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIssue(Issue $issue, Project $project): array
    {
        // Attaches the already-known `$project` before touching `Issue::key()`
        // (which internally reads `$this->project`) — same technique as
        // `IssueController::presentIssue()`, avoids an N+1 per row.
        $issue->setRelation('project', $project);

        return [
            'id' => $issue->id,
            'key' => $issue->key,
            'title' => $issue->title,
            'type' => $issue->type,
            'priority' => $issue->priority,
            'story_points' => $issue->story_points,
            'due_date' => $issue->due_date?->toDateString(),
            'assignee' => $issue->assignee === null ? null : [
                'id' => $issue->assignee->id,
                'name' => $issue->assignee->name,
            ],
            'labels' => $issue->labels->map(fn (Label $label): array => [
                'id' => $label->id,
                'name' => $label->name,
            ])->all(),
            'url' => $this->links->issue($issue),
        ];
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
                ->description('Key of the project whose board to show (e.g. "PROJ").')
                ->required(),
            'sprint_id' => $schema->integer()
                ->description('Id of the sprint to filter the board by. Pass null for the backlog. Omit entirely to use the currently active sprint (or the backlog if none is active).')
                ->nullable(),
        ];
    }
}
