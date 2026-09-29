<?php

namespace App\Models;

use Database\Factories\AiAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per AI-applied change (ai-operations spec "Every AI Change Is
 * Audited"): a minor operation applied directly (written by
 * `DatabaseAuditWriter`, bound to `AuditWriter` and called from
 * `DomainTransaction` for an AI actor) or a proposal Marco decided
 * (`AcceptProposal`/`RejectProposal`). `target_type` is the short morph
 * alias from AppServiceProvider's morph map, not the FQCN.
 *
 * @property int $id
 * @property int $user_id
 * @property string $source
 * @property string $tier
 * @property string $operation
 * @property string|null $target_type
 * @property int|null $target_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property int|null $proposal_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'source', 'tier', 'operation', 'target_type', 'target_id', 'before', 'after', 'proposal_id'])]
class AiAuditLog extends Model
{
    /** @use HasFactory<AiAuditLogFactory> */
    use HasFactory;

    protected $table = 'ai_audit_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AiProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AiProposal::class, 'proposal_id');
    }
}
