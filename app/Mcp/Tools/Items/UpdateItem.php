<?php

namespace App\Mcp\Tools\Items;

use App\Actions\Items\UpdateItem as UpdateItemAction;
use App\Actions\Support\Actor;
use App\Enums\TwoMinuteSource;
use App\Http\Requests\UpdateItemRequest;
use App\Http\Resources\ItemDetails;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Item;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: minor (applied directly and audited) — edits an item\'s title, description, 2-minute version, target date, plan (only within the same objective) or manual position. Only the fields sent change; the 2-minute version is never cleared, and a replaced one goes to its history. Returns the updated item.')]
class UpdateItem extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private UpdateItemAction $updateItem,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $itemKey = $request->get('item_key');
        $item = is_string($itemKey) ? Item::resolveForOwner($user, $itemKey) : null;

        if ($item === null) {
            return Response::error('Item not found: '.(is_scalar($itemKey) ? $itemKey : ''));
        }

        $validated = $this->formRequests->replay(UpdateItemRequest::class, $request->all(), $user)->validated();

        $item = ($this->updateItem)(Actor::aiMcp($user), $item, $validated, TwoMinuteSource::Ai);

        return Response::json([
            'item' => [
                ...ItemDetails::present(ItemDetails::load($item)),
                'url' => $this->links->item($item),
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
            'item_key' => $schema->string()
                ->description('Item key (e.g. "SALUD-7"). May belong to any of the caller\'s objectives.')
                ->required(),
            'title' => $schema->string(),
            'two_minute_version' => $schema->string()
                ->description('The item\'s new 2-minute version — never cleared.'),
            'description' => $schema->string(),
            'target_date' => $schema->string()->description('Y-m-d, or omit to leave unchanged.'),
            'plan_id' => $schema->integer()
                ->description('Moves the item to this plan of the SAME objective.'),
            'position' => $schema->integer()
                ->description('0-based position within the item\'s plan (ignored together with a plan move).'),
        ];
    }
}
