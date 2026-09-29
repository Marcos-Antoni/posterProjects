<?php

namespace App\Actions\Proposals;

use App\Actions\Support\Actor;
use App\Actions\Support\AiTier;
use App\Enums\ProposalStatus;
use App\Models\AiAuditLog;
use App\Models\AiProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accepts a pending proposal (ai-operations spec): applies it exactly as
 * stored via `ApplyProposal`, marks it `accepted`, and records the decision
 * in `ai_audit_log` — all inside one transaction, so a failed apply leaves
 * the proposal pending and nothing audited.
 */
class AcceptProposal
{
    public function __construct(private ApplyProposal $apply) {}

    /**
     * @throws ModelNotFoundException<AiProposal>|ValidationException
     */
    public function __invoke(User $owner, AiProposal $proposal): AiProposal
    {
        $this->guardOwnedAndPending($owner, $proposal);

        return DB::transaction(function () use ($owner, $proposal): AiProposal {
            $target = ($this->apply)(Actor::ownerWeb($owner), $proposal);

            $proposal->forceFill([
                'status' => ProposalStatus::Accepted,
                'decided_at' => now(),
            ])->save();

            AiAuditLog::create([
                'user_id' => $owner->id,
                'source' => $proposal->source,
                'tier' => AiTier::Major->value,
                'operation' => $proposal->kind,
                'target_type' => $target->getMorphClass(),
                'target_id' => $target->getKey(),
                'before' => [],
                'after' => ['proposal_id' => $proposal->id, 'status' => ProposalStatus::Accepted->value],
                'proposal_id' => $proposal->id,
            ]);

            return $proposal;
        });
    }

    /**
     * @throws ModelNotFoundException<AiProposal>|ValidationException
     */
    private function guardOwnedAndPending(User $owner, AiProposal $proposal): void
    {
        if ($proposal->user_id !== $owner->id) {
            throw (new ModelNotFoundException)->setModel(AiProposal::class, [$proposal->id]);
        }

        if (! $proposal->isPending()) {
            throw ValidationException::withMessages(['proposal' => 'Esta propuesta ya fue decidida.']);
        }
    }
}
