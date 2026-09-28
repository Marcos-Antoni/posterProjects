<?php

namespace App\Models;

use App\Enums\RetirableKind;
use App\Enums\RetirementDecision;
use App\Models\Scopes\NotRetired;
use Database\Factories\RetirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One retirement of one element (retirement spec, design D8): the reason
 * the owner wrote, the content decision, the element's state before, and —
 * once restored — when. Rows are never deleted: a restore stamps
 * `restored_at` and the row stays as history. A child retired together with
 * its plan or objective points at that retirement through `parent_id`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $retirable_type
 * @property int $retirable_id
 * @property RetirableKind $kind
 * @property int|null $objective_id
 * @property int|null $parent_id
 * @property string $reason
 * @property RetirementDecision $decision
 * @property array<string, mixed>|null $decision_payload
 * @property string|null $prior_state
 * @property Carbon $retired_at
 * @property Carbon|null $restored_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'retirable_type',
    'retirable_id',
    'kind',
    'objective_id',
    'parent_id',
    'reason',
    'decision',
    'decision_payload',
    'prior_state',
    'retired_at',
    'restored_at',
])]
class Retirement extends Model
{
    /** @use HasFactory<RetirementFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RetirableKind::class,
            'decision' => RetirementDecision::class,
            'decision_payload' => 'array',
            'retired_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The objective the element belonged to when it was retired (null for
     * habits without one and for captures). Reaches retired objectives too.
     *
     * @return BelongsTo<Objective, $this>
     */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class)->withoutGlobalScope(NotRetired::class);
    }

    /**
     * The retirement that carried this one (its plan's or objective's).
     *
     * @return BelongsTo<Retirement, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The retirements carried by this one (archive as-is cascade).
     *
     * @return HasMany<Retirement, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Retirements still in effect (not restored).
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('restored_at');
    }

    /**
     * The retired element itself, reached past the `NotRetired` scope.
     */
    public function element(): ?Model
    {
        $class = Model::getActualClassNameForMorph($this->retirable_type);

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->withoutGlobalScope(NotRetired::class)->find($this->retirable_id);
    }
}
