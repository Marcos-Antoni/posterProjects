<?php

namespace App\Mcp\Tools\Items;

use App\Actions\Items\StartItem as StartItemAction;
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

#[Description('Nivel IA: minor (applied directly and audited) — only when Marco explicitly named the task to start now. Makes an available item THE active one (now-focus "Exactly One Active Task At A Time"): the previously active item, if any, goes back to available (or locked) and its focus session closes as "switched"; a locked item is refused, naming what still blocks it; a done item is refused; starting the item that is already active is a no-op. Returns the item.')]
class StartItem extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(
        private StartItemAction $startItem,
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

        $result = ($this->startItem)(Actor::aiMcp($user), $item);

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
                ->description('Key of the item to start (e.g. "SALUD-7"). Must belong to objective_key.')
                ->required(),
        ];
    }
}
