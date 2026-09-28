<?php

namespace App\Actions\Support;

use App\Enums\PlanState;
use App\Models\Plan;

/**
 * Keeps a plan's `done` state honest (plans spec): an active plan becomes
 * done when it holds at least one non-retired item and all of them are done;
 * a done plan with an open item returns to active. Draft and retired plans
 * are never touched.
 */
class PlanStateRecalculator
{
    public function recalculate(Plan $plan): void
    {
        if (! in_array($plan->state, [PlanState::Active, PlanState::Done], true)) {
            return;
        }

        $open = $plan->items()->reorder()->whereNull('retired_at')->whereNull('completed_at')->count();
        $done = $plan->items()->reorder()->whereNull('retired_at')->whereNotNull('completed_at')->count();

        $state = ($open === 0 && $done > 0) ? PlanState::Done : PlanState::Active;

        if ($plan->state !== $state) {
            $plan->update(['state' => $state]);
        }
    }
}
