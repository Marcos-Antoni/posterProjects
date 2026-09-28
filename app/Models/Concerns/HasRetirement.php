<?php

namespace App\Models\Concerns;

use App\Models\Retirement;
use App\Models\Scopes\NotRetired;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A model that is retired, never deleted (retirement spec): hidden by the
 * `NotRetired` global scope and carrying its retirement history. The model
 * implements `Retirable` (`constrainRetired()`, `isRetired()`).
 *
 * @method static Builder<static> withRetired()
 * @method static Builder<static> onlyRetired()
 */
trait HasRetirement
{
    public static function bootHasRetirement(): void
    {
        static::addGlobalScope(new NotRetired);
    }

    /**
     * Every retirement of this element, newest first (history kept on restore).
     *
     * @return MorphMany<Retirement, $this>
     */
    public function retirements(): MorphMany
    {
        return $this->morphMany(Retirement::class, 'retirable')->orderByDesc('retired_at')->orderByDesc('id');
    }
}
