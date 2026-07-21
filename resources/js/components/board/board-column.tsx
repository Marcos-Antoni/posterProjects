import { useDroppable } from '@dnd-kit/core';
import { AnimatePresence } from 'motion/react';

import { ColumnManagementMenu } from '@/components/board/column-management-menu';
import { IssueCard } from '@/components/board/issue-card';
import { QuickAddIssue } from '@/components/board/quick-add-issue';
import type { ColumnDropData } from '@/hooks/use-board-dnd';
import { cn } from '@/lib/utils';
import type { BoardColumnWithIssues } from '@/types/board';

type BoardColumnProps = {
    column: BoardColumnWithIssues;
    projectKey: string;
    selectedSprintId: number | null;
    isOwner: boolean;
    allColumns: BoardColumnWithIssues[];
    columnIndex: number;
};

/**
 * One board column: name, its issues (ordered by position, already
 * filtered by the selected sprint on the server), and a quick-add form at
 * the foot. The whole card list is a dnd-kit drop zone (see
 * `useBoardDnd`) — dropping a card in the empty space below the last one,
 * or into an empty column, appends it to the end. Owners additionally get
 * a management menu (rename/move/delete) in the header.
 */
export function BoardColumn({
    column,
    projectKey,
    selectedSprintId,
    isOwner,
    allColumns,
    columnIndex,
}: BoardColumnProps) {
    const dropData: ColumnDropData = { type: 'column', columnId: column.id };
    const { setNodeRef, isOver } = useDroppable({
        id: `column-${column.id}`,
        data: dropData,
    });

    return (
        <div className="flex w-72 shrink-0 flex-col gap-3 rounded-xl border bg-muted/30 p-3">
            <div className="flex items-center justify-between px-1">
                <h2 className="text-sm font-semibold">{column.name}</h2>

                <div className="flex items-center gap-1">
                    <span className="text-xs text-muted-foreground">
                        {column.issues.length}
                    </span>

                    {isOwner ? (
                        <ColumnManagementMenu
                            projectKey={projectKey}
                            column={{
                                id: column.id,
                                name: column.name,
                                issuesCount: column.issues.length,
                            }}
                            otherColumns={allColumns
                                .filter(
                                    (candidate) => candidate.id !== column.id,
                                )
                                .map((candidate) => ({
                                    id: candidate.id,
                                    name: candidate.name,
                                }))}
                            isFirst={columnIndex === 0}
                            isLast={columnIndex === allColumns.length - 1}
                            columnIndex={columnIndex}
                        />
                    ) : null}
                </div>
            </div>

            <div
                ref={setNodeRef}
                className={cn(
                    'flex min-h-16 flex-col gap-2 overflow-y-auto rounded-lg transition-colors',
                    isOver && 'bg-accent/50',
                )}
            >
                <AnimatePresence initial={false}>
                    {column.issues.map((issue) => (
                        <IssueCard
                            key={issue.id}
                            issue={issue}
                            projectKey={projectKey}
                            selectedSprintId={selectedSprintId}
                        />
                    ))}
                </AnimatePresence>
            </div>

            <QuickAddIssue
                projectKey={projectKey}
                boardColumnId={column.id}
                sprintId={selectedSprintId}
            />
        </div>
    );
}
