import { issueTypeIcons, issueTypeLabels } from '@/lib/issue-meta';
import type { IssueType } from '@/types/models';

type IssueTypeIconProps = {
    type: IssueType;
    className?: string;
};

/** Small icon representing an issue's type (epic/story/task/bug), JIRA-style. */
export function IssueTypeIcon({ type, className }: IssueTypeIconProps) {
    const Icon = issueTypeIcons[type];

    return (
        <span title={issueTypeLabels[type]}>
            <Icon className={className ?? 'size-4'} />
        </span>
    );
}
