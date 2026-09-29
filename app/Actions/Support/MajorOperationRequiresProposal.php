<?php

namespace App\Actions\Support;

use RuntimeException;

/**
 * Thrown when an AI actor attempts a major operation it may not apply
 * directly. The `propose` MCP tool turns it into a pending proposal Marco
 * accepts or rejects from "Propuestas" (`/ai/proposals`).
 */
class MajorOperationRequiresProposal extends RuntimeException
{
    public function __construct(public readonly Operation $operation)
    {
        parent::__construct(
            "La operación «{$operation->value}» es mayor: la IA no la aplica sola. Usá el tool `propose` para que Marco la acepte desde /ai/proposals.",
        );
    }
}
