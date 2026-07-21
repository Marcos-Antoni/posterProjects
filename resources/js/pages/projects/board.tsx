import type { DragEndEvent, DragStartEvent } from '@dnd-kit/core';
import { closestCenter, DndContext, DragOverlay } from '@dnd-kit/core';
import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';

import { index as backlogIndex } from '@/actions/App/Http/Controllers/BacklogController';
import { index as labelsIndex } from '@/actions/App/Http/Controllers/LabelController';
import { BoardColumn } from '@/components/board/board-column';
import { CreateColumnDialog } from '@/components/board/create-column-dialog';
import { IssueCardContent } from '@/components/board/issue-card';
import { SprintSelector } from '@/components/board/sprint-selector';
import { IssueModal } from '@/components/issue-modal/issue-modal';
import type { IssueDragData } from '@/hooks/use-board-dnd';
import { useBoardDnd } from '@/hooks/use-board-dnd';
import AppLayout from '@/layouts/app-layout';
import type {
    BoardColumnWithIssues,
    BoardIssue,
    BoardLabel,
    BoardMember,
    IssueDetail,
} from '@/types/board';
import type { Project, Sprint } from '@/types/models';

type BoardPageProps = {
    project: Pick<Project, 'id' | 'key' | 'name' | 'owner_id'>;
    columns: BoardColumnWithIssues[];
    sprints: Sprint[];
    selectedSprintId: number | null;
    activeSprintId: number | null;
    members: BoardMember[];
    labels: BoardLabel[];
    /** Present only on the issue deep-link route (`IssueController::show`). */
    issue?: IssueDetail | null;
};

/**
 * A project's Trello-style board: horizontally scrollable columns, each
 * with its issues (filtered by the sprint selector), a quick-add form,
 * and drag-and-drop between/within columns. Owners additionally get
 * column management (add/rename/reorder/delete). This same page renders
 * the issue modal on top when `issue` is present (the deep-link route,
 * `IssueController::show`) — see `IssueModal`.
 */
export default function Board({
    project,
    columns,
    sprints,
    selectedSprintId,
    activeSprintId,
    members,
    labels,
    issue = null,
}: BoardPageProps) {
    const { props } = usePage();
    const isOwner = props.auth.user.id === project.owner_id;

    const [activeIssue, setActiveIssue] = useState<BoardIssue | null>(null);
    const { sensors, handleDragEnd } = useBoardDnd({
        projectKey: project.key,
        columns,
    });

    function handleDragStart(event: DragStartEvent) {
        const data = event.active.data.current as IssueDragData | undefined;

        if (!data) {
            return;
        }

        const issue = columns
            .flatMap((column) => column.issues)
            .find((candidate) => candidate.id === data.issueId);

        setActiveIssue(issue ?? null);
    }

    function handleDragCancelOrEnd(event: DragEndEvent) {
        handleDragEnd(event);
        setActiveIssue(null);
    }

    return (
        <>
            <Head title={`Tablero — ${project.name}`} />

            <div className="flex h-full flex-col gap-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="font-heading text-2xl font-medium">
                            {project.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {project.key}
                        </p>
                    </div>

                    <div className="flex items-center gap-4">
                        <Link
                            href={backlogIndex.url({ project: project.key })}
                            className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        >
                            Backlog
                        </Link>
                        <Link
                            href={labelsIndex.url({ project: project.key })}
                            className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        >
                            Etiquetas
                        </Link>

                        <SprintSelector
                            sprints={sprints}
                            selectedSprintId={selectedSprintId}
                            activeSprintId={activeSprintId}
                        />
                    </div>
                </div>

                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragStart={handleDragStart}
                    onDragEnd={handleDragCancelOrEnd}
                    onDragCancel={() => setActiveIssue(null)}
                >
                    <div className="flex flex-1 items-start gap-4 overflow-x-auto pb-4">
                        {columns.map((column, index) => (
                            <BoardColumn
                                key={column.id}
                                column={column}
                                projectKey={project.key}
                                selectedSprintId={selectedSprintId}
                                isOwner={isOwner}
                                allColumns={columns}
                                columnIndex={index}
                            />
                        ))}

                        {isOwner ? (
                            <CreateColumnDialog projectKey={project.key} />
                        ) : null}
                    </div>

                    <DragOverlay>
                        {activeIssue ? (
                            <IssueCardContent issue={activeIssue} />
                        ) : null}
                    </DragOverlay>
                </DndContext>
            </div>

            <IssueModal
                projectKey={project.key}
                issue={issue}
                columns={columns}
                sprints={sprints}
                members={members}
                labels={labels}
                selectedSprintId={selectedSprintId}
            />
        </>
    );
}

Board.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
