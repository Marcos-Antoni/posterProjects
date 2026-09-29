<?php

namespace App\Actions\Support;

use App\Models\AiAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8 audit writer (ai-operations spec "Every AI Change Is Audited"):
 * persists one `ai_audit_log` row per AI-applied change, replacing the
 * Phase 2 log-only placeholder (`LogAuditWriter`). Bound to `AuditWriter` in
 * `AppServiceProvider`; called by `DomainTransaction` inside the same
 * database transaction as the change, so a rollback undoes the audit row
 * too.
 */
class DatabaseAuditWriter implements AuditWriter
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(Actor $actor, Operation $operation, Model $target, array $before, array $after): void
    {
        AiAuditLog::create([
            'user_id' => $actor->user->id,
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
