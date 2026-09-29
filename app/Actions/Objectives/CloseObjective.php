<?php

namespace App\Actions\Objectives;

use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Enums\ObjectiveState;
use App\Enums\ReviewKind;
use App\Models\Habit;
use App\Models\Objective;
use App\Models\Review;
use Illuminate\Validation\ValidationException;

/**
 * Closes an objective (reviews spec "Closing An Objective Requires A
 * Learning Review And A Habit Decision"): three answers plus an explicit
 * keep-or-retire decision for every active habit linked to it, saved
 * atomically. The objective's own active task, if any, is released so Now
 * never keeps showing a task of a closed objective (now-focus).
 */
class CloseObjective
{
    use GuardsObjectives;

    public const MISSING_ANSWER = 'Contestá las tres preguntas, aunque sea una línea.';

    public const MISSING_DECISIONS = 'Decidí qué pasa con cada hábito vinculado.';

    public function __construct(
        private DomainTransaction $transaction,
        private RetireElement $retireElement,
    ) {}

    /**
     * @param  array{what_learned: string, what_repeat: string, what_change: string}  $answers
     * @param  list<array{habit_id: int, decision: string, reason?: string|null, relink_objective_id?: int|null}>  $habitDecisions
     */
    public function __invoke(Actor $actor, Objective $objective, array $answers, array $habitDecisions): Review
    {
        $this->ensureOwned($actor, $objective);

        if ($objective->state !== ObjectiveState::Active) {
            throw ValidationException::withMessages(['objective' => 'Solo se cierra un objetivo activo.']);
        }

        foreach (['what_learned', 'what_repeat', 'what_change'] as $field) {
            if (trim($answers[$field]) === '') {
                throw ValidationException::withMessages([$field => self::MISSING_ANSWER]);
            }
        }

        $linkedHabits = $objective->habits()->get()->keyBy('id');
        $decisionsByHabit = collect($habitDecisions)->keyBy('habit_id');

        if ($linkedHabits->keys()->diff($decisionsByHabit->keys())->isNotEmpty() || $decisionsByHabit->keys()->diff($linkedHabits->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['habits' => self::MISSING_DECISIONS]);
        }

        $review = new Review([
            'user_id' => $actor->user->id,
            'kind' => ReviewKind::Objective,
            'objective_id' => $objective->id,
            'answers' => [
                'what_learned' => trim($answers['what_learned']),
                'what_repeat' => trim($answers['what_repeat']),
                'what_change' => trim($answers['what_change']),
            ],
        ]);

        return $this->transaction->run($actor, Operation::CloseObjective, $objective, function () use ($actor, $objective, $review, $linkedHabits, $habitDecisions): Review {
            $review->save();

            foreach ($habitDecisions as $decision) {
                $habit = $linkedHabits->get($decision['habit_id']);

                if ($habit === null) {
                    continue;
                }

                $this->applyHabitDecision($actor, $objective, $habit, $decision);
            }

            $this->releaseActiveItem($objective);

            $objective->update(['state' => ObjectiveState::Closed, 'closed_at' => now()]);

            return $review;
        });
    }

    /**
     * @param  array{habit_id: int, decision: string, reason?: string|null, relink_objective_id?: int|null}  $decision
     */
    private function applyHabitDecision(Actor $actor, Objective $objective, Habit $habit, array $decision): void
    {
        if ($decision['decision'] === 'retire') {
            ($this->retireElement)($actor, $habit, (string) ($decision['reason'] ?? ''));

            return;
        }

        $relinkId = $decision['relink_objective_id'] ?? null;

        if ($relinkId === null) {
            return;
        }

        $target = Objective::query()
            ->where('user_id', $actor->user->id)
            ->where('state', ObjectiveState::Active->value)
            ->whereKeyNot($objective->id)
            ->find($relinkId);

        if ($target === null) {
            throw ValidationException::withMessages(['relink_objective_id' => 'Ese objetivo no existe o no está activo.']);
        }

        $habit->update(['objective_id' => $target->id, 'plan_id' => null]);
    }

    /**
     * Now never keeps showing a task of a closed objective (P3 NEED, design
     * D6): the closing objective's active item, if any, stops being active
     * and its open focus session closes.
     */
    private function releaseActiveItem(Objective $objective): void
    {
        $item = $objective->items()->where('is_active', true)->whereNull('completed_at')->first();

        if ($item === null) {
            return;
        }

        $item->update(['is_active' => false]);
        $item->focusSessions()->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'objective-closed']);
    }
}
