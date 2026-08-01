<?php

namespace App\Http\Resources;

use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Label
 */
class LabelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Pinned to exactly these 4 fields — no other label attribute (e.g.
     * `project_id`, `created_at`, nested `issues`, `color`) may be exposed
     * here. `project_id` is omitted because the URL already carries the
     * project (design D-2). `issues_count` MUST come from the eager
     * `withCount('issues')` aggregate the controller applies — never a
     * per-row `->issues()->count()`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'issues_count' => $this->issues_count,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
