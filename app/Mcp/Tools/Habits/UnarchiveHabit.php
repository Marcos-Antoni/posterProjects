<?php

namespace App\Mcp\Tools\Habits;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Reactivate an archived personal habit. Owner only.')]
class UnarchiveHabit extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $habit = $user->habits()->whereKey($request->get('habit_id'))->first();

        if ($habit === null) {
            return Response::error("Habit not found: {$request->get('habit_id')}");
        }

        Gate::forUser($user)->authorize('unarchive', $habit);

        $habit->update(['archived_at' => null]);

        return Response::json([
            'habit' => [
                'id' => $habit->id,
                'archived_at' => $habit->archived_at,
                'url' => $this->links->habit($habit),
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
            'habit_id' => $schema->integer()
                ->description('Id of the habit to reactivate. Must belong to the caller.')
                ->required(),
        ];
    }
}
