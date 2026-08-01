<?php

namespace App\Http\Resources;

use App\Models\Comment;
use App\Models\Issue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Issue
 */
class IssueDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The same 15 fields as `IssueResource` (reused directly, never
     * duplicated) plus `description`, `reporter`, `parent`, `children`,
     * `comments` — field-for-field `IssueController::presentIssue()`
     * (`app/Http/Controllers/IssueController.php:130-176`), so the phone
     * and the web modal show one issue, not two dialects of it. Requires
     * the caller to have already `setRelation('project', $project)` on
     * this issue, its parent, and each child (same reason as
     * `IssueResource`).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new IssueResource($this->resource))->toArray($request),
            'description' => $this->description,
            'reporter' => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
            ],
            'parent' => $this->parent === null ? null : [
                'id' => $this->parent->id,
                'key' => $this->parent->key,
                'title' => $this->parent->title,
            ],
            'children' => $this->children->map(fn (Issue $child): array => [
                'id' => $child->id,
                'key' => $child->key,
                'title' => $child->title,
                'type' => $child->type,
                'board_column_id' => $child->board_column_id,
            ])->all(),
            'comments' => $this->comments->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
                'author' => [
                    'id' => $comment->author->id,
                    'name' => $comment->author->name,
                ],
            ])->all(),
        ];
    }
}
