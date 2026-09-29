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
    case CreateObjective = 'create_objective';
    case AddItems = 'add_items';
    case UpdateItem = 'update_item';
    case AddDependency = 'add_dependency';
}
