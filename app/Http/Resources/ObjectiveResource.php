<?php

namespace App\Http\Resources;

use App\Models\Objective;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The pinned objective shape (api-projects spec "Pinned Objective Shape"):
 * exactly `id, key, title, identity_statement, state, outcome, deadline,
 * metric {name, target, current}, progress {done, total}, updated_at`.
 *
 * Requires `controlPlan` to be eager loaded and the `progress_done` /
 * `progress_total` aggregates selected (`Objective::scopeWithProgress()`),
 * so serializing a list never issues a query per objective.
 *
 * @mixin Objective
 */
class ObjectiveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $controlPlan = $this->controlPlan;

        return [
            'id' => $this->id,
            'key' => $this->key,
            'title' => $this->title,
            'identity_statement' => $this->identity_statement,
            'state' => $this->state->value,
            'outcome' => $controlPlan?->outcome,
            'deadline' => $controlPlan?->deadline?->toDateString(),
            'metric' => [
                'name' => $controlPlan?->metric_name,
                'target' => $controlPlan?->metric_target === null ? null : (float) $controlPlan->metric_target,
                'current' => $controlPlan?->metric_current === null ? null : (float) $controlPlan->metric_current,
            ],
            'progress' => [
                'done' => (int) $this->progress_done,
                'total' => (int) $this->progress_total,
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
