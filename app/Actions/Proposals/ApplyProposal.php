<?php

namespace App\Actions\Proposals;

use App\Actions\Items\AddItem;
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
    ) {}

    /**
     * @throws ModelNotFoundException<Model>|ValidationException
     */
    public function __invoke(Actor $actor, AiProposal $proposal): Model
    {
        return match (ProposalKind::tryFrom($proposal->kind)) {
            ProposalKind::CreatePlan => $this->applyCreatePlan($actor, $proposal->payload),
            ProposalKind::Retire => $this->applyRetire($actor, $proposal->payload),
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
