<?php

namespace App\Mcp\Tools\Proposals;

use App\Actions\Proposals\CreateProposal;
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

#[Description('Nivel IA: major — retirar o restaurar es lo único que la IA nunca aplica sola: siempre crea una propuesta pendiente que Marco acepta o rechaza desde "Propuestas" (/ai/proposals) en la web; una vez aceptada se aplica en una sola transacción. `kind` es siempre "retire" (2026-09-29: crear y editar estructura — objetivo, plan, tarea, dependencia — ya no pasa por acá, usá los tools directos `create-objective`, `create-plan`, `add-items`, `update-item`, `add-dependency`, `remove-dependency`, `update-objective`, `update-plan`, que se aplican al toque y quedan auditados). `payload` = {target_type: objective|item|plan|habit, objective_key? (objective/item/plan), item_key? (item), plan_id? (plan), habit_id? (habit), reason, decision?} — reason necesita al menos 10 caracteres; decision se infiere si el elemento no tiene contenido. `summary` es una frase en español, legible por Marco, de qué hace la propuesta. Un payload mal formado se rechaza acá mismo, con el error puntual, antes de guardar nada. Devuelve el id de la propuesta y su URL web.')]
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
                ->description('Siempre "retire".')
                ->enum(['retire'])
                ->required(),
            'payload' => $schema->object([
                'target_type' => $schema->string()
                    ->description('What to retire.')
                    ->enum(ProposalTargetType::class),
                'objective_key' => $schema->string()
                    ->description('Key of an EXISTING objective (e.g. "SALUD"). Required with target_type objective, item or plan.'),
                'item_key' => $schema->string()
                    ->description('Item key (e.g. "SALUD-7"). Required with target_type=item.'),
                'plan_id' => $schema->integer()
                    ->description('Plan id. Required with target_type=plan.'),
                'habit_id' => $schema->integer()
                    ->description('Habit id. Required with target_type=habit.'),
                'reason' => $schema->string()
                    ->description('Why it is being retired, at least 10 characters.'),
                'decision' => $schema->string()
                    ->description('Content decision; omit to let it default (archive as-is when there is no content).')
                    ->enum(RetirementDecision::class),
            ])
                ->description('The retire target and reason — see each field\'s note above.')
                ->required(),
            'summary' => $schema->string()
                ->description('Human-readable Spanish summary of what this proposal does, for Marco to read at a glance.')
                ->required(),
        ];
    }
}
