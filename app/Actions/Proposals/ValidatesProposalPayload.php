<?php

namespace App\Actions\Proposals;

use App\Enums\ProposalKind;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Gates a proposal's payload SHAPE at propose time. `retire` is the only
 * remaining kind (2026-09-29 decision: structural operations moved to direct
 * MCP tools) and keeps its existing shape-free gate — only `kind` and
 * `summary` are checked by `CreateProposal`; the target it names is resolved
 * and guarded later, at accept time (`ApplyProposal`).
 *
 * @throws ValidationException
 */
class ValidatesProposalPayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(ProposalKind $kind, array $payload): void
    {
        Validator::make($payload, $this->rulesFor($kind))->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesFor(ProposalKind $kind): array
    {
        return match ($kind) {
            ProposalKind::Retire => [],
        };
    }
}
