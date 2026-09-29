<?php

namespace App\Actions\Proposals;

use App\Actions\Support\AiTier;
use App\Enums\ProposalStatus;
use App\Models\AiAuditLog;
use App\Models\AiProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rejects a pending proposal (ai-operations spec): nothing is applied, the
 * proposal is marked `rejected`, and the decision is recorded in
 * `ai_audit_log` so it shows in the AI activity history too.
 */
class RejectProposal
{
    /**
     * @throws ModelNotFoundException<AiProposal>|ValidationException
     */
    public function __invoke(User $owner, AiProposal $proposal): AiProposal
    {
        if ($proposal->user_id !== $owner->id) {
            throw (new ModelNotFoundException)->setModel(AiProposal::class, [$proposal->id]);
        }

        if (! $proposal->isPending()) {
            throw ValidationException::withMessages(['proposal' => 'Esta propuesta ya fue decidida.']);
        }

        return DB::transaction(function () use ($owner, $proposal): AiProposal {
            $proposal->forceFill([
                'status' => ProposalStatus::Rejected,
                'decided_at' => now(),
            ])->save();

            AiAuditLog::create([
                'user_id' => $owner->id,
                'source' => $proposal->source,
                'tier' => AiTier::Major->value,
                'operation' => $proposal->kind,
                'target_type' => null,
                'target_id' => null,
                'before' => [],
                'after' => ['proposal_id' => $proposal->id, 'status' => ProposalStatus::Rejected->value],
                'proposal_id' => $proposal->id,
            ]);

            return $proposal;
        });
    }
}
