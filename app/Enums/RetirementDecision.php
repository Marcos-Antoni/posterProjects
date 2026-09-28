<?php

namespace App\Enums;

/**
 * What happens to a retired element's content (retirement spec): its
 * children or dependents go to a chosen target (`Move`), it is replaced by
 * two or more smaller elements written in the same flow (`Split`), or its
 * children are retired with it under the same reason (`ArchiveAsIs`).
 */
enum RetirementDecision: string
{
    case Move = 'move';
    case Split = 'split';
    case ArchiveAsIs = 'archive_as_is';

    public function label(): string
    {
        return match ($this) {
            self::Move => 'Mover',
            self::Split => 'Dividir',
            self::ArchiveAsIs => 'Archivar tal cual',
        };
    }
}
