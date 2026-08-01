<?php

namespace App\Http\Resources;

use App\Models\Issue;
use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Issue
 */
class IssueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Pinned to exactly these 15 fields — no other issue attribute (e.g.
     * `project_id`, `description`, `created_at`) may be exposed here; the
     * detail-only fields live on `IssueDetailResource`. `key` reads
     * `$this->project` via the accessor, so the caller MUST have already
     * called `setRelation('project', $project)` on every row, or this
     * silently issues one lazy-loaded query per issue.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'number' => $this->number,
            'title' => $this->title,
            'type' => $this->type->value,
            'priority' => $this->priority->value,
            'story_points' => $this->story_points,
            'due_date' => $this->due_date?->toDateString(),
            'board_column_id' => $this->board_column_id,
            'sprint_id' => $this->sprint_id,
            'parent_id' => $this->parent_id,
            'position' => $this->position,
            'assignee' => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ],
            'labels' => $this->labels->map(fn (Label $label): array => [
                'id' => $label->id,
                'name' => $label->name,
            ])->all(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
