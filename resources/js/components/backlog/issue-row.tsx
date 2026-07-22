import { Link, router } from '@inertiajs/react';

import { show, update } from '@/actions/App/Http/Controllers/IssueController';
import { IssuePriorityBadge } from '@/components/board/issue-priority-badge';
import { IssueTypeIcon } from '@/components/board/issue-type-icon';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { BacklogIssue } from '@/types/backlog';

const BACKLOG_VALUE = 'backlog';

type IssueRowProps = {
    projectKey: string;
    issue: BacklogIssue;
    sprintOptions: Array<{ id: number; name: string }>;
};

/**
 * A single backlog/sprint row: type icon, key + title (links to the issue
 * modal deep link), priority, story points, and a "move to" select that
 * reassigns the issue's sprint. Reuses `PATCH projects.issues.update`
 * (`IssueController::update`) sending only `sprint_id` — no dedicated
 * backlog endpoint exists for this, per T-10's "Ajustes post-T-9". The
 * server always redirects back to the backlog page, so the whole list
 * (sprint sums, both sections) refreshes with the move already applied.
 */
export function IssueRow({ projectKey, issue, sprintOptions }: IssueRowProps) {
    function moveTo(value: string) {
        router.patch(
            update.url({ project: projectKey, issue: issue.id }),
            { sprint_id: value === BACKLOG_VALUE ? null : Number(value) },
            { preserveScroll: true },
        );
    }

    return (
        <div className="flex flex-col gap-2 rounded-lg border bg-card px-3 py-2 sm:flex-row sm:items-center sm:gap-2">
            <div className="flex min-w-0 items-center gap-2 sm:flex-1">
                <IssueTypeIcon
                    type={issue.type}
                    className="size-4 shrink-0 text-muted-foreground"
                />

                <Link
                    href={show.url({
                        project: projectKey,
                        issueKey: issue.key,
                    })}
                    className="flex min-w-0 flex-1 items-center gap-2 hover:underline"
                >
                    <span className="shrink-0 text-xs font-medium text-muted-foreground">
                        {issue.key}
                    </span>
                    <span className="truncate text-sm">{issue.title}</span>
                </Link>
            </div>

            {/* Below sm: its own row (nothing to compete with for width).
                At sm+: `sm:contents` drops this wrapper so the badges
                become direct flex items of the outer row again, in their
                original desktop position between the title and the
                select. */}
            <div className="flex items-center gap-2 sm:contents">
                <IssuePriorityBadge priority={issue.priority} />

                {issue.story_points !== null ? (
                    <span className="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
                        {issue.story_points} pts
                    </span>
                ) : null}
            </div>

            {/* Below sm: full-width on its own row, so `w-full` never has
                to compete with the badges above for horizontal space
                (that competition, inside a shared flex row, was the
                actual cause of the overflow — not `w-full` itself). */}
            <Select
                value={
                    issue.sprint_id === null
                        ? BACKLOG_VALUE
                        : String(issue.sprint_id)
                }
                onValueChange={moveTo}
            >
                <SelectTrigger className="h-11 w-full shrink-0 text-xs sm:w-40 md:h-7">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={BACKLOG_VALUE}>Backlog</SelectItem>
                    {sprintOptions.map((sprint) => (
                        <SelectItem key={sprint.id} value={String(sprint.id)}>
                            {sprint.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
