<?php

namespace App\Mcp\Tools\Views;

use App\Http\Resources\RetiredView as RetiredViewModel;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: read. The Retired view ("Retirados"): every element Marco retired (tasks, milestones, plans, habits, objectives, captures) with its kind, title, objective, the reason he wrote, what happened to its content (moved / split / archived as-is), age at retirement in days and date, grouped by month, newest first; plus the patterns — counts per repeated reason keyword and the median age per kind. It is a reflective archive for spotting patterns, never a score or failure rate. Same entries and reasons as the web page. Optional filters: kind, objective (key), month (YYYY-MM). Read-only; restoring is major and done by Marco.')]
class RetiredView extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private RetiredViewModel $view,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $filters = array_filter([
            'kind' => $request->get('kind'),
            'objective' => $request->get('objective'),
            'month' => $request->get('month'),
        ], fn (mixed $value): bool => is_string($value));

        $view = $this->view->build($this->authenticatedUser($request), $filters);

        return Response::json([
            'url' => $this->links->retired($view['filters']),
            'total' => $view['total'],
            'filters' => $view['filters'],
            'patterns' => $view['patterns'],
            'months' => array_map(fn (array $month): array => [
                'month' => $month['label'],
                'count' => $month['count'],
                'entries' => array_map(fn (array $entry): array => [
                    'kind' => $entry['kind'],
                    'title' => $entry['title'],
                    'objective' => $entry['objective_label'],
                    'reason' => $entry['reason'],
                    'decision' => $entry['decision'],
                    'content' => $entry['decision_text'],
                    'age_days' => $entry['age_days'],
                    'retired_on' => $entry['retired_on'],
                    'can_restore' => $entry['restore']['allowed'],
                    'restore_blocked_by' => $entry['restore']['blocked_reason'],
                ], $month['entries']),
            ], $view['months']),
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
            'kind' => $schema->string()
                ->enum(['task', 'milestone', 'plan', 'habit', 'objective', 'capture'])
                ->description('Only this kind.'),
            'objective' => $schema->string()->description('Only elements of this objective key (e.g. "SALUD").'),
            'month' => $schema->string()->description('Only retirements of this month, "YYYY-MM" (UTC-6).'),
        ];
    }
}
