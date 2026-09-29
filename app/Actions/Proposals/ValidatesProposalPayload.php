<?php

namespace App\Actions\Proposals;

use App\Enums\ControlZone;
use App\Enums\ProposalKind;
use App\Models\ControlPlan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Gates a proposal's payload SHAPE at propose time, one rule set per kind,
 * built from the same fields the web form requests validate (`StoreObjectiveRequest`,
 * `StorePlanRequest`, `StoreItemRequest`, `UpdateItemRequest`,
 * `ItemDependencyRequest`) so a malformed payload is rejected before a row is
 * even written, with the exact message Marco would see on the web. Existence
 * of the objective/plan/item a payload references is checked later, at
 * accept time (`ApplyProposal`) — same convention `retire` already uses.
 *
 * @throws ValidationException
 */
class ValidatesProposalPayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(ProposalKind $kind, array $payload): void
    {
        $validator = Validator::make($payload, $this->rulesFor($kind), $this->messagesFor($kind));

        $validator->after(function (ValidatorInstance $validator) use ($kind, $payload): void {
            $this->checkDependencyIndexes($kind, $payload, $validator);
        });

        $validator->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesFor(ProposalKind $kind): array
    {
        return match ($kind) {
            ProposalKind::CreateObjective => $this->createObjectiveRules(),
            ProposalKind::AddItems => $this->addItemsRules(),
            ProposalKind::UpdateItem => $this->updateItemRules(),
            ProposalKind::AddDependency => $this->addDependencyRules(),
            // create_plan and retire keep their existing shape-free gate
            // (only `kind` and `summary` are checked by `CreateProposal`);
            // their targets are resolved and guarded at accept time.
            ProposalKind::CreatePlan, ProposalKind::Retire => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function createObjectiveRules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^[A-Z]{2,10}$/', Rule::unique('objectives', 'key')],
            'title' => ['required', 'string', 'max:255'],
            'identity_statement' => ['nullable', 'string', 'max:255'],
            // The Control 5 points are always required here: `CreateObjective`
            // refuses an incomplete plan unconditionally (control-plan spec),
            // unlike a plan's, which may stay a draft.
            'outcome' => ['required', 'string', 'max:2000'],
            'deadline' => ['required', 'date_format:Y-m-d'],
            'metric' => ['required', 'array:name,target,current'],
            'metric.name' => ['required', 'string', 'max:255'],
            'metric.target' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'metric.current' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'risks' => ['required', 'array', 'min:1', 'max:10'],
            'risks.*' => ['required', 'string', 'max:500'],
            'contingency' => ['required', 'string', 'max:2000'],
            'control_map' => ['nullable', 'array', 'max:30'],
            'control_map.*.zone' => ['required', Rule::enum(ControlZone::class)],
            'control_map.*.text' => ['required', 'string', 'max:255'],
            'plans' => ['nullable', 'array', 'max:20'],
            'plans.*.title' => ['required', 'string', 'max:255'],
            'plans.*.level' => ['nullable', 'integer', 'min:1', 'max:99'],
            'plans.*.items' => ['nullable', 'array', 'max:100'],
            ...$this->itemRules('plans.*.items.*'),
            'dependencies' => ['nullable', 'array', 'max:200'],
            'dependencies.*.prerequisite' => ['required', 'integer', 'min:0'],
            'dependencies.*.dependent' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addItemsRules(): array
    {
        return [
            'objective_key' => ['required', 'string', 'max:10'],
            'plan_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            ...$this->itemRules('items.*'),
            'dependencies' => ['nullable', 'array', 'max:200'],
            'dependencies.*.prerequisite' => ['required', 'integer', 'min:0'],
            'dependencies.*.dependent' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function updateItemRules(): array
    {
        return [
            'item_key' => ['required', 'string', 'max:40'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'two_minute_version' => ['sometimes', 'required', 'string', 'max:255'],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'plan_id' => ['sometimes', 'required', 'integer'],
            'position' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addDependencyRules(): array
    {
        return [
            'prerequisite_key' => ['required', 'string', 'max:40'],
            'dependent_key' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * The `StoreItemRequest` shape, prefixed for a nested `items` collection.
     *
     * @return array<string, mixed>
     */
    private function itemRules(string $prefix): array
    {
        return [
            "{$prefix}.title" => ['required', 'string', 'max:255'],
            "{$prefix}.kind" => ['nullable', Rule::in(['task', 'milestone'])],
            "{$prefix}.two_minute_version" => ['required', 'string', 'max:255'],
            "{$prefix}.description" => ['nullable', 'string', 'max:5000'],
            "{$prefix}.target_date" => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messagesFor(ProposalKind $kind): array
    {
        return match ($kind) {
            ProposalKind::CreateObjective => [
                'key.required' => 'La clave del objetivo es obligatoria.',
                'key.regex' => 'La clave tiene de 2 a 10 letras mayúsculas, sin números ni espacios.',
                'key.unique' => 'Ya existe un objetivo con esa clave.',
                'title.required' => 'El título es obligatorio.',
                'outcome.required' => ControlPlan::MISSING_MESSAGES['outcome'],
                'deadline.required' => ControlPlan::MISSING_MESSAGES['deadline'],
                'deadline.date_format' => 'La fecha límite no es válida.',
                'metric.required' => ControlPlan::MISSING_MESSAGES['metric'],
                'metric.name.required' => ControlPlan::MISSING_MESSAGES['metric'],
                'metric.target.required' => ControlPlan::MISSING_MESSAGES['metric'],
                'risks.required' => ControlPlan::MISSING_MESSAGES['risks'],
                'risks.min' => ControlPlan::MISSING_MESSAGES['risks'],
                'contingency.required' => ControlPlan::MISSING_MESSAGES['contingency'],
                'plans.*.items.*.two_minute_version.required' => 'Falta la versión de 2 minutos de una tarea propuesta: es lo primero que se ve en Ahora.',
                'dependencies.*.prerequisite.required' => 'Cada dependencia necesita qué tarea la abre.',
                'dependencies.*.dependent.required' => 'Cada dependencia necesita qué tarea abre.',
            ],
            ProposalKind::AddItems => [
                'objective_key.required' => 'Elegí un objetivo.',
                'plan_id.required' => 'Elegí un plan.',
                'items.required' => 'La propuesta necesita al menos una tarea o hito.',
                'items.*.two_minute_version.required' => 'Falta la versión de 2 minutos de una tarea propuesta: es lo primero que se ve en Ahora.',
            ],
            ProposalKind::UpdateItem => [
                'item_key.required' => 'Falta la tarea o el hito a actualizar.',
                'two_minute_version.required' => 'La versión de 2 minutos no se puede borrar: es lo primero que ves en Ahora.',
            ],
            ProposalKind::AddDependency => [
                'prerequisite_key.required' => 'Elegí la tarea o el hito que abre la dependencia.',
                'dependent_key.required' => 'Elegí la tarea o el hito que queda abierto.',
            ],
            ProposalKind::CreatePlan, ProposalKind::Retire => [],
        };
    }

    /**
     * `dependencies` indexes are positions into the payload's own flattened
     * item list (create_objective: every plan's items, in order; add_items:
     * its own `items`) — out-of-range indexes are refused here, before the
     * proposal is even stored.
     *
     * @param  array<string, mixed>  $payload
     */
    private function checkDependencyIndexes(ProposalKind $kind, array $payload, ValidatorInstance $validator): void
    {
        $dependencies = $payload['dependencies'] ?? null;

        if (! is_array($dependencies) || $dependencies === []) {
            return;
        }

        $itemCount = match ($kind) {
            ProposalKind::CreateObjective => collect($payload['plans'] ?? [])
                ->sum(fn ($plan) => is_array($plan) && is_array($plan['items'] ?? null) ? count($plan['items']) : 0),
            ProposalKind::AddItems => is_array($payload['items'] ?? null) ? count($payload['items']) : 0,
            default => 0,
        };

        foreach ($dependencies as $index => $dependency) {
            $prerequisite = is_array($dependency) ? ($dependency['prerequisite'] ?? null) : null;
            $dependent = is_array($dependency) ? ($dependency['dependent'] ?? null) : null;

            if (! is_int($prerequisite) || ! is_int($dependent)) {
                continue; // already reported by the field rules above.
            }

            if ($prerequisite < 0 || $prerequisite >= $itemCount || $dependent < 0 || $dependent >= $itemCount) {
                $validator->errors()->add("dependencies.{$index}", 'Una dependencia apunta a una tarea que no está en esta propuesta.');
            } elseif ($prerequisite === $dependent) {
                $validator->errors()->add("dependencies.{$index}", 'Una tarea no puede depender de sí misma.');
            }
        }
    }
}
