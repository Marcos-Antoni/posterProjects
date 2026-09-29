<?php

namespace App\Enums;

/**
 * What a `retire` proposal's payload targets (ai-operations spec, trimmed
 * slice). `ApplyProposal::resolveRetireTarget()` resolves the matching
 * Eloquent model, owned by the deciding user, before handing it to the
 * existing `RetireElement` action.
 */
enum ProposalTargetType: string
{
    case Objective = 'objective';
    case Item = 'item';
    case Plan = 'plan';
    case Habit = 'habit';
}
