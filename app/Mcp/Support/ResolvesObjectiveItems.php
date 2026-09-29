<?php

namespace App\Mcp\Support;

use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Laravel\Mcp\Response;

/**
 * Scoped lookups for the objective, plan and item tools (mcp-server spec
 * "Scoped Lookups Reject Cross-Objective Identifiers"): the objective among
 * the owner's visible ones (active or closed), and the plan/item only inside
 * that objective. Anything else is "not found", exactly like the web 404.
 */
trait ResolvesObjectiveItems
{
    protected function objectiveOrError(User $owner, mixed $key): Objective|Response
    {
        $objective = is_string($key) ? Objective::visibleForOwner($owner, $key) : null;

        return $objective ?? Response::error('Objective not found: '.(is_scalar($key) ? $key : ''));
    }

    protected function itemOrError(Objective $objective, mixed $key): Item|Response
    {
        $item = is_string($key) ? Item::resolveByKey($objective, $key) : null;

        return $item ?? Response::error('Item not found: '.(is_scalar($key) ? $key : ''));
    }

    protected function planOrError(Objective $objective, mixed $planId): Plan|Response
    {
        $plan = is_numeric($planId)
            ? Plan::query()->where('objective_id', $objective->id)->whereKey((int) $planId)->first()
            : null;

        return $plan ?? Response::error('Plan not found: '.(is_scalar($planId) ? $planId : ''));
    }
}
