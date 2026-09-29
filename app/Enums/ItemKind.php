<?php

namespace App\Enums;

/**
 * An item is a task (completed with a single check) or a milestone
 * (completed with recorded evidence).
 */
enum ItemKind: string
{
    case Task = 'task';
    case Milestone = 'milestone';

    public function label(): string
    {
        return match ($this) {
            self::Task => 'Tarea',
            self::Milestone => 'Hito',
        };
    }
}
