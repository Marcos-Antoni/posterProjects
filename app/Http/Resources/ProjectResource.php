<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Pinned to exactly these 7 fields — no other project attribute (e.g.
     * `owner_id`, `next_issue_number`, `deleted_at`, `created_at`) may be
     * exposed. `issues_count` MUST come from the eager-loaded
     * `withCount('issues')` aggregate on the query, never
     * `$this->issues()->count()`, or the N+1 guard would fail.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'issues_count' => $this->issues_count,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived' => $this->trashed(),
        ];
    }
}
