<?php

namespace App\Mcp\Tools\Captures;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Capture;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: read. Every untriaged capture (capture-inbox spec), oldest first: text, source (web/mobile/ai) and when it came in. None of these are priorities or Now tasks — they are waiting for Marco to triage. Same list as the web inbox.')]
class ListInbox extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $captures = Capture::query()->where('user_id', $user->id)->untriaged()->get();

        return Response::json([
            'url' => $this->links->inbox(),
            'total' => $captures->count(),
            'captures' => $captures->map(fn (Capture $capture): array => [
                'id' => $capture->id,
                'text' => $capture->text,
                'source' => $capture->source->value,
                'created_at' => $capture->created_at?->toIso8601String(),
            ])->all(),
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
