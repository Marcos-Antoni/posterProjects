import type { Issue, Project } from '@/types/models';

/**
 * An issue as embedded in the global calendar's payload
 * (`CalendarController::index`) — every issue with a due date this month,
 * across every project the authenticated user is a member of.
 */
export type CalendarIssue = Pick<
    Issue,
    'id' | 'key' | 'title' | 'type' | 'priority' | 'due_date'
> & {
    project: Pick<Project, 'key'>;
};
