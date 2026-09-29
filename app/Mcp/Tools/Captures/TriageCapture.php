<?php

namespace App\Mcp\Tools\Captures;

use App\Actions\Captures\ConvertCaptureToItem;
use App\Actions\Support\Actor;
use App\Http\Resources\ItemDetails;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResolvesObjectiveItems;
use App\Mcp\Support\ResourceLinker;
use App\Models\Capture;
use App\Models\Plan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: minor (applied directly and audited) — only when Marco explicitly asked to turn one of his captures into a task, naming the plan. Converts an untriaged capture into a task of an existing plan, exactly like "Convertir en tarea" on the inbox screen: the 2-minute version is required, same as adding an item anywhere else. The capture keeps a link to the new item and leaves the inbox. A capture that does not exist, belongs to someone else, or was already triaged is refused (not found). Returns the new item.')]
class TriageCapture extends Tool
{
    use ResolvesAuthenticatedUser;
    use ResolvesObjectiveItems;

    public function __construct(
        private ConvertCaptureToItem $convert,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $capture = Capture::query()
            ->where('user_id', $user->id)
            ->whereKey($request->get('capture_id'))
            ->first();

        if ($capture === null) {
            return Response::error("Capture not found: {$request->get('capture_id')}");
        }

        $objective = $this->objectiveOrError($user, $request->get('objective_key'));

        if ($objective instanceof Response) {
            return $objective;
        }

        $plan = Plan::query()->where('objective_id', $objective->id)->whereKey($request->get('plan_id'))->first();

        if ($plan === null) {
            return Response::error("Plan not found: {$request->get('plan_id')}");
        }

        $item = ($this->convert)(Actor::aiMcp($user), $capture, $plan, [
            'title' => (string) $request->get('title'),
            'two_minute_version' => (string) $request->get('two_minute_version'),
        ]);

        return Response::json([
            'item' => [
                ...ItemDetails::present(ItemDetails::load($item)),
                'url' => $this->links->item($item, $objective),
            ],
            'capture' => ['id' => $capture->id, 'triaged' => true],
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
            'capture_id' => $schema->integer()
                ->description('Id of the untriaged capture to convert. Must belong to the caller.')
                ->required(),
            'objective_key' => $schema->string()
                ->description('Key of the objective that owns the destination plan (e.g. "SALUD").')
                ->required(),
            'plan_id' => $schema->integer()
                ->description('Id of the existing plan the new item lands on. Must belong to objective_key.')
                ->required(),
            'title' => $schema->string()
                ->description('The new item\'s title.')
                ->required(),
            'two_minute_version' => $schema->string()
                ->description('Required: the first 2-minute version of the new item.')
                ->required(),
        ];
    }
}
