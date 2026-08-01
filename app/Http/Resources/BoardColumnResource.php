<?php

namespace App\Http\Resources;

use App\Models\BoardColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoardColumn
 */
class BoardColumnResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Pinned to exactly these 4 fields — no other board-column attribute
     * (e.g. `project_id`, `created_at`, `issues_count`) may be exposed here.
     * `project_id` is omitted because the URL already carries the project;
     * `issues_count` is omitted deliberately (design D-2): a column's count
     * is meaningless without a sprint filter.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
