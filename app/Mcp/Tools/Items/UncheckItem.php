<?php

namespace App\Mcp\Tools\Items;

use App\Actions\Items\UncheckItem as UncheckItemAction;
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

#[Description('Nivel IA: minor (applied directly and audited) — only when Marco explicitly named the task. Undo a check without penalty, exactly like the web: the item returns to available (or locked), its dependents that were not done become locked again, and a done plan returns to active. Unchecking an item that is not done changes nothing.')]
class UncheckItem extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(
        private UncheckItemAction $uncheckItem,
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

        $item = ($this->uncheckItem)(Actor::aiMcp($user), $item);

        return Response::json([
            'item' => [
                ...ItemDetails::present(ItemDetails::load($item)),
                'url' => $this->links->item($item, $objective),
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
                ->description('Key of the item to uncheck (e.g. "SALUD-7"). Must belong to objective_key.')
                ->required(),
        ];
    }
}
