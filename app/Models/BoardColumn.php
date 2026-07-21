<?php

namespace App\Models;

use Database\Factories\BoardColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'name', 'position'])]
class BoardColumn extends Model
{
    /** @use HasFactory<BoardColumnFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class)->orderBy('position');
    }

    /**
     * The next `position` value that appends a new column to the end of
     * the project's board.
     */
    public static function nextPositionInProject(int $projectId): int
    {
        $maxPosition = self::query()->where('project_id', $projectId)->max('position');

        return $maxPosition === null ? 0 : $maxPosition + 1;
    }

    /**
     * Moves the given column to `$targetPosition` (0-indexed, clamped to
     * the project's bounds) and renumbers every other column in the
     * project sequentially around it.
     *
     * `position` has a `unique(project_id, position)` index at the DB
     * level (unlike `issues.position`, which has none), so a naive
     * "reassign final positions one row at a time" loop would violate it
     * mid-loop whenever a target position is still held by another row
     * awaiting its own update. This dodges that the standard way: shift
     * every column in the project far out of the live range first
     * (`+1000`, a value no real project will ever have that many columns
     * to reach), then assign the final 0..n-1 positions — by then no row
     * can still be holding a target value.
     *
     * The final assignment goes through the query builder
     * (`self::query()->whereKey(...)->update(...)`) rather than
     * `$model->update()` on purpose: the loaded model instances still
     * hold their pre-shift `position` in memory, so whenever a column's
     * final target index happens to equal that stale in-memory value,
     * Eloquent's dirty-checking would silently skip the write and leave
     * the row stuck at its `+1000` shifted value.
     */
    public static function reorderColumns(int $projectId, int $movingColumnId, int $targetPosition): void
    {
        $columns = self::query()
            ->where('project_id', $projectId)
            ->orderBy('position')
            ->lockForUpdate()
            ->get();

        $moving = $columns->firstWhere('id', $movingColumnId);

        if ($moving === null) {
            return;
        }

        $siblings = $columns->reject(fn (self $column): bool => $column->is($moving))->values();

        $targetPosition = max(0, min($targetPosition, $siblings->count()));

        $ordered = $siblings->all();
        array_splice($ordered, $targetPosition, 0, [$moving]);

        self::query()->where('project_id', $projectId)->increment('position', 1000);

        foreach ($ordered as $index => $column) {
            /** @var self $column */
            self::query()->whereKey($column->id)->update(['position' => $index]);
        }
    }

    /**
     * Resequences a project's board columns to consecutive positions
     * (0, 1, 2, ...) in their current relative order. Called after
     * deleting a column so the remaining ones have no gaps. Uses the same
     * shift-then-reassign technique as {@see reorderColumns()} (including
     * writing through the query builder, not the model) to avoid
     * violating the unique `(project_id, position)` index mid-loop.
     */
    public static function reindexPositions(int $projectId): void
    {
        $columns = self::query()
            ->where('project_id', $projectId)
            ->orderBy('position')
            ->lockForUpdate()
            ->get();

        self::query()->where('project_id', $projectId)->increment('position', 1000);

        foreach ($columns->values() as $index => $column) {
            /** @var self $column */
            self::query()->whereKey($column->id)->update(['position' => $index]);
        }
    }
}
