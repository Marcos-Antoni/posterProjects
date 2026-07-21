import {
    issuePriorityColors,
    issuePriorityIcons,
    issuePriorityLabels,
} from '@/lib/issue-meta';
import { cn } from '@/lib/utils';
import type { IssuePriority } from '@/types/models';

type IssuePriorityBadgeProps = {
    priority: IssuePriority;
};

/** Compact priority indicator: colored icon + Spanish label, JIRA-style. */
export function IssuePriorityBadge({ priority }: IssuePriorityBadgeProps) {
    const Icon = issuePriorityIcons[priority];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 text-xs font-medium',
                issuePriorityColors[priority],
            )}
        >
            <Icon className="size-3.5" />
            {issuePriorityLabels[priority]}
        </span>
    );
}
