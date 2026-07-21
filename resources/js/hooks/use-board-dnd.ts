import type { DragEndEvent } from '@dnd-kit/core';
import { PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { router } from '@inertiajs/react';

import { move } from '@/actions/App/Http/Controllers/IssueMoveController';
import type { BoardColumnWithIssues, BoardIssue } from '@/types/board';

/** `useDraggable`/`useSortable` data attached to every issue card. */
export type IssueDragData = {
    type: 'issue';
    issueId: number;
    columnId: number;
};

/** `useDroppable` data attached to a column's card list (drop target when hovering an empty area). */
export type ColumnDropData = {
    type: 'column';
    columnId: number;
};

type OverData = IssueDragData | ColumnDropData;

type BoardProps = {
    columns: BoardColumnWithIssues[];
};

type UseBoardDndOptions = {
    projectKey: string;
    columns: BoardColumnWithIssues[];
};

/**
 * Wires dnd-kit's `DndContext` to the board: dropping a card on another
 * card or on a column's drop zone fires an optimistic
 * `IssueMoveController::move` PATCH. `router.optimistic()` recomputes
 * `columns` immediately (mirroring the server's insert algorithm) and
 * automatically reverts to the pre-drag state if the request fails — no
 * manual rollback bookkeeping needed. `sprint_id` is never sent in the
 * payload; the server keeps whatever the issue already had, per the
 * "drag never touches sprint_id" rule.
 */
export function useBoardDnd({ projectKey, columns }: UseBoardDndOptions) {
    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: 6 },
        }),
    );

    function handleDragEnd(event: DragEndEvent) {
        const { active, over } = event;

        if (!over || active.id === over.id) {
            return;
        }

        const activeData = active.data.current as IssueDragData | undefined;
        const overData = over.data.current as OverData | undefined;

        if (!activeData || !overData) {
            return;
        }

        const destinationColumnId = overData.columnId;
        const destinationColumn = columns.find(
            (column) => column.id === destinationColumnId,
        );

        if (!destinationColumn) {
            return;
        }

        const siblingIds = destinationColumn.issues
            .filter((issue) => issue.id !== activeData.issueId)
            .map((issue) => issue.id);

        const destinationPosition =
            overData.type === 'issue'
                ? siblingIds.indexOf(overData.issueId)
                : siblingIds.length;

        if (destinationPosition === -1) {
            return;
        }

        router
            .optimistic<BoardProps>((props) => ({
                columns: moveIssueAcrossColumns(
                    props.columns,
                    activeData.issueId,
                    destinationColumnId,
                    destinationPosition,
                ),
            }))
            .patch(
                move.url({ project: projectKey, issue: activeData.issueId }),
                {
                    board_column_id: destinationColumnId,
                    position: destinationPosition,
                },
            );
    }

    return { sensors, handleDragEnd };
}

/**
 * Mirrors `Issue::reorderScope` client-side so the optimistic prop update
 * matches what the real response will look like: remove the issue from
 * wherever it currently lives, then splice it into the destination
 * column at the target index.
 */
function moveIssueAcrossColumns(
    columns: BoardColumnWithIssues[],
    issueId: number,
    destinationColumnId: number,
    destinationPosition: number,
): BoardColumnWithIssues[] {
    let movingIssue: BoardIssue | undefined;

    const withoutIssue = columns.map((column) => {
        const issue = column.issues.find(
            (candidate) => candidate.id === issueId,
        );

        if (!issue) {
            return column;
        }

        movingIssue = issue;

        return {
            ...column,
            issues: column.issues.filter(
                (candidate) => candidate.id !== issueId,
            ),
        };
    });

    if (!movingIssue) {
        return columns;
    }

    const insertedIssue: BoardIssue = {
        ...movingIssue,
        board_column_id: destinationColumnId,
    };

    return withoutIssue.map((column) => {
        if (column.id !== destinationColumnId) {
            return column;
        }

        const issues = [...column.issues];
        issues.splice(destinationPosition, 0, insertedIssue);

        return { ...column, issues };
    });
}
