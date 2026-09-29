<?php

namespace App\Actions\Proposals;

use App\Actions\Items\AddDependency;
use App\Actions\Items\AddItem;
use App\Actions\Items\UpdateItem as UpdateItemAction;
use App\Actions\Objectives\CreateObjective;
use App\Actions\Plans\CreatePlan;
use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Enums\ProposalKind;
use App\Enums\ProposalTargetType;
use App\Enums\RetirementDecision;
use App\Models\AiProposal;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Applies an accepted proposal exactly as stored, reusing the same domain
 * actions the web and MCP already call (ai-operations spec: "Accepting a
 * proposal ... applies exactly as proposed"). Called with an owner actor
 * (`Actor::ownerWeb`): Marco himself accepted it from the web, so the tier
 * gate's major-operation restriction (which exists for an AI actor, not the
 * owner) does not apply — this trimmed slice has no permission grants.
 *
 * Returns the primary affected model (the new plan, or the retired element)
 * so the caller can record it on the accepted proposal's audit row.
 */
class ApplyProposal
{
    public function __construct(
        private CreatePlan $createPlan,
        private AddItem $addItem,
        private RetireElement $retireElement,
        private CreateObjective $createObjective,
        private UpdateItemAction $updateItem,
        private AddDependency $addDependency,
    ) {}

