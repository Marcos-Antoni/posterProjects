import { Link } from '@inertiajs/react';
import {
    eachDayOfInterval,
    endOfMonth,
    endOfWeek,
    format,
    isSameMonth,
    isToday,
    startOfMonth,
    startOfWeek,
} from 'date-fns';

import { show } from '@/actions/App/Http/Controllers/IssueController';
import { IssueTypeIcon } from '@/components/board/issue-type-icon';
import { cn } from '@/lib/utils';
import type { CalendarIssue } from '@/types/calendar';

const WEEKDAY_LABELS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

type CalendarGridProps = {
    monthStart: Date;
    issues: CalendarIssue[];
};

/**
 * The month's grid, weeks starting on Monday — full leading/trailing days
 * from the adjacent months are shown (muted) so every week row has 7 days.
 * Issues are grouped by their `due_date` string and matched against each
 * cell's own `yyyy-MM-dd` key, so no timezone-sensitive date parsing is
 * needed (see `edit-form.tsx`'s `parseIsoDate` for why parsing a
 * date-only string via `new Date(string)` would be wrong here).
 */
export function CalendarGrid({ monthStart, issues }: CalendarGridProps) {
    const gridStart = startOfWeek(startOfMonth(monthStart), {
        weekStartsOn: 1,
    });
    const gridEnd = endOfWeek(endOfMonth(monthStart), { weekStartsOn: 1 });
    const days = eachDayOfInterval({ start: gridStart, end: gridEnd });

    const issuesByDate = new Map<string, CalendarIssue[]>();

    for (const issue of issues) {
        if (issue.due_date === null) {
            continue;
        }

        const list = issuesByDate.get(issue.due_date) ?? [];
        list.push(issue);
        issuesByDate.set(issue.due_date, list);
    }

    return (
        <div className="grid grid-cols-7 gap-px overflow-hidden rounded-xl border bg-border">
            {WEEKDAY_LABELS.map((label) => (
                <div
                    key={label}
                    className="bg-muted px-2 py-1.5 text-center text-xs font-medium text-muted-foreground"
                >
                    {label}
                </div>
            ))}

            {days.map((day) => {
                const dateKey = format(day, 'yyyy-MM-dd');
                const dayIssues = issuesByDate.get(dateKey) ?? [];
                const inCurrentMonth = isSameMonth(day, monthStart);
                const today = isToday(day);

                return (
                    <div
                        key={dateKey}
                        className={cn(
                            'flex min-h-28 flex-col gap-1 bg-card p-1.5',
                            !inCurrentMonth && 'bg-muted/40',
                        )}
                    >
                        <span
                            className={cn(
                                'self-end text-xs font-medium',
                                !inCurrentMonth && 'text-muted-foreground/60',
                                today &&
                                    'flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground',
                            )}
                        >
                            {format(day, 'd')}
                        </span>

                        <div className="flex min-w-0 flex-col gap-1 overflow-y-auto">
                            {dayIssues.map((issue) => (
                                <CalendarIssueItem
                                    key={issue.id}
                                    issue={issue}
                                />
                            ))}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function CalendarIssueItem({ issue }: { issue: CalendarIssue }) {
    return (
        <Link
            href={show.url({
                project: issue.project.key,
                issueKey: issue.key,
            })}
            title={issue.title}
            className="flex min-w-0 items-center gap-1 rounded px-1 py-0.5 text-xs transition-colors hover:bg-accent"
        >
            <IssueTypeIcon
                type={issue.type}
                className="size-3 shrink-0 text-muted-foreground"
            />
            <span className="shrink-0 font-medium text-muted-foreground">
                {issue.key}
            </span>
            <span className="truncate">{issue.title}</span>
        </Link>
    );
}
