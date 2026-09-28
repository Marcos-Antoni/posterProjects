<?php

namespace App\Actions\Objectives;

use App\Actions\Support\Actor;
use App\Actions\Support\ControlPlanWriter;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Enums\ObjectiveState;
use App\Models\Objective;
use Illuminate\Validation\ValidationException;

/**
 * Draft → active, only with a complete 5-point plan (projects spec).
 */
class ActivateObjective
{
    use GuardsObjectives;

    public function __construct(
        private DomainTransaction $transaction,
        private ControlPlanWriter $controlPlans,
    ) {}

    public function __invoke(Actor $actor, Objective $objective): Objective
    {
        $this->ensureOwned($actor, $objective);

        if (! $objective->state->canTransitionTo(ObjectiveState::Active) || $objective->state !== ObjectiveState::Draft) {
            throw ValidationException::withMessages(['objective' => 'Solo un borrador se activa; este objetivo ya está '.mb_strtolower($objective->state->label()).'.']);
        }

        return $this->transaction->run($actor, Operation::ActivateObjective, $objective, function () use ($objective): Objective {
            $this->controlPlans->ensureComplete($objective);

            $objective->update(['state' => ObjectiveState::Active]);

            return $objective;
        });
    }
}
