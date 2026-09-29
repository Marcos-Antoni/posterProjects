<?php

namespace App\Mcp\Tools\Proposals;

use App\Actions\Proposals\CreateProposal;
use App\Enums\ControlZone;
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

#[Description('Nivel IA: major — nunca aplica nada, siempre crea una propuesta pendiente que Marco acepta o rechaza desde "Propuestas" (/ai/proposals) en la web; una vez aceptada se aplica en una sola transacción. `kind` es "create_objective", "add_items", "update_item", "add_dependency", "create_plan" o "retire". create_objective: `payload` = {key, title, identity_statement?, outcome, deadline, metric: {name, target, current?}, risks: [string, ...], contingency, control_map?: [{zone, text}], plans?: [{title, level?, items?: [{title, kind?, two_minute_version, description?, target_date?}]}], dependencies?: [{prerequisite, dependent}]} — los 5 puntos del plan de control (outcome/deadline/metric/risks/contingency) son obligatorios, igual que en el form web; `dependencies` referencia items por su posición 0-based en el orden de declaración (todos los items de todos los plans, en orden). add_items: `payload` = {objective_key, plan_id, items: [...], dependencies?: [...]} — mismo formato de items/dependencies que create_objective, pero sobre un plan existente. update_item: `payload` = {item_key, title?, two_minute_version?, description?, target_date?, plan_id?, position?} — solo cambia lo enviado; two_minute_version nunca se borra. add_dependency: `payload` = {prerequisite_key, dependent_key} — "completar prerequisite abre dependent", entre dos items YA existentes (pueden ser de objetivos distintos). create_plan: `payload` = {objective_key, title, level?, items?: [...]} — crea el plan (borrador) y sus tareas/hitos. retire: `payload` = {target_type: objective|item|plan|habit, objective_key? (objective/item/plan), item_key? (item), plan_id? (plan), habit_id? (habit), reason, decision?} — reason necesita al menos 10 caracteres; decision se infiere si el elemento no tiene contenido. `summary` es una frase en español, legible por Marco, de qué hace la propuesta. Un payload mal formado se rechaza acá mismo, con el error puntual, antes de guardar nada. Devuelve el id de la propuesta y su URL web.')]
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
        $itemSchema = fn () => $schema->object([
            'title' => $schema->string()->required(),
            'two_minute_version' => $schema->string()->required(),
            'description' => $schema->string(),
            'kind' => $schema->string()->enum(ItemKind::class),
            'target_date' => $schema->string()->description('Y-m-d'),
        ]);

        $dependencySchema = fn () => $schema->object([
            'prerequisite' => $schema->integer()->required(),
            'dependent' => $schema->integer()->required(),
        ]);

        return [
            'kind' => $schema->string()
                ->description('"create_objective", "add_items", "update_item", "add_dependency", "create_plan" o "retire".')
                ->enum(['create_objective', 'add_items', 'update_item', 'add_dependency', 'create_plan', 'retire'])
                ->required(),
            'payload' => $schema->object([
                'key' => $schema->string()
                    ->description('New objective\'s key, 2-10 uppercase letters (e.g. "SALUD"). create_objective.'),
                'objective_key' => $schema->string()
                    ->description('Key of an EXISTING objective (e.g. "SALUD"). create_plan; add_items; retire with target_type objective, item or plan.'),
                'identity_statement' => $schema->string()
                    ->description('Optional identity statement. create_objective.'),
                'outcome' => $schema->string()
                    ->description('What is true when it is done. Required, part of the Control 5-point plan. create_objective.'),
                'deadline' => $schema->string()
                    ->description('Y-m-d. Required, part of the Control 5-point plan. create_objective.'),
                'metric' => $schema->object([
                    'name' => $schema->string()->required(),
                    'target' => $schema->number()->required(),
                    'current' => $schema->number(),
                ])->description('The single metric. Required, part of the Control 5-point plan. create_objective.'),
                'risks' => $schema->array()->items($schema->string())
                    ->description('What can go wrong, at least one. Required, part of the Control 5-point plan. create_objective.'),
                'contingency' => $schema->string()
                    ->description('What to do if a risk happens. Required, part of the Control 5-point plan. create_objective.'),
                'control_map' => $schema->array()
                    ->items($schema->object([
                        'zone' => $schema->string()->enum(ControlZone::class)->required(),
                        'text' => $schema->string()->required(),
                    ]))
                    ->description('Optional control map entries. create_objective.'),
                'plans' => $schema->array()
                    ->items($schema->object([
                        'title' => $schema->string()->required(),
                        'level' => $schema->integer()->description('1-99.'),
                        'items' => $schema->array()->items($itemSchema())
                            ->description('Tasks/milestones of this plan.'),
                    ]))
                    ->description('Optional plans (created as drafts) under the new objective, each with its own items. create_objective.'),
                'title' => $schema->string()
                    ->description('Plan title (create_plan) or objective title (create_objective) or item title (update_item).'),
                'level' => $schema->integer()
                    ->description('Optional plan level, 1-99. create_plan.'),
                'items' => $schema->array()
                    ->items($itemSchema())
                    ->description('Tasks/milestones to add to the plan. create_plan (new plan); add_items (existing plan).'),
                'dependencies' => $schema->array()
                    ->items($dependencySchema())
                    ->description('"Completing prerequisite unlocks dependent" edges between items of THIS SAME payload, each referenced by its 0-based position in declaration order (every plan\'s items, in order, for create_objective; this call\'s own `items` for add_items). create_objective, add_items.'),
                'plan_id' => $schema->integer()
                    ->description('Plan id. add_items (which plan gets the items); update_item (moves the item to this plan, same objective only); retire with target_type=plan.'),
                'item_key' => $schema->string()
                    ->description('Item key (e.g. "SALUD-7"). update_item (which item); retire, target_type=item.'),
                'two_minute_version' => $schema->string()
                    ->description('The item\'s new 2-minute version — never cleared. update_item.'),
                'description' => $schema->string()
                    ->description('The item\'s new description. update_item.'),
                'target_date' => $schema->string()
                    ->description('Y-m-d, or omit to leave unchanged. update_item.'),
                'position' => $schema->integer()
                    ->description('0-based position within the item\'s plan (ignored together with a plan move). update_item.'),
                'prerequisite_key' => $schema->string()
                    ->description('Key of the EXISTING item that opens the other (e.g. "SALUD-7"). add_dependency.'),
                'dependent_key' => $schema->string()
                    ->description('Key of the EXISTING item that gets unlocked (e.g. "SALUD-8"). add_dependency.'),
                'target_type' => $schema->string()
                    ->description('What to retire.')
                    ->enum(ProposalTargetType::class),
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
