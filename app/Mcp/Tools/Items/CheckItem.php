<?php

namespace App\Mcp\Tools\Items;

use App\Actions\Items\CheckItem as CheckItemAction;
use App\Actions\Support\Actor;
use App\Http\Requests\CheckItemRequest;
use App\Http\Resources\ItemDetails;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResolvesObjectiveItems;
use App\Mcp\Support\ResourceLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: minor (applied directly and audited) — only when Marco explicitly named the task. Mark a task or milestone done, exactly like "Marcar hecho" on the web: a locked item (a prerequisite not done) is refused; a milestone requires a line of evidence; checking an already-done item changes nothing. Returns the item and `unlocked`, the items that became available.')]
class CheckItem extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private CheckItemAction $checkItem,
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

        $validated = $this->formRequests->replay(CheckItemRequest::class, $request->all(), $user)->validated();

        $result = ($this->checkItem)(Actor::aiMcp($user), $item, $validated['evidence'] ?? null, $validated['link'] ?? null);

        return Response::json([
            'item' => [
                ...ItemDetails::present(ItemDetails::load($result->item)),
                'url' => $this->links->item($result->item, $objective),
            ],
            'unlocked' => ItemDetails::neighbours($result->unlocked),
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
                ->description('Key of the item to check (e.g. "SALUD-7"). Must belong to objective_key.')
                ->required(),
            'evidence' => $schema->string()
                ->description('Required for a milestone: one line saying what got done and where it shows.'),
            'link' => $schema->string()
                ->description('Optional URL backing the milestone evidence.'),
        ];
    }
}
