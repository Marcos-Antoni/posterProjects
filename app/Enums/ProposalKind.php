<?php

namespace App\Enums;

/**
 * The AI proposal kinds this trimmed slice of Phase 8 supports. Anything
 * else is refused by `CreateProposal` before a row is even written.
 */
enum ProposalKind: string
{
    case CreatePlan = 'create_plan';
    case Retire = 'retire';
}
