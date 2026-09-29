<?php

namespace App\Actions\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 audit writer: one structured log line per AI-applied change. Phase 8
 * (`ai_audit_entries`) replaces the container binding with a database writer
 * shown in the AI activity screen; the call sites do not change.
 */
class LogAuditWriter implements AuditWriter
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(Actor $actor, Operation $operation, Model $target, array $before, array $after): void
    {
        Log::info('ai.audit', [
            'source' => $actor->kind->value,
            'tier' => $operation->tier()->value,
            'operation' => $operation->value,
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'before' => $before,
            'after' => $after,
        ]);
    }
}
