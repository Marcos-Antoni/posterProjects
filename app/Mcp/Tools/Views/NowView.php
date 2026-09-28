<?php

namespace App\Mcp\Tools\Views;

use App\Http\Resources\NowView as NowReadModel;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('now-view')]
#[IsReadOnly]
#[Description('Nivel IA: read. What Marco should do now — the same single task the web "Ahora" screen shows: the active task, else ONE suggestion (first available item of the weekly main priority, else the oldest available item of the first active objective). Returns `now` (key, title, kind, description, 2-minute version to start with, is_active, focus_started_at, objective, plan, the milestone it leads to, the items completing it unlocks, and its absolute url) or `now: null` when nothing is available, plus `restart` (true after a day without activity: offer to restart with the 2-minute version, never mention missed days) and `now_url`, the absolute URL of the Ahora screen. Never a list of tasks. Read-only.')]
class NowView extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private NowReadModel $now) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $owner = $this->authenticatedUser($request);

        return Response::json([
            'now' => $this->now->present($owner),
            'restart' => $this->now->needsRestart($owner),
            'now_url' => route('now'),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
