<?php

namespace App\Models\Scopes;

use App\Models\Concerns\Retirable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Retired elements are hidden everywhere (retirement spec, design D8): this
 * global scope removes them from every query of a retirable model, including
 * relations and route model binding. Only the Retired view and the restore
 * actions look past it, through the `withRetired()` / `onlyRetired()` builder
 * macros (same shape as Laravel's soft deletes).
 *
 * @implements Scope<Model>
 */
class NotRetired implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if ($model instanceof Retirable) {
            $model->constrainRetired($builder, false);
        }
    }

    /**
     * @param  Builder<Model>  $builder
     */
    public function extend(Builder $builder): void
    {
        $scope = $this;

        $builder->macro('withRetired', fn (Builder $builder): Builder => $builder->withoutGlobalScope($scope));

        $builder->macro('onlyRetired', function (Builder $builder) use ($scope): Builder {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($scope);

            if ($model instanceof Retirable) {
                $model->constrainRetired($builder, true);
            }

            return $builder;
        });
    }
}
