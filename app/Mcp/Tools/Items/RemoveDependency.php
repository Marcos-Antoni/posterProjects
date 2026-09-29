<?php

namespace App\Mcp\Tools\Items;

use App\Actions\Items\RemoveDependency as RemoveDependencyAction;
use App\Actions\Support\Actor;
use App\Http\Requests\ItemDependencyRequest;
use App\Http\Resources\ItemDetails;
use App\Mcp\Support\ReplaysFormRequest;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Item;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: minor (applied directly and audited) — removes the "prerequisite unlocks dependent" edge between two EXISTING items, exactly like the web dependency form. Removing an edge is not a retirement (it is a relation, not an element); the dependent\'s state is derived and may become available immediately. Returns the dependent item.')]
class RemoveDependency extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private ReplaysFormRequest $formRequests,
        private RemoveDependencyAction $removeDependency,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $prerequisite = $this->resolve($request->get('prerequisite_key'), $user, 'prerequisite_key');
        $dependent = $this->resolve($request->get('dependent_key'), $user, 'dependent_key');

        ($this->removeDependency)(Actor::aiMcp($user), $prerequisite, $dependent);

        $dependent = $dependent->refresh();

        return Response::json([
            'item' => [
                ...ItemDetails::present(ItemDetails::load($dependent)),
                'url' => $this->links->item($dependent),
            ],
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function resolve(mixed $key, User $user, string $field): Item
    {
        $validated = $this->formRequests
            ->replay(ItemDependencyRequest::class, ['key' => $key], $user)
            ->validated('key');

        $item = Item::resolveForOwner($user, strtoupper(trim((string) $validated)));

        if ($item === null) {
            throw ValidationException::withMessages([$field => 'No encontramos esa tarea o hito.']);
        }

        return $item;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'prerequisite_key' => $schema->string()
                ->description('Key of the item that opens the other (e.g. "SALUD-7").')
                ->required(),
            'dependent_key' => $schema->string()
                ->description('Key of the item that gets unlocked (e.g. "SALUD-8").')
                ->required(),
        ];
    }
}
