<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A model that is retired, never deleted (retirement spec). Implemented
 * through the `HasRetirement` trait.
 */
interface Retirable
{
    /**
     * Constrain a query to the non-retired rows (`$retired = false`) or to
     * the retired ones only (`$retired = true`).
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainRetired(Builder $query, bool $retired): void;

    /**
     * Whether this element is currently retired.
     */
    public function isRetired(): bool;
}
