<?php

namespace App\Actions\Plans;

use App\Actions\Support\Actor;
use App\Actions\Support\ControlPlanWriter;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Actions\Support\PlanStateRecalculator;
use App\Enums\PlanState;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

/**
 * Draft → active, only with a complete 5-point plan. Never automatic: the
 * next level is only ever suggested (control-plan spec).
 */
class ActivatePlan
{
    use GuardsObjectives;

    public function __construct(
        private DomainTransaction $transaction,
        private ControlPlanWriter $controlPlans,
        private PlanStateRecalculator $planStates,
    ) {}

    public function __invoke(Actor $actor, Plan $plan): Plan
    {
        $this->ensureOwned($actor, $plan->objective);
        $this->ensureWritable($plan->objective);

        if ($plan->state !== PlanState::Draft) {
            throw ValidationException::withMessages(['plan' => 'Solo un plan en borrador se activa.']);
        }

        return $this->transaction->run($actor, Operation::ActivatePlan, $plan, function () use ($plan): Plan {
            $this->controlPlans->ensureComplete($plan);

            $plan->update(['state' => PlanState::Active]);
            $this->planStates->recalculate($plan);

            return $plan->refresh();
        });
    }
}
