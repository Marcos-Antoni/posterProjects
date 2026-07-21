<?php

namespace App\Models;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
}
