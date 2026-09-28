<?php

namespace App\Mcp\Tools\Habits;

use App\Actions\Retirement\RestoreElement;
use App\Actions\Support\Actor;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Habit;
use App\Models\Retirement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: major-proposal. Restore a retired personal habit: it re-enables entries and keeps its history; the retirement stays as history. Restoring is a MAJOR operation: the AI never applies it directly — without an accepted proposal or a permission grant (Phase 8) the call is refused and nothing changes; ask Marco, who can restore it from Retirados.')]
class RestoreHabit extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private RestoreElement $restore,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $habit = Habit::onlyRetired()->where('user_id', $user->id)->whereKey($request->get('habit_id'))->first();
        $retirement = $habit === null ? null : Retirement::query()
            ->where('retirable_type', $habit->getMorphClass())
            ->where('retirable_id', $habit->id)
            ->open()
            ->latest('retired_at')
            ->first();

        if ($habit === null || $retirement === null) {
            return Response::error("Retired habit not found: {$request->get('habit_id')}");
        }

        try {
            ($this->restore)(Actor::aiMcp($user), $retirement);
        } catch (MajorOperationRequiresProposal $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json(['habit' => ['id' => $habit->id, 'url' => $this->links->habit($habit)]]);
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
                ->description('Id of the retired habit to restore. Must belong to the caller.')
                ->required(),
        ];
    }
}
