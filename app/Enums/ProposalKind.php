<?php

namespace App\Enums;

/**
 * The AI proposal kinds this trimmed slice of Phase 8 supports. Anything
 * else is refused by `CreateProposal` before a row is even written.
 *
 * 2026-09-29 decision (supersedes the old R17 minor/major split for
 * structural operations): the AI creates and edits structure directly,
 * through its own MCP tools (`create-objective`, `create-plan`, `add-items`,
 * `update-item`, `add-dependency`, …) — `create_plan`, `create_objective`,
 * `add_items`, `update_item` and `add_dependency` are gone from here, one
 * clear path each. Only retiring or restoring an element stays a proposal
 * Marco accepts or rejects at `/ai/proposals`. A proposal row created under
 * the old kinds, if any, still renders on that screen (`AiProposal::kind` is
 * a plain string, not this enum) — it just cannot be decided anymore.
 */
enum ProposalKind: string
{
    case Retire = 'retire';
}
