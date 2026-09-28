<?php

namespace App\Actions\Retirement;

use App\Enums\ObjectiveState;
use App\Enums\RetirableKind;
use App\Enums\RetirementDecision;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Retiring an objective (retirement + projects specs):
 *
 * - archive as-is: its plans and their open items are retired with it.
 * - move: its open plans go to another active objective; their items get
 *   new numbers there (numbers are per objective and never reused, so the
 *   old ones stay burnt in this objective).
 *
 * Splitting an objective is not offered: new objectives are born only
 * through a negotiation or a complete 5-point plan (projects spec). Habits
 * linked to it are never retired by this (habits spec).
 *
 * @implements RetirementHandler<Objective>
 */
class ObjectiveRetirementHandler implements RetirementHandler
{
    public function __construct(private PlanRetirementHandler $plans) {}

    public function kind(Model $element): RetirableKind
    {
        return RetirableKind::Objective;
    }

    public function ownerId(Model $element): int
    {
        return $element->user_id;
    }

    public function objectiveId(Model $element): ?int
    {
        return $element->id;
    }

    public function title(Model $element): string
    {
        return $element->title;
    }

    public function priorState(Model $element): string
    {
        return $element->state->value;
    }

    public function decisions(Model $element): array
    {
        return [RetirementDecision::Move, RetirementDecision::ArchiveAsIs];
    }

    public function defaultDecision(Model $element): ?RetirementDecision
    {
        return $element->plans()->exists() ? null : RetirementDecision::ArchiveAsIs;
    }

    public function guard(Model $element): void
    {
        if (! $element->state->canTransitionTo(ObjectiveState::Retired)) {
            throw ValidationException::withMessages(['objective' => 'El objetivo está cerrado: reabrilo si querés retirarlo.']);
        }
    }

    public function itemIds(Model $element): array
    {
        return array_values($element->items()->pluck('id')->all());
    }

    public function retire(Model $element, string $reason, RetirementDecision $decision, array $payload, RetirementRecorder $recorder): RetirementOutcome
    {
        if ($decision === RetirementDecision::Move) {
            return $this->move($element, $reason, $payload, $recorder);
        }

        $plans = $element->plans()->get();
        $retirement = $recorder->record($element, $reason, RetirementDecision::ArchiveAsIs, ['plans' => $plans->count()]);
        $cascaded = 0;

        foreach ($plans as $plan) {
            $cascaded += 1 + $this->plans->retireWithContent($plan, $reason, RetirementDecision::ArchiveAsIs, ['with_parent' => true], $recorder, $retirement)->cascaded;
        }

        $element->update(['state' => ObjectiveState::Retired]);

        return new RetirementOutcome($retirement, cascaded: $cascaded);
    }

    public function restoreBlocker(Model $element): ?string
    {
        return null;
    }

    public function restore(Model $element, Retirement $retirement): void
    {
        $prior = ObjectiveState::tryFrom((string) $retirement->prior_state);

        $element->update(['state' => $prior === ObjectiveState::Draft ? ObjectiveState::Draft : ObjectiveState::Active]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function move(Objective $objective, string $reason, array $payload, RetirementRecorder $recorder): RetirementOutcome
    {
        $plans = $objective->plans()->get();

        if ($plans->isEmpty()) {
            throw ValidationException::withMessages(['decision' => 'No hay nada que mover: el objetivo no tiene planes. Elegí archivar tal cual.']);
        }

        $target = is_string($payload['target'] ?? null)
            ? Objective::query()
                ->where('user_id', $objective->user_id)
                ->where('state', ObjectiveState::Active)
                ->whereKeyNot($objective->id)
                ->where('key', $payload['target'])
                ->first()
            : null;

        if ($target === null) {
            throw ValidationException::withMessages(['target' => 'Elegí otro objetivo activo tuyo.']);
        }

        $items = 0;

        foreach ($plans as $plan) {
            $plan->update(['objective_id' => $target->id, 'position' => Plan::nextPositionIn($target)]);

            foreach (Item::withRetired()->where('plan_id', $plan->id)->orderBy('number')->get() as $item) {
                $item->update(['objective_id' => $target->id, 'number' => $target->allocateNextItemNumber()]);
                $items++;
            }
        }

        $retirement = $recorder->record($objective, $reason, RetirementDecision::Move, [
            'target' => ['type' => 'objective', 'id' => $target->id, 'key' => $target->key, 'title' => $target->title],
            'moved' => $plans->count(),
            'items' => $items,
        ]);

        $objective->update(['state' => ObjectiveState::Retired]);

        return new RetirementOutcome($retirement, moved: $plans->count());
    }
}
