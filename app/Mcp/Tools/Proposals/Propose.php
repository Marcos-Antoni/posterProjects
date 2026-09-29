<?php

namespace App\Mcp\Tools\Proposals;

use App\Actions\Proposals\CreateProposal;
use App\Enums\ItemKind;
use App\Enums\ProposalTargetType;
use App\Enums\RetirementDecision;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: major — nunca aplica nada, siempre crea una propuesta pendiente que Marco acepta o rechaza desde "Propuestas" en la web. `kind` es "create_plan" o "retire". Para create_plan: `payload` = {objective_key, title, level?, items?: [{title, two_minute_version, description?, kind?, target_date?}]} — crea el plan (borrador) y sus tareas/hitos cuando se acepta. Para retire: `payload` = {target_type: objective|item|plan|habit, objective_key? (objective/item/plan), item_key? (item), plan_id? (plan), habit_id? (habit), reason, decision?} — reason necesita al menos 10 caracteres; decision se infiere si el elemento no tiene contenido. `summary` es una frase en español, legible por Marco, de qué hace la propuesta. Devuelve el id de la propuesta y su URL web.')]
class Propose extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private CreateProposal $createProposal,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        $payload = $request->get('payload');
        $payload = is_array($payload) ? $payload : [];

        try {
            $proposal = ($this->createProposal)(
                $user,
                (string) $request->get('kind'),
                $payload,
                (string) $request->get('summary'),
                'mcp',
            );
        } catch (ValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json([
            'proposal' => [
                'id' => $proposal->id,
                'kind' => $proposal->kind,
                'status' => $proposal->status->value,
            ],
            'url' => $this->links->proposal($proposal),
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
            'kind' => $schema->string()
                ->description('"create_plan" o "retire".')
                ->enum(['create_plan', 'retire'])
                ->required(),
            'payload' => $schema->object([
                'objective_key' => $schema->string()
                    ->description('Key of the objective (e.g. "SALUD"). create_plan; retire with target_type objective, item or plan.'),
                'title' => $schema->string()
                    ->description('Plan title. create_plan.'),
                'level' => $schema->integer()
                    ->description('Optional plan level, 1-99. create_plan.'),
                'items' => $schema->array()
                    ->items($schema->object([
                        'title' => $schema->string()->required(),
                        'two_minute_version' => $schema->string()->required(),
                        'description' => $schema->string(),
                        'kind' => $schema->string()->enum(ItemKind::class),
                        'target_date' => $schema->string()->description('Y-m-d'),
                    ]))
                    ->description('Tasks/milestones to add to the new plan. create_plan.'),
                'target_type' => $schema->string()
                    ->description('What to retire.')
                    ->enum(ProposalTargetType::class),
                'item_key' => $schema->string()
                    ->description('Item key (e.g. "SALUD-7"). retire, target_type=item.'),
                'plan_id' => $schema->integer()
                    ->description('Plan id. retire, target_type=plan.'),
                'habit_id' => $schema->integer()
                    ->description('Habit id. retire, target_type=habit.'),
                'reason' => $schema->string()
                    ->description('Why it is being retired, at least 10 characters. retire.'),
                'decision' => $schema->string()
                    ->description('Content decision; omit to let it default (archive as-is when there is no content). retire.')
                    ->enum(RetirementDecision::class),
            ])
                ->description('The exact payload for `kind` — see each field\'s per-kind note above.')
                ->required(),
            'summary' => $schema->string()
                ->description('Human-readable Spanish summary of what this proposal does, for Marco to read at a glance.')
                ->required(),
        ];
    }
}
