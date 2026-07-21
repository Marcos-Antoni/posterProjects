import type { Issue, Sprint } from '@/types/models';

/**
 * An issue as embedded in the backlog page's payload
 * (`BacklogController::index`) — either nested under its sprint or, when
 * `sprint_id` is `null`, listed in the Backlog section.
 */
export type BacklogIssue = Pick<
    Issue,
    'id' | 'key' | 'title' | 'type' | 'priority' | 'story_points' | 'sprint_id'
>;

/** A sprint entry on the backlog page: its own fields plus the derived story-point sum and issue list. */
export type BacklogSprint = Pick<
    Sprint,
    'id' | 'name' | 'goal' | 'start_date' | 'end_date'
> & {
    story_points_sum: number;
    issues: BacklogIssue[];
};
