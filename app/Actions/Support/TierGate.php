<?php

namespace App\Actions\Support;

/**
 * The single place where AI tiers are enforced (design D3, D11). Owner
 * actors always pass. AI actors pass for minor operations; a major operation
 * is refused unless proposed via the `propose` MCP tool and accepted by
 * Marco at `/ai/proposals` (this trimmed slice has no permission grants).
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
