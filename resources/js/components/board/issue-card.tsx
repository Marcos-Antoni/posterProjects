import { useDraggable, useDroppable } from '@dnd-kit/core';
import { router } from '@inertiajs/react';
import { motion } from 'motion/react';

import { show } from '@/actions/App/Http/Controllers/IssueController';
import { IssuePriorityBadge } from '@/components/board/issue-priority-badge';
import { IssueTypeIcon } from '@/components/board/issue-type-icon';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import type { IssueDragData } from '@/hooks/use-board-dnd';
import { cn } from '@/lib/utils';
import type { BoardIssue } from '@/types/board';

type IssueCardProps = {
    issue: BoardIssue;
};

type DraggableIssueCardProps = IssueCardProps & {
    projectKey: string;
    selectedSprintId: number | null;
};

const cardVariants = {
    hidden: { opacity: 0, y: 8, scale: 0.98 },
    visible: { opacity: 1, y: 0, scale: 1 },
};

function initials(name: string): string {
    return (
        name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase())
            .join('') || '?'
    );
}

/**
 * The card's visual content only — no drag behavior. Shared by the
 * draggable card on the board and its floating `DragOverlay` clone while
 * being dragged (see `board.tsx`).
 */
export function IssueCardContent({ issue }: IssueCardProps) {
    return (
        <div className="flex flex-col gap-2 rounded-lg border bg-card p-3 shadow-sm">
            <div className="flex items-start justify-between gap-2">
                <span className="text-xs font-medium text-muted-foreground">
                    {issue.key}
                </span>
                <IssueTypeIcon
                    type={issue.type}
                    className="size-4 shrink-0 text-muted-foreground"
                />
            </div>

            <p className="text-sm leading-snug font-medium">{issue.title}</p>

            {issue.labels.length > 0 ? (
                <div className="flex flex-wrap gap-1">
                    {issue.labels.map((label) => (
                        <span
                            key={label.id}
                            className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >
                            {label.name}
                        </span>
                    ))}
                </div>
            ) : null}

            <div className="flex items-center justify-between gap-2 pt-1">
                <div className="flex items-center gap-2">
                    <IssuePriorityBadge priority={issue.priority} />
                    {issue.story_points !== null ? (
                        <span className="rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
                            {issue.story_points} pts
                        </span>
                    ) : null}
                </div>

                {issue.assignee ? (
                    <Avatar size="sm" title={issue.assignee.name}>
                        <AvatarFallback>
                            {initials(issue.assignee.name)}
                        </AvatarFallback>
                    </Avatar>
                ) : null}
            </div>
        </div>
    );
}

/**
 * A single draggable, droppable issue card on the board.
 *
 * dnd-kit owns the drag gesture and the floating `DragOverlay` preview
 * (see `board.tsx`) — this element itself never receives a transform, so
 * Motion's `layout`/enter/exit animations (new cards from quick-add,
 * reflow after a move lands) apply to it undisturbed. While it's the
 * active drag source it's just dimmed via `isDragging`. It's also a drop
 * target: dropping another card "on" this one inserts the dragged card
 * at this card's position within the column (see `useBoardDnd`). A plain
 * click (no drag) opens the issue modal — see the module doc comment
 * below for why this doesn't get eaten by drag detection.
 */
export function IssueCard({
    issue,
    projectKey,
    selectedSprintId,
}: DraggableIssueCardProps) {
    const dragData: IssueDragData = {
        type: 'issue',
        issueId: issue.id,
        columnId: issue.board_column_id,
    };

    const {
        attributes,
        listeners,
        setNodeRef: setDragNodeRef,
        isDragging,
    } = useDraggable({
        id: `issue-${issue.id}`,
        data: dragData,
    });
    const { setNodeRef: setDropNodeRef } = useDroppable({
        id: `issue-${issue.id}`,
        data: dragData,
    });

    function setNodeRef(node: HTMLElement | null) {
        setDragNodeRef(node);
        setDropNodeRef(node);
    }

    // dnd-kit's MouseSensor only starts a drag once the pointer moves past
    // the 6px activation distance, and TouchSensor only after a ~250ms
    // long-press (see `useBoardDnd`); a click/tap below those thresholds
    // fires normally. If a real drag DOES start, dnd-kit itself stops
    // propagation on the trailing `click` event (verified in
    // `@dnd-kit/core`'s `AbstractPointerSensor.handleStart` — both sensors
    // extend this same base class, which adds a capture-phase `click`
    // listener that calls `stopPropagation()` once activation constraints
    // are met), so this handler never fires as a spurious "open" right
    // after a completed drag.
    function handleOpen() {
        router.visit(show.url({ project: projectKey, issueKey: issue.key }), {
            preserveScroll: true,
            data: selectedSprintId !== null ? { sprint: selectedSprintId } : {},
        });
    }

    return (
        <motion.div
            ref={setNodeRef}
            {...attributes}
            {...listeners}
            onClick={handleOpen}
            layout
            initial="hidden"
            animate="visible"
            exit={{ opacity: 0, scale: 0.96 }}
            variants={cardVariants}
            transition={{ duration: 0.18, ease: 'easeOut' }}
            className={cn(
                'cursor-pointer touch-manipulation',
                isDragging && 'opacity-40',
            )}
        >
            <IssueCardContent issue={issue} />
        </motion.div>
    );
}