    /**
     * @throws ModelNotFoundException<Model>|ValidationException
     */
    public function __invoke(Actor $actor, AiProposal $proposal): Model
    {
        return match (ProposalKind::tryFrom($proposal->kind)) {
            ProposalKind::CreatePlan => $this->applyCreatePlan($actor, $proposal->payload),
            ProposalKind::Retire => $this->applyRetire($actor, $proposal->payload),
            ProposalKind::CreateObjective => $this->applyCreateObjective($actor, $proposal->payload),
            ProposalKind::AddItems => $this->applyAddItems($actor, $proposal->payload),
            ProposalKind::UpdateItem => $this->applyUpdateItem($actor, $proposal->payload),
            ProposalKind::AddDependency => $this->applyAddDependency($actor, $proposal->payload),
            null => throw ValidationException::withMessages(['kind' => "Tipo de propuesta no soportado: «{$proposal->kind}»."]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ModelNotFoundException<Objective>|ValidationException
     */
    private function applyCreatePlan(Actor $actor, array $payload): Plan
    {
        $objective = $this->findObjective($actor, (string) ($payload['objective_key'] ?? ''));

        $plan = ($this->createPlan)($actor, $objective, [
            'title' => (string) ($payload['title'] ?? ''),
            'level' => $payload['level'] ?? null,
        ]);

        foreach ((array) ($payload['items'] ?? []) as $item) {
            ($this->addItem)($actor, $plan, (array) $item);
        }

        return $plan;
    }

    /**
     * A new objective, its complete 5-point control plan, and any nested
     * plans/items/dependencies — one transaction, entirely from EXISTING
     * domain actions (`CreateObjective`, `CreatePlan`, `AddItem`,
     * `AddDependency`). Items are created first, in payload order, into a
     * flat list; `dependencies` then resolves by index into that list, so an
     * edge may point either way regardless of declaration order.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    private function applyCreateObjective(Actor $actor, array $payload): Objective
    {
        $objective = ($this->createObjective)($actor, $payload);

        $items = [];

        foreach ((array) ($payload['plans'] ?? []) as $planData) {
            $planData = (array) $planData;
            $plan = ($this->createPlan)($actor, $objective, $planData);

            foreach ((array) ($planData['items'] ?? []) as $itemData) {
                $items[] = ($this->addItem)($actor, $plan, (array) $itemData);
            }
        }

        $this->applyDependencies($actor, $items, (array) ($payload['dependencies'] ?? []));

        return $objective;
    }

    /**
     * Items (and optional dependencies between them) added to an existing
     * plan, reusing `AddItem`/`AddDependency` exactly like `create_objective`.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ModelNotFoundException<Model>|ValidationException
     */
    private function applyAddItems(Actor $actor, array $payload): Plan
    {
        $plan = $this->findPlan($actor, (string) ($payload['objective_key'] ?? ''), (int) ($payload['plan_id'] ?? 0));

        $items = [];

        foreach ((array) ($payload['items'] ?? []) as $itemData) {
            $items[] = ($this->addItem)($actor, $plan, (array) $itemData);
        }

        $this->applyDependencies($actor, $items, (array) ($payload['dependencies'] ?? []));

        return $plan;
    }

    /**
     * Title, 2-minute version, target date or a move to another plan of the
     * same objective — only the fields the proposal actually included
     * (`UpdateItem`'s own "sometimes" semantics).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ModelNotFoundException<Item>|ValidationException
     */
    private function applyUpdateItem(Actor $actor, array $payload): Item
    {
        $item = $this->findItemByKey($actor, (string) ($payload['item_key'] ?? ''));

        $data = Arr::only($payload, ['title', 'description', 'two_minute_version', 'target_date', 'plan_id', 'position']);

        return ($this->updateItem)($actor, $item, $data);
    }

    /**
     * "Completing A unlocks B" between two EXISTING items, resolved by their
     * public key (may cross objectives, like the web's own dependency form).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ModelNotFoundException<Item>|ValidationException
     */
    private function applyAddDependency(Actor $actor, array $payload): Item
    {
        $prerequisite = $this->findItemByKey($actor, (string) ($payload['prerequisite_key'] ?? ''));
        $dependent = $this->findItemByKey($actor, (string) ($payload['dependent_key'] ?? ''));

        ($this->addDependency)($actor, $prerequisite, $dependent);

        return $dependent->refresh();
    }

    /**
     * Resolves each `{prerequisite, dependent}` pair by index into `$items`
     * (the flat list just created, in declaration order) and links them.
     * `ValidatesProposalPayload` already refused an out-of-range index at
     * propose time — this is a defensive re-check, never expected to trip.
     *
     * @param  list<Item>  $items
     * @param  list<array<string, mixed>>  $dependencies
     *
     * @throws ValidationException
     */
    private function applyDependencies(Actor $actor, array $items, array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            $prerequisite = $items[(int) ($dependency['prerequisite'] ?? -1)] ?? null;
            $dependent = $items[(int) ($dependency['dependent'] ?? -1)] ?? null;

            if ($prerequisite === null || $dependent === null) {
                throw ValidationException::withMessages(['dependencies' => 'Una dependencia apunta a una tarea que no está en esta propuesta.']);
            }

            ($this->addDependency)($actor, $prerequisite, $dependent);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ModelNotFoundException<Model>|ValidationException
     */
    private function applyRetire(Actor $actor, array $payload): Model
    {
        $element = $this->resolveRetireTarget($actor, $payload);

        $reason = (string) ($payload['reason'] ?? '');
        $decisionValue = $payload['decision'] ?? null;
        $decision = is_string($decisionValue) ? RetirementDecision::tryFrom($decisionValue) : null;

        if ($decisionValue !== null && $decision === null) {
            throw ValidationException::withMessages(['decision' => "Decisión no válida: «{$decisionValue}»."]);
        }

        // `RetireElement` mutates `$element` in place (its handler sets
        // `retired_at` on the same instance) and its `RetirementResult`
        // holds the history row, not the element — so the element we
        // resolved above is already the right thing to hand back for the
        // audit row.
        ($this->retireElement)($actor, $element, $reason, $decision, (array) ($payload['decision_payload'] ?? []));

        return $element;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ModelNotFoundException<Model>|ValidationException
     */
    private function resolveRetireTarget(Actor $actor, array $payload): Model
    {
        $targetType = ProposalTargetType::tryFrom((string) ($payload['target_type'] ?? ''));

        if ($targetType === null) {
            throw ValidationException::withMessages(['target_type' => 'Falta o no es válido el tipo de elemento a retirar.']);
        }

        return match ($targetType) {
            ProposalTargetType::Objective => $this->findObjective($actor, (string) ($payload['objective_key'] ?? '')),
            ProposalTargetType::Item => $this->findItem($actor, (string) ($payload['objective_key'] ?? ''), (string) ($payload['item_key'] ?? '')),
            ProposalTargetType::Plan => $this->findPlan($actor, (string) ($payload['objective_key'] ?? ''), (int) ($payload['plan_id'] ?? 0)),
            ProposalTargetType::Habit => $this->findHabit($actor, (int) ($payload['habit_id'] ?? 0)),
        };
    }

    /**
     * @throws ModelNotFoundException<Objective>
     */
    private function findObjective(Actor $actor, string $key): Objective
    {
        $objective = Objective::query()->where('user_id', $actor->user->id)->where('key', $key)->first();

        if ($objective === null) {
            throw (new ModelNotFoundException)->setModel(Objective::class, [$key]);
        }

        return $objective;
    }

    /**
     * @throws ModelNotFoundException<Item>|ModelNotFoundException<Objective>
     */
    private function findItem(Actor $actor, string $objectiveKey, string $itemKey): Item
    {
        $objective = $this->findObjective($actor, $objectiveKey);
        $item = Item::resolveByKey($objective, $itemKey);

        if ($item === null) {
            throw (new ModelNotFoundException)->setModel(Item::class, [$itemKey]);
        }

        return $item;
    }

    /**
     * An item by its public key alone (e.g. "SALUD-7"), among the actor's
     * owned objectives — used by `update_item` and `add_dependency`, whose
     * payload names one item directly instead of an objective_key/item_key
     * pair.
     *
     * @throws ModelNotFoundException<Item>
     */
    private function findItemByKey(Actor $actor, string $itemKey): Item
    {
        $item = Item::resolveForOwner($actor->user, $itemKey);

        if ($item === null) {
            throw (new ModelNotFoundException)->setModel(Item::class, [$itemKey]);
        }

        return $item;
    }

    /**
     * @throws ModelNotFoundException<Plan>|ModelNotFoundException<Objective>
     */
    private function findPlan(Actor $actor, string $objectiveKey, int $planId): Plan
    {
        $objective = $this->findObjective($actor, $objectiveKey);
        $plan = Plan::query()->where('objective_id', $objective->id)->whereKey($planId)->first();

        if ($plan === null) {
            throw (new ModelNotFoundException)->setModel(Plan::class, [$planId]);
        }

        return $plan;
    }

    /**
     * @throws ModelNotFoundException<Habit>
     */
    private function findHabit(Actor $actor, int $habitId): Habit
    {
        $habit = Habit::query()->where('user_id', $actor->user->id)->whereKey($habitId)->first();

        if ($habit === null) {
            throw (new ModelNotFoundException)->setModel(Habit::class, [$habitId]);
        }

        return $habit;
    }
}
