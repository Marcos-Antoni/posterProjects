<?php

namespace App\Actions\Plans;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Actions\Support\Reorderer;
use App\Models\Plan;

/**
 * Moves a plan one place up (-1) or down (+1) among its objective's plans,
 * renumbering positions 0..n-1. Past either end it is a no-op.
 */
class MovePlan
{
    use GuardsObjectives;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Plan $plan, int $direction): Plan
    {
        $this->ensureOwned($actor, $plan->objective);
        $this->ensureWritable($plan->objective);

        return $this->transaction->run($actor, Operation::ReorderPlan, $plan, function () use ($plan, $direction): Plan {
            $siblings = Plan::query()
                ->where('objective_id', $plan->objective_id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            Reorderer::move($siblings, $plan, $direction);

            return $plan->refresh();
        });
    }
}
