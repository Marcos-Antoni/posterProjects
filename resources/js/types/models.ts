/**
 * Domain types mirroring the Eloquent models in `app/Models`.
 *
 * Dates are ISO 8601 strings (as serialized by Laravel), never `Date`
 * instances — Inertia sends plain JSON over the wire.
 */

export type IssueType = 'epic' | 'story' | 'task' | 'bug';

/** Mirrors `App\Enums\IssuePriority` — 1 (Highest) to 5 (Lowest). */
export type IssuePriority = 1 | 2 | 3 | 4 | 5;

export type Project = {
    id: number;
    owner_id: number;
    key: string;
    name: string;
    description: string | null;
    next_issue_number: number;
    created_at: string | null;
    updated_at: string | null;
    /** Set once the project is archived (soft deleted). See the trash page. */
    deleted_at: string | null;
};

export type BoardColumn = {
    id: number;
    project_id: number;
    name: string;
    position: number;
    created_at: string | null;
    updated_at: string | null;
};

export type Sprint = {
    id: number;
    project_id: number;
    name: string;
    goal: string | null;
    start_date: string;
    end_date: string;
    created_at: string | null;
    updated_at: string | null;
};

export type Label = {
    id: number;
    project_id: number;
    name: string;
    created_at: string | null;
    updated_at: string | null;
};

export type Issue = {
    id: number;
    project_id: number;
    board_column_id: number;
    sprint_id: number | null;
    parent_id: number | null;
    number: number;
    /** Human-readable key, e.g. "PROJ-123". Computed accessor, not persisted. */
    key: string;
    type: IssueType;
    priority: IssuePriority;
    title: string;
    description: string | null;
    story_points: number | null;
    due_date: string | null;
    assignee_id: number | null;
    reporter_id: number;
    position: number;
    created_at: string | null;
    updated_at: string | null;
};

export type Comment = {
    id: number;
    issue_id: number;
    user_id: number;
    body: string;
    created_at: string | null;
    updated_at: string | null;
};
