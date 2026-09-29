<?php

namespace App\Actions\Support;

use RuntimeException;

/**
 * Thrown when an AI actor attempts a major operation it may not apply
 * directly. Phase 8 turns it into a pending proposal; until then the AI is
 * told to use `propose-change`.
 */
class MajorOperationRequiresProposal extends RuntimeException
{
    public function __construct(public readonly Operation $operation)
    {
        parent::__construct(
            "La operación «{$operation->value}» es mayor: la IA no la aplica sola. Usá propose-change para que Marco la acepte.",
        );
    }
}
