<?php

namespace App\Actions\Plans;

use App\Actions\Support\Actor;
use App\Actions\Support\ControlPlanWriter;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Enums\PlanState;
use App\Models\Objective;
use App\Models\Plan;

/**
 * Appends a plan at the end of its objective (plans spec). It is saved as a
 * draft unless `activate` is requested, which requires the complete 5-point
 * plan (control-plan spec).
 */
class CreatePlan
{
    use GuardsObjectives;

    public function __construct(
        private DomainTransaction $transaction,
        private ControlPlanWriter $controlPlans,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`StorePlanRequest`)
     */
    public function __invoke(Actor $actor, Objective $objective, array $data): Plan
    {
        $this->ensureOwned($actor, $objective);
        $this->ensureWritable($objective);

        $plan = new Plan([
            'objective_id' => $objective->id,
            'title' => trim((string) $data['title']),
            'level' => $data['level'] ?? null,
            'state' => PlanState::Draft,
        ]);

        return $this->transaction->run($actor, Operation::CreatePlan, $plan, function () use ($objective, $plan, $data): Plan {
            $plan->position = Plan::nextPositionIn($objective);
            $plan->save();

            $this->controlPlans->write($plan, $data);

            if (($data['activate'] ?? false) === true) {
                $this->controlPlans->ensureComplete($plan);
                $plan->update(['state' => PlanState::Active]);
            }

            return $plan->load(['controlPlan', 'controlMapEntries']);
        });
    }
}
