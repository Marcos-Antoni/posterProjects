<?php

namespace App\Mcp\Tools\Views;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description("Show the authenticated user's global calendar: every issue with a due date, across every project they are a member of, for a given month. Read-only, scoped to the caller — same data as the web calendar. This inspects due dates, it does not set or change them.")]
class CalendarView extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $month = $this->resolveMonth($request);

        $projectIds = $user->projects()->pluck('projects.id');

        $issues = Issue::query()
            ->whereNotNull('due_date')
            ->whereIn('project_id', $projectIds)
            ->whereBetween('due_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->with('project:id,key')
            ->orderBy('due_date')
            ->get();

        return Response::json([
            'month' => $month->format('Y-m'),
            'issues' => $issues->map(fn (Issue $issue): array => [
                'id' => $issue->id,
                'key' => $issue->key,
                'title' => $issue->title,
                'type' => $issue->type,
                'priority' => $issue->priority,
                'due_date' => $issue->due_date?->toDateString(),
                'project' => [
                    'key' => $issue->project->key,
                ],
                'url' => $this->links->issue($issue),
            ])->all(),
        ]);
    }

    /**
     * Parses the `month` argument (format "YYYY-MM") into the first day of
     * that month. Falls back to the current month on a missing or
     * malformed value — same defensive posture as
     * `CalendarController::resolveMonth()`.
     */
    private function resolveMonth(Request $request): Carbon
    {
        $monthParam = $request->get('month');

        if (is_string($monthParam) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam) === 1) {
            return Carbon::createFromFormat('Y-m-d', $monthParam.'-01')->startOfMonth();
        }

        return Carbon::now()->startOfMonth();
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()
                ->description('Month to show, format "YYYY-MM" (e.g. "2026-08"). Omit for the current month; a malformed value also falls back to the current month.'),
        ];
    }
}
