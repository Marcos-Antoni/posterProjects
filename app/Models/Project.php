<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $next_issue_number
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['owner_id', 'key', 'name', 'description'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members');
    }

    /**
     * @return HasMany<Sprint, $this>
     */
    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }

    /**
     * @return HasMany<BoardColumn, $this>
     */
    public function boardColumns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position');
    }

    /**
     * @return HasMany<Label, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    /**
     * Atomically reserve the next sequential issue number for this project.
     *
     * Locks the project row inside a transaction so concurrent callers
     * never observe or reserve the same number.
     */
    public function allocateNextIssueNumber(): int
    {
        return DB::transaction(function (): int {
            $locked = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            $number = $locked->next_issue_number;

            $locked->increment('next_issue_number');

            $this->next_issue_number = $locked->next_issue_number;

            return $number;
        });
    }

    /**
     * Create a project with the default board columns (To Do, In Progress,
     * Done) and auto-attach its owner as a project member.
     *
     * Every entry point that creates a project (seeder, tests, future UI)
     * must go through this method instead of `Project::create()` directly,
     * so the board-columns and membership invariants always hold.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createWithDefaultColumns(array $attributes): self
    {
        return DB::transaction(function () use ($attributes): self {
            $project = self::create($attributes);

            $project->boardColumns()->createMany([
                ['name' => 'To Do', 'position' => 0],
                ['name' => 'In Progress', 'position' => 1],
                ['name' => 'Done', 'position' => 2],
            ]);

            $project->members()->attach($project->owner_id);

            return $project;
        });
    }
}
