<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Database\Factories\AiProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A major AI operation Marco has not yet decided (ai-operations spec,
 * trimmed Phase 8 slice): the AI's exact intended operation
 * (`kind`/`payload`), a human-readable Spanish `summary`, and its `status`
 * until Marco accepts or rejects it from the "Propuestas" web screen.
 * Nothing in `payload` is applied until accepted (`ApplyProposal`).
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property array<string, mixed> $payload
 * @property string $summary
 * @property ProposalStatus $status
 * @property string $source
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'kind', 'payload', 'summary', 'status', 'source', 'decided_at'])]
class AiProposal extends Model
{
    /** @use HasFactory<AiProposalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => ProposalStatus::class,
            'decided_at' => 'datetime',
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
     * @return HasMany<AiAuditLog, $this>
     */
    public function auditEntries(): HasMany
    {
        return $this->hasMany(AiAuditLog::class, 'proposal_id');
    }

    public function isPending(): bool
    {
        return $this->status === ProposalStatus::Pending;
    }
}
