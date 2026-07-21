import { Form, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

import {
    show,
    store,
    update,
} from '@/actions/App/Http/Controllers/IssueController';
import { IssueTypeIcon } from '@/components/board/issue-type-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { IssueChildSummary } from '@/types/board';
import type { BoardColumn } from '@/types/models';

type SubtasksListProps = {
    projectKey: string;
    parentIssueId: number;
    parentSprintId: number | null;
    subtasks: IssueChildSummary[];
    columns: Array<Pick<BoardColumn, 'id' | 'name'>>;
};

/**
 * The issue modal's sub-tasks section. Each row's "Done" checkbox is a
 * derived state — not its own column — computed against the project's
 * last column by position; toggling it PATCHes `board_column_id` through
 * `IssueController::update` (already recomputes `position` on a column
 * change since T-9.7, so this doesn't duplicate any logic). Inline
 * creation reuses `IssueController::store` with `parent_id` — the
 * one-level hierarchy guard already lives in `StoreIssueRequest::after()`
 * since T-9.3. Per the plan's decision, a new sub-task always starts as a
 * Task (enforced server-side) in the project's FIRST column, inheriting
 * the parent's own sprint so it stays visible under the same filter.
 */
export function SubtasksList({
    projectKey,
    parentIssueId,
    parentSprintId,
    subtasks,
    columns,
}: SubtasksListProps) {
    const [isAdding, setIsAdding] = useState(false);
    const firstColumnId = columns[0]?.id;
    const lastColumnId = columns[columns.length - 1]?.id;

    function navigate(subtask: IssueChildSummary) {
        router.visit(show.url({ project: projectKey, issueKey: subtask.key }), {
            preserveScroll: true,
        });
    }

    function toggleDone(subtask: IssueChildSummary) {
        if (firstColumnId === undefined || lastColumnId === undefined) {
            return;
        }

        const isDone = subtask.board_column_id === lastColumnId;
        const nextColumnId = isDone ? firstColumnId : lastColumnId;

        router.patch(
            update.url({ project: projectKey, issue: subtask.id }),
            { board_column_id: nextColumnId },
            { preserveScroll: true, preserveState: true },
        );
    }

    return (
        <div className="flex flex-col gap-1">
            {subtasks.length === 0 ? (
                <p className="text-sm text-muted-foreground">Sin sub-tareas.</p>
            ) : (
                <ul className="flex flex-col gap-1">
                    {subtasks.map((subtask) => {
                        const isDone =
                            lastColumnId !== undefined &&
                            subtask.board_column_id === lastColumnId;

                        return (
                            <li
                                key={subtask.id}
                                className="flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-accent"
                            >
                                <input
                                    type="checkbox"
                                    checked={isDone}
                                    onChange={() => toggleDone(subtask)}
                                    aria-label={`Marcar ${subtask.key} como ${isDone ? 'no hecha' : 'hecha'}`}
                                    className="size-4 shrink-0 accent-primary"
                                />
                                <button
                                    type="button"
                                    onClick={() => navigate(subtask)}
                                    className="flex flex-1 items-center gap-2 text-left text-sm"
                                >
                                    <IssueTypeIcon
                                        type={subtask.type}
                                        className="size-4 shrink-0 text-muted-foreground"
                                    />
                                    <span className="text-xs font-medium text-muted-foreground">
                                        {subtask.key}
                                    </span>
                                    <span
                                        className={cn(
                                            'truncate',
                                            isDone &&
                                                'text-muted-foreground line-through',
                                        )}
                                    >
                                        {subtask.title}
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}

            {isAdding ? (
                <Form
                    {...store.form(projectKey)}
                    resetOnSuccess
                    resetOnError
                    onSuccess={() => setIsAdding(false)}
                    className="flex items-center gap-2 px-2 pt-1"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="parent_id"
                                value={parentIssueId}
                            />
                            <input
                                type="hidden"
                                name="board_column_id"
                                value={firstColumnId ?? ''}
                            />
                            <input
                                type="hidden"
                                name="sprint_id"
                                value={parentSprintId ?? ''}
                            />

                            <Input
                                name="title"
                                placeholder="Título de la sub-tarea"
                                autoFocus
                                aria-invalid={Boolean(errors.title)}
                            />
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {processing ? 'Agregando…' : 'Agregar'}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setIsAdding(false)}
                            >
                                Cancelar
                            </Button>
                        </>
                    )}
                </Form>
            ) : (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="justify-start text-muted-foreground"
                    onClick={() => setIsAdding(true)}
                >
                    <Plus />
                    Añadir sub-tarea
                </Button>
            )}
        </div>
    );
}
