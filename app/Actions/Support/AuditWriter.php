<?php

namespace App\Actions\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Records an AI-applied change with its before/after snapshot (ai-operations
 * spec: "Every AI Change Is Audited"). Called by `DomainTransaction` inside
 * the same database transaction as the change.
 */
interface AuditWriter
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(Actor $actor, Operation $operation, Model $target, array $before, array $after): void;
}
