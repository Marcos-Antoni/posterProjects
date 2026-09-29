<?php

namespace App\Actions\Support;

/**
 * The single place where AI tiers are enforced (design D3, D11). Owner
 * actors always pass. AI actors pass for minor operations; a major operation
 * is refused until Phase 8 adds proposals and permission grants.
 */
class TierGate
{
    /**
     * @throws MajorOperationRequiresProposal
     */
    public function authorize(Actor $actor, Operation $operation): void
    {
        if ($actor->isOwner()) {
            return;
        }

        if ($operation->tier() === AiTier::Minor) {
            return;
        }

        throw new MajorOperationRequiresProposal($operation);
    }
}
