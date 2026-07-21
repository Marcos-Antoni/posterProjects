import type { LucideIcon } from 'lucide-react';
import {
    Bookmark,
    Bug,
    CheckSquare,
    ChevronDown,
    ChevronsDown,
    ChevronsUp,
    ChevronUp,
    Equal,
    Rocket,
} from 'lucide-react';

import type { IssuePriority, IssueType } from '@/types/models';

/** Spanish UI copy and icons for the domain enums — shared by the board and (later) the issue modal. */

export const issueTypeIcons: Record<IssueType, LucideIcon> = {
    epic: Rocket,
    story: Bookmark,
    task: CheckSquare,
    bug: Bug,
};

export const issueTypeLabels: Record<IssueType, string> = {
    epic: 'Épica',
    story: 'Historia',
    task: 'Tarea',
    bug: 'Bug',
};

export const issuePriorityIcons: Record<IssuePriority, LucideIcon> = {
    1: ChevronsUp,
    2: ChevronUp,
    3: Equal,
    4: ChevronDown,
    5: ChevronsDown,
};

export const issuePriorityLabels: Record<IssuePriority, string> = {
    1: 'Más alta',
    2: 'Alta',
    3: 'Media',
    4: 'Baja',
    5: 'Más baja',
};

export const issuePriorityColors: Record<IssuePriority, string> = {
    1: 'text-red-600 dark:text-red-400',
    2: 'text-orange-600 dark:text-orange-400',
    3: 'text-yellow-600 dark:text-yellow-400',
    4: 'text-blue-600 dark:text-blue-400',
    5: 'text-muted-foreground',
};
