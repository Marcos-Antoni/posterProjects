<?php

namespace App\Models;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $board_column_id
 * @property int|null $sprint_id
 * @property int|null $parent_id
 * @property int $number
 * @property IssueType $type
 * @property IssuePriority $priority
 * @property string $title
 * @property string|null $description
 * @property int|null $story_points
 * @property Carbon|null $due_date
 * @property int|null $assignee_id
 * @property int $reporter_id
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $key
 */
#[Fillable([
    'project_id',
    'board_column_id',
    'sprint_id',
    'parent_id',
    'number',
    'type',
    'priority',
    'title',
    'description',
    'story_points',
    'due_date',
    'assignee_id',
    'reporter_id',
    'position',
])]
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory;

    /**
     * The human-readable `key` accessor is fundamental to how issues are
     * identified everywhere in the UI (board cards, deep links, comments),
     * so it's always included when an issue is serialized to JSON.
     *
     * @var list<string>
     */
    protected $appends = ['key'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IssueType::class,
            'priority' => IssuePriority::class,
            'due_date' => 'date',
        ];
    }

    /**
     * The human-readable issue key, e.g. "PROJ-123". Not persisted.
     *
     * @return Attribute<string, never>
     */
    protected function key(): Attribute
    {
        return Attribute::make(
            get: fn (): string => sprintf('%s-%d', $this->project->key, $this->number),
        );
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<BoardColumn, $this>
     */
    public function boardColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class);
    }

    /**
     * @return BelongsTo<Sprint, $this>
     */
    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    /**
     * The parent issue, if any. Structural only — no depth/type guard yet.
     *
     * @return BelongsTo<Issue, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'issue_label');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * The next `position` value that appends an issue to the bottom of the
     * given column, scoped to the same sprint filter (or backlog when
     * `$sprintId` is `null`) so quick-added cards land where they're
     * currently visible on the board.
     */
    public static function nextPositionInColumn(int $boardColumnId, ?int $sprintId): int
    {
        $maxPosition = self::scopedToColumnAndSprint($boardColumnId, $sprintId)->max('position');

        return $maxPosition === null ? 0 : $maxPosition + 1;
    }

    /**
     * Inserts `$movingIssue` into the given `(board_column_id, sprint_id)`
     * scope at `$targetPosition` (0-indexed, clamped to the scope's
     * bounds), renumbering every other issue in the scope sequentially
     * around it. Drives drag-and-drop: works for both a same-column
     * reorder and a cross-column move — `$movingIssue`'s own current row
     * is excluded from the sibling list before the insert, so it doesn't
     * matter whether it currently belongs to this scope or not. Never
     * touches `sprint_id` — the caller is responsible for closing the gap
     * in the origin scope first when moving across columns (see
     * {@see closeGapInScope()}).
     */
    public static function reorderScope(int $boardColumnId, ?int $sprintId, self $movingIssue, int $targetPosition): void
    {
        $siblings = self::scopedToColumnAndSprint($boardColumnId, $sprintId)
            ->where('id', '!=', $movingIssue->id)
            ->orderBy('position')
            ->lockForUpdate()
            ->get();

        $targetPosition = max(0, min($targetPosition, $siblings->count()));

        $ordered = $siblings->all();
        array_splice($ordered, $targetPosition, 0, [$movingIssue]);

        foreach ($ordered as $index => $issue) {
            /** @var self $issue */
            if ($issue->is($movingIssue)) {
                $movingIssue->board_column_id = $boardColumnId;
                $movingIssue->position = $index;
                $movingIssue->save();

                continue;
            }

            if ($issue->position !== $index) {
                $issue->update(['position' => $index]);
            }
        }
    }

    /**
     * Resequences every remaining issue in the given
     * `(board_column_id, sprint_id)` scope to consecutive positions
     * (0, 1, 2, ...), excluding `$excludeIssueId` — the issue that just
     * left this scope. Closes the gap left behind by a drag-and-drop move
     * to a different column.
     */
    public static function closeGapInScope(int $boardColumnId, ?int $sprintId, int $excludeIssueId): void
    {
        $issues = self::scopedToColumnAndSprint($boardColumnId, $sprintId)
            ->where('id', '!=', $excludeIssueId)
            ->orderBy('position')
            ->lockForUpdate()
            ->get();

        foreach ($issues->values() as $index => $issue) {
            /** @var self $issue */
            if ($issue->position !== $index) {
                $issue->update(['position' => $index]);
            }
        }
    }

    /**
     * Resolves a human-readable issue key ("PROJ-123") against a project
     * already known to the caller. Splits on the LAST "-" (project keys
     * never contain one — see `StoreProjectRequest`'s regex), so this is
     * safe even if a future key format changes. Returns `null` (never
     * throws or aborts) whenever the key is malformed, its prefix doesn't
     * match `$project`, or no issue has that number in this project —
     * callers decide how to react (e.g. `abort_if(..., 404)` on the web,
     * an MCP error response for tools).
     */
    public static function resolveByKey(Project $project, string $issueKey): ?self
    {
        $lastDashPosition = strrpos($issueKey, '-');

        if ($lastDashPosition === false) {
            return null;
        }

        $prefix = substr($issueKey, 0, $lastDashPosition);
        $numberPart = substr($issueKey, $lastDashPosition + 1);

        if ($prefix !== $project->key || $numberPart === '' || ! ctype_digit($numberPart)) {
            return null;
        }

        return self::query()
            ->where('project_id', $project->id)
            ->where('number', (int) $numberPart)
            ->first();
    }

    /**
     * @return Builder<self>
     */
    private static function scopedToColumnAndSprint(int $boardColumnId, ?int $sprintId)
    {
        return self::query()
            ->where('board_column_id', $boardColumnId)
            ->when(
                $sprintId === null,
                fn ($query) => $query->whereNull('sprint_id'),
                fn ($query) => $query->where('sprint_id', $sprintId),
            );
    }
}
