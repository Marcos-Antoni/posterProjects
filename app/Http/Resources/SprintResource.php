<?php

namespace App\Http\Resources;

use App\Models\Sprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sprint
 */
class SprintResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Pinned to exactly these 8 fields — no other sprint attribute (e.g.
     * `project_id`, `created_at`) may be exposed here. `start_date` /
     * `end_date` ship as `toDateString()` ("2026-08-01"), NOT ISO8601 —
     * they are `date` casts over `date` columns, and emitting a timestamp
     * would misreport the day to a client in a different UTC offset.
     * `issues_count` MUST come from the eager `withCount('issues')`
     * aggregate on the query, never `$this->issues()->count()`, or the N+1
     * guard would fail.
     *
     * `state` is computed here, not on the model or in the query (design
     * D-4): `Sprint` has no status column. Anchored to `today()` in the
     * application timezone (UTC, `config/app.php:68`) — deliberately NOT
     * the UTC-6 habit-day rule. Both bounds are inclusive by construction:
     * `future` is checked first, so a sprint whose bounds equal `today()`
     * falls through to `active`. Byte-identical to
     * `BoardController::resolveActiveSprint`'s
     * `start_date->lte($today) && end_date->gte($today)`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = today();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'goal' => $this->goal,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'state' => match (true) {
                $this->start_date->gt($today) => 'future',
                $this->end_date->lt($today) => 'completed',
                default => 'active',
            },
            'issues_count' => $this->issues_count,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
