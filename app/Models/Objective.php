<?php

namespace App\Models;

use App\Enums\ObjectiveState;
use App\Models\Concerns\HasRetirement;
use App\Models\Concerns\Retirable;
use Database\Factories\ObjectiveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The root of Marcos OS (projects spec): a keyed goal owned by the single
 * owner, holding plans, a Control 5-point plan and a control map.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $title
 * @property string|null $identity_statement
 * @property ObjectiveState $state
 * @property int $position
 * @property int $next_item_number
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $progress_done  selected by `scopeWithProgress()`
 * @property-read int|null $progress_total  selected by `scopeWithProgress()`
 */
#[Fillable(['user_id', 'key', 'title', 'identity_statement', 'state', 'position', 'closed_at'])]
class Objective extends Model implements Retirable
{
    /** @use HasFactory<ObjectiveFactory> */
    use HasFactory;

    use HasRetirement;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ObjectiveState::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Objectives are addressed by their key everywhere (`/objectives/SALUD`).
     * The `{objective}` binder in AppServiceProvider adds the owner scoping.
     */
    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The objective's plans in the owner's manual order.
     *
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Habits hanging from this objective (phase 4). Informational link: the
     * objective's lifecycle never archives, retires or modifies them.
     *
     * @return HasMany<Habit, $this>
     */
    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @return MorphOne<ControlPlan, $this>
     */
    public function controlPlan(): MorphOne
    {
        return $this->morphOne(ControlPlan::class, 'plannable');
    }

    /**
     * @return MorphMany<ControlMapEntry, $this>
     */
    public function controlMapEntries(): MorphMany
    {
        return $this->morphMany(ControlMapEntry::class, 'plannable')->orderBy('position')->orderBy('id');
    }

    /**
     * Only the objectives visible in navigation and the index: `active`,
     * in the owner's manual order (projects spec).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('state', ObjectiveState::Active)->orderBy('position')->orderBy('id');
    }

    /**
     * Select `progress_done` / `progress_total` over the non-retired items as
     * eager aggregates, so a list of objectives never counts per row.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithProgress(Builder $query): void
    {
        $query->withCount([
            'items as progress_total' => fn (Builder $items) => $items->whereNull('retired_at'),
            'items as progress_done' => fn (Builder $items) => $items->whereNull('retired_at')->whereNotNull('completed_at'),
        ]);
    }

    /**
     * Resolve an objective by key among the owner's VISIBLE objectives:
     * active and closed. Draft (still inside a negotiation) and retired
     * (only in the Retired view) are not found, like another owner's key.
     */
    public static function visibleForOwner(User $owner, string $key): ?self
    {
        return self::query()
            ->where('user_id', $owner->id)
            ->where('key', $key)
            ->whereIn('state', [ObjectiveState::Active, ObjectiveState::Closed])
            ->first();
    }

    /**
     * Atomically reserve the next item number of this objective.
     *
     * The objective row is locked inside a transaction and the counter is
     * re-read under the lock, so concurrent (or stale) callers never get the
     * same number. The counter only ever grows: a retired or moved item
     * keeps its number and it is never handed out again.
     */
    public function allocateNextItemNumber(): int
    {
        return DB::transaction(function (): int {
            /** @var self $locked */
            $locked = self::withRetired()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            $number = $locked->next_item_number;

            $locked->increment('next_item_number');

            $this->next_item_number = $locked->next_item_number;

            return $number;
        });
    }

    /**
     * The position that appends a new objective after the owner's others.
     */
    public static function nextPositionFor(User $owner): int
    {
        $max = self::query()->where('user_id', $owner->id)->max('position');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * Retired objectives are in the `retired` state (projects spec).
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainRetired(Builder $query, bool $retired): void
    {
        $query->where($this->qualifyColumn('state'), $retired ? '=' : '!=', ObjectiveState::Retired->value);
    }

    public function isRetired(): bool
    {
        return $this->state === ObjectiveState::Retired;
    }
}
