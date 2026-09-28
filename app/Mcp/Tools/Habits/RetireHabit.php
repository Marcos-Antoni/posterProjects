<?php

namespace App\Mcp\Tools\Habits;

use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: major-proposal. Retire a personal habit through the retirement protocol: a written reason of at least 10 characters; the habit keeps its full history, disappears from today and rejects entries until restored from Retirados. Retiring is a MAJOR operation: the AI never applies it directly — without an accepted proposal or a permission grant (Phase 8) the call is refused and nothing changes; ask Marco. There is no delete.')]
class RetireHabit extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private RetireElement $retire,
        private ResourceLinker $links,
    ) {}

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

        try {
            $result = ($this->retire)(Actor::aiMcp($user), $habit, (string) $request->get('reason', ''));
        } catch (MajorOperationRequiresProposal $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json([
            'retirement' => [
                'id' => $result->retirement->id,
                'reason' => $result->retirement->reason,
                'decision' => $result->retirement->decision->value,
            ],
            'url' => $this->links->retired(),
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
                ->description('Id of the habit to retire. Must belong to the caller and not be retired.')
                ->required(),
            'reason' => $schema->string()
                ->description('Why it is retired, in Marco\'s words (at least 10 characters). Shown later in Retirados to spot patterns.')
                ->required(),
        ];
    }
}
