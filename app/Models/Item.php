<?php

namespace App\Models;

use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Models\Concerns\HasRetirement;
use App\Models\Concerns\Retirable;
use App\Models\Scopes\NotRetired;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A checkable task or milestone of a plan (issues spec). Its state is
 * DERIVED, never stored (design D2): see `scopeWithState()` (SQL, one
 * `EXISTS` subquery, used by every list) and `deriveState()` (PHP, same
 * precedence, used for a single item).
 *
 * @property int $id
 * @property int $user_id the objective's owner, filled by a database trigger (phase 3)
 * @property int $objective_id
 * @property int $plan_id
 * @property int $number
 * @property ItemKind $kind
 * @property string $title
 * @property string|null $description
 * @property string $two_minute_version
 * @property Carbon|null $target_date
 * @property bool $is_active
 * @property Carbon|null $completed_at
 * @property Carbon|null $retired_at
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $key
 * @property-read ItemState $state
 */
#[Fillable([
    'objective_id',
    'plan_id',
    'number',
    'kind',
    'title',
    'description',
    'two_minute_version',
    'target_date',
    'is_active',
    'completed_at',
    'retired_at',
    'position',
])]
class Item extends Model implements Retirable
{
    /** @use HasFactory<ItemFactory> */
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
            'kind' => ItemKind::class,
            'target_date' => 'date',
            'is_active' => 'boolean',
            'completed_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Objective, $this>
     */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class)->withoutGlobalScope(NotRetired::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withoutGlobalScope(NotRetired::class);
    }

    /**
     * Retired items carry `retired_at` (retirement spec).
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainRetired(Builder $query, bool $retired): void
    {
        $retired
            ? $query->whereNotNull($this->qualifyColumn('retired_at'))
            : $query->whereNull($this->qualifyColumn('retired_at'));
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * The items that must be done before this one ("La abrió").
     *
     * @return BelongsToMany<Item, $this>
     */
    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'item_dependencies', 'dependent_id', 'prerequisite_id')
            ->withTimestamps();
    }

    /**
     * The items this one unlocks ("Abre al terminar").
     *
     * @return BelongsToMany<Item, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'item_dependencies', 'prerequisite_id', 'dependent_id')
            ->withTimestamps();
    }

    /**
     * Replaced 2-minute versions, newest first.
     *
     * @return HasMany<ItemTwoMinuteHistory, $this>
     */
    public function twoMinuteHistory(): HasMany
    {
        return $this->hasMany(ItemTwoMinuteHistory::class)->orderByDesc('replaced_at')->orderByDesc('id');
    }

    /**
     * @return HasOne<MilestoneEvidence, $this>
     */
    public function evidence(): HasOne
    {
        return $this->hasOne(MilestoneEvidence::class);
    }

    /**
     * @return HasMany<FocusSession, $this>
     */
    public function focusSessions(): HasMany
    {
        return $this->hasMany(FocusSession::class)->orderByDesc('started_at');
    }

    /**
     * The public key, e.g. "SALUD-7" (objective key + number). Not persisted.
     * Callers that serialize many items set the `objective` relation first
     * (or eager load it) so this never lazy-loads per row.
     *
     * @return Attribute<string, never>
     */
    protected function key(): Attribute
    {
        return Attribute::make(
            get: fn (): string => sprintf('%s-%d', $this->objective->key, $this->number),
        );
    }

    /**
     * The derived state: the value selected by `scopeWithState()` when the
     * query used it, otherwise computed with `deriveState()`.
     *
     * @return Attribute<ItemState, never>
     */
    protected function state(): Attribute
    {
        return Attribute::make(
            get: function (): ItemState {
                $derived = $this->getAttributeFromArray('derived_state');

                return is_string($derived) ? ItemState::from($derived) : $this->deriveState();
            },
        );
    }

    /**
     * Select the derived state as `derived_state` with a single correlated
     * `EXISTS` subquery, so any list stays O(1) in queries. Precedence:
     * retired, done, active, locked (a non-retired prerequisite not done),
     * available.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithState(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('items.*');
        }

        $query->selectRaw(<<<'SQL'
            CASE
                WHEN items.retired_at IS NOT NULL THEN 'retired'
                WHEN items.completed_at IS NOT NULL THEN 'done'
                WHEN items.is_active THEN 'active'
                WHEN EXISTS (
                    SELECT 1
                    FROM item_dependencies AS state_edges
                    INNER JOIN items AS state_prerequisites ON state_prerequisites.id = state_edges.prerequisite_id
                    WHERE state_edges.dependent_id = items.id
                        AND state_prerequisites.retired_at IS NULL
                        AND state_prerequisites.completed_at IS NULL
                ) THEN 'locked'
                ELSE 'available'
            END AS derived_state
            SQL);
    }

    /**
     * Only `available` items: not retired, not done, not active and with no
     * open (non-retired, not done) prerequisite — the same predicate as the
     * `available` branch of `scopeWithState()`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->whereNull('items.retired_at')
            ->whereNull('items.completed_at')
            ->where('items.is_active', false)
            ->whereNotExists(function (QueryBuilder $edges): void {
                $edges->selectRaw('1')
                    ->from('item_dependencies as available_edges')
                    ->join('items as available_prerequisites', 'available_prerequisites.id', '=', 'available_edges.prerequisite_id')
                    ->whereColumn('available_edges.dependent_id', 'items.id')
                    ->whereNull('available_prerequisites.retired_at')
                    ->whereNull('available_prerequisites.completed_at');
            });
    }

    /**
     * The same derivation as `scopeWithState()`, in PHP, for one item.
     */
    public function deriveState(): ItemState
    {
        return match (true) {
            $this->retired_at !== null => ItemState::Retired,
            $this->completed_at !== null => ItemState::Done,
            $this->is_active => ItemState::Active,
            $this->hasOpenPrerequisite() => ItemState::Locked,
            default => ItemState::Available,
        };
    }

    /**
     * Whether a non-retired prerequisite is still not done.
     */
    public function hasOpenPrerequisite(): bool
    {
        return DB::table('item_dependencies')
            ->join('items as prerequisites', 'prerequisites.id', '=', 'item_dependencies.prerequisite_id')
            ->where('item_dependencies.dependent_id', $this->id)
            ->whereNull('prerequisites.retired_at')
            ->whereNull('prerequisites.completed_at')
            ->exists();
    }

    /**
     * Resolve a public key ("SALUD-7") inside an objective already known to
     * the caller. Returns `null` (never throws) for every malformed form —
     * no dash, empty or non-numeric suffix, a prefix that is not the
     * objective's key, an unknown number — and for a retired item, so every
     * caller can fail closed with the same 404 (issues spec).
     */
    public static function resolveByKey(Objective $objective, string $itemKey): ?self
    {
        $lastDash = strrpos($itemKey, '-');

        if ($lastDash === false) {
            return null;
        }

        $prefix = substr($itemKey, 0, $lastDash);
        $number = substr($itemKey, $lastDash + 1);

        if ($prefix !== $objective->key || $number === '' || ! ctype_digit($number) || strlen($number) > 9) {
            return null;
        }

        $item = self::query()
            ->where('objective_id', $objective->id)
            ->where('number', (int) $number)
            ->whereNull('retired_at')
            ->first();

        $item?->setRelation('objective', $objective);

        return $item;
    }

    /**
     * Resolve a public key from ANY of the owner's visible objectives (the
     * prefix names the objective): used for cross-objective dependencies.
     * Returns null for anything malformed, foreign or retired.
     */
    public static function resolveForOwner(User $owner, string $itemKey): ?self
    {
        $lastDash = strrpos($itemKey, '-');

        if ($lastDash === false) {
            return null;
        }

        $objective = Objective::visibleForOwner($owner, substr($itemKey, 0, $lastDash));

        return $objective === null ? null : self::resolveByKey($objective, $itemKey);
    }

    /**
     * The position that appends an item at the end of a plan.
     */
    public static function nextPositionIn(Plan $plan): int
    {
        $max = self::query()->where('plan_id', $plan->id)->max('position');

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
