<?php

namespace App\Mcp\Tools\Items;

use App\Actions\Items\ShrinkTwoMinuteVersion;
use App\Actions\Support\Actor;
use App\Http\Resources\ItemDetails;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResolvesObjectiveItems;
use App\Mcp\Support\ResourceLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: minor (applied directly and audited) — the "estoy trabado" fallback, only when Marco explicitly asked for a smaller step. Replaces ONLY the item\'s 2-minute version with one smaller physical action; the previous one goes to its history. Refused for an already-completed item, or when the new action is blank or identical to the current one. Returns the item.')]
class ReplaceTwoMinute extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(
        private ShrinkTwoMinuteVersion $shrink,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);
        $objective = $this->objectiveOrError($user, $request->get('objective_key'));

        if ($objective instanceof Response) {
            return $objective;
        }

        $item = $this->itemOrError($objective, $request->get('item_key'));

        if ($item instanceof Response) {
            return $item;
        }

        $result = ($this->shrink)(Actor::aiMcp($user), $item, (string) $request->get('two_minute_version'));

        return Response::json([
            'item' => [
                ...ItemDetails::present(ItemDetails::load($result)),
                'url' => $this->links->item($result, $objective),
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
            'objective_key' => $schema->string()
                ->description('Key of the item\'s objective (e.g. "SALUD").')
                ->required(),
            'item_key' => $schema->string()
                ->description('Key of the item to shrink (e.g. "SALUD-7"). Must belong to objective_key.')
                ->required(),
            'two_minute_version' => $schema->string()
                ->description('The one smaller physical action, the smallest Marco can do right now.')
                ->required(),
        ];
    }
}
