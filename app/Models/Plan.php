<?php

namespace App\Models;

use App\Enums\PlanState;
use App\Models\Concerns\HasRetirement;
use App\Models\Concerns\Retirable;
use App\Models\Scopes\NotRetired;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/**
 * The middle layer between an objective and its items (plans spec), with
 * its own 5-point plan, control map, optional progression level and manual
 * position among the objective's plans.
 *
 * @property int $id
 * @property int $objective_id
 * @property string $title
 * @property PlanState $state
 * @property int|null $level
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['objective_id', 'title', 'state', 'level', 'position'])]
class Plan extends Model implements Retirable
{
    /** @use HasFactory<PlanFactory> */
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
            'state' => PlanState::class,
            'level' => 'integer',
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
     * Retired plans are in the `retired` state (plans spec).
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainRetired(Builder $query, bool $retired): void
    {
        $query->where($this->qualifyColumn('state'), $retired ? '=' : '!=', PlanState::Retired->value);
    }

    public function isRetired(): bool
    {
        return $this->state === PlanState::Retired;
    }

    /**
     * Habits hanging from this plan (phase 4). Informational link: the
     * plan's lifecycle never archives, retires or modifies them.
     *
     * @return HasMany<Habit, $this>
     */
    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    /**
     * The plan's items in manual order.
     *
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class)->orderBy('position')->orderBy('id');
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
     * Retired plans are hidden everywhere outside the Retired view, so a
     * route never resolves one (the `{plan}` segment answers 404).
     *
     * @param  Builder<self>|Relation<self, *, *>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Builder<self>|Relation<self, *, *>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->where('plans.state', '!=', PlanState::Retired->value);
    }

    /**
     * The position that appends a plan at the end of its objective.
     */
    public static function nextPositionIn(Objective $objective): int
    {
        $max = self::query()->where('objective_id', $objective->id)->max('position');

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
