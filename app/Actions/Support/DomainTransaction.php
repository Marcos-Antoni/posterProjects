<?php

namespace App\Actions\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The envelope every domain action runs its change in (design D3): the tier
 * gate first, then one database transaction holding the change and, for an
 * AI actor, the audit entry with the target's before/after snapshot. A
 * failure rolls everything back, audit included.
 */
class DomainTransaction
{
    public function __construct(
        private TierGate $gate,
        private AuditWriter $audit,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     */
    public function run(Actor $actor, Operation $operation, Model $target, Closure $change): mixed
    {
        $this->gate->authorize($actor, $operation);

        return DB::transaction(function () use ($actor, $operation, $target, $change): mixed {
            $before = $target->exists ? $target->fresh()?->attributesToArray() ?? [] : [];

            $result = $change();

            if ($actor->isAi()) {
                $after = $target->exists ? $target->fresh()?->attributesToArray() ?? [] : [];

                $this->audit->record($actor, $operation, $target, $before, $after);
            }

            return $result;
        });
    }
}
