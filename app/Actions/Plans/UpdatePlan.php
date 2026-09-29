<?php

namespace App\Actions\Plans;

use App\Actions\Support\Actor;
use App\Actions\Support\ControlPlanWriter;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Enums\PlanState;
use App\Models\Plan;

/**
 * Edits a plan's title, level and 5-point plan. An active (or done) plan can
 * never lose one of its five points.
 */
class UpdatePlan
{
    use GuardsObjectives;

    public function __construct(
        private DomainTransaction $transaction,
        private ControlPlanWriter $controlPlans,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`UpdatePlanRequest`)
     */
    public function __invoke(Actor $actor, Plan $plan, array $data): Plan
    {
        $this->ensureOwned($actor, $plan->objective);
        $this->ensureWritable($plan->objective);
        $this->ensurePlanWritable($plan);

        return $this->transaction->run($actor, Operation::UpdatePlan, $plan, function () use ($plan, $data): Plan {
            if (array_key_exists('title', $data)) {
                $plan->title = trim((string) $data['title']);
            }

            if (array_key_exists('level', $data)) {
                $plan->level = $data['level'];
            }

            $plan->save();

            $this->controlPlans->write($plan, $data);

            if (in_array($plan->state, [PlanState::Active, PlanState::Done], true)) {
                $this->controlPlans->ensureComplete($plan);
            }

            return $plan->load('controlPlan');
        });
    }
}
