import type { User } from '@/types/auth';
import type { BoardColumn, Issue, Label } from '@/types/models';

/**
 * An issue as embedded in the board's per-column payload
 * (`BoardController::show`). Labels and assignee are eager-loaded and
 * trimmed to only the fields a board card needs.
 */
export type BoardIssue = Pick<
    Issue,
    | 'id'
    | 'key'
    | 'number'
    | 'title'
    | 'type'
    | 'priority'
    | 'story_points'
    | 'position'
    | 'board_column_id'
    | 'sprint_id'
> & {
    labels: Array<Pick<Label, 'id' | 'name'>>;
    assignee: Pick<User, 'id' | 'name'> | null;
};

export type BoardColumnWithIssues = BoardColumn & { issues: BoardIssue[] };

/** A project member, trimmed to what the assignee picker needs. */
export type BoardMember = Pick<User, 'id' | 'name'>;

/** A project's label catalog entry, as used by the issue modal's `LabelsPicker`. */
export type BoardLabel = Pick<Label, 'id' | 'name'>;

/** A child issue as listed inside its parent's modal (`IssueDetail.children`). */
export type IssueChildSummary = Pick<
    Issue,
    'id' | 'key' | 'title' | 'type' | 'board_column_id'
>;

/** A comment as listed in the issue modal (`IssueController::show`). Read-only until T-9.9. */
export type IssueComment = {
    id: number;
    body: string;
    created_at: string | null;
    author: Pick<User, 'id' | 'name'>;
};

/**
 * The full read (and, from T-9.7, editable) payload for the issue modal —
 * `IssueController::show`'s `issue` prop. Unlike `BoardIssue`, this
 * includes everything the modal needs: description, due date, hierarchy,
 * and the labels/assignee/reporter/parent/children/comments relations.
 */
export type IssueDetail = Pick<
    Issue,
    | 'id'
    | 'key'
    | 'number'
    | 'title'
    | 'description'
    | 'type'
    | 'priority'
    | 'story_points'
    | 'due_date'
    | 'board_column_id'
    | 'sprint_id'
    | 'parent_id'
> & {
    labels: Array<Pick<Label, 'id' | 'name'>>;
    assignee: Pick<User, 'id' | 'name'> | null;
    reporter: Pick<User, 'id' | 'name'>;
    parent: Pick<Issue, 'id' | 'key' | 'title'> | null;
    children: IssueChildSummary[];
    comments: IssueComment[];
};
