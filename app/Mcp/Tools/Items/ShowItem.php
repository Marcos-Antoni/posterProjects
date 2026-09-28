<?php

namespace App\Mcp\Tools\Items;

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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Nivel IA: read. Show one task or milestone by its key inside its objective — the same payload as the web item page: kind, title, description, 2-minute version, derived state, plan, target date, prerequisites and the items it unlocks (each with key, title and state), completion time and milestone evidence. The item key must belong to the given objective. Read-only.')]
class ShowItem extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $objective = $this->objectiveOrError($this->authenticatedUser($request), $request->get('objective_key'));

        if ($objective instanceof Response) {
            return $objective;
        }

        $item = $this->itemOrError($objective, $request->get('item_key'));

        if ($item instanceof Response) {
            return $item;
        }

        return Response::json([
            'item' => [
                ...ItemDetails::present(ItemDetails::load($item)),
                'objective' => ['key' => $objective->key, 'title' => $objective->title],
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
                ->description('Key of the item (e.g. "SALUD-7"). Must belong to objective_key.')
                ->required(),
        ];
    }
}
