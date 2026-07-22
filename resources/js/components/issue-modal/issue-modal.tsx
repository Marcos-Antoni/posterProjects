import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useCallback, useEffect, useState } from 'react';

import { show as showBoard } from '@/actions/App/Http/Controllers/BoardController';
import {
    show as showIssue,
    update,
} from '@/actions/App/Http/Controllers/IssueController';
import { IssueTypeIcon } from '@/components/board/issue-type-icon';
import { CommentsList } from '@/components/issue-modal/comments-list';
import { EditForm } from '@/components/issue-modal/edit-form';
import { LabelsPicker } from '@/components/issue-modal/labels-picker';
import { SubtasksList } from '@/components/issue-modal/subtasks-list';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useAutosaveField } from '@/hooks/use-autosave-field';
import type { BoardLabel, BoardMember, IssueDetail } from '@/types/board';
import type { BoardColumn, Sprint } from '@/types/models';

type IssueModalProps = {
    projectKey: string;
    issue: IssueDetail | null;
    columns: Array<Pick<BoardColumn, 'id' | 'name'>>;
    sprints: Sprint[];
    members: BoardMember[];
    labels: BoardLabel[];
    selectedSprintId: number | null;
};

/**
 * Route-driven modal for the `IssueController::show` deep link: the board
 * stays mounted behind it. Per the Gate 2 animation-stack decision, the
 * modal uses Motion's `AnimatePresence` (not the Radix `Dialog` the
 * column-management dialogs use) — closing navigates back to the board
 * without a full page reload, preserving the current sprint filter.
 */
export function IssueModal({
    projectKey,
    issue,
    columns,
    sprints,
    members,
    labels,
    selectedSprintId,
}: IssueModalProps) {
    const handleClose = useCallback(() => {
        router.visit(showBoard.url({ project: projectKey }), {
            preserveScroll: true,
            preserveState: true,
            data: selectedSprintId !== null ? { sprint: selectedSprintId } : {},
        });
    }, [projectKey, selectedSprintId]);

    useEffect(() => {
        if (!issue) {
            return;
        }

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                handleClose();
            }
        }

        document.addEventListener('keydown', handleKeyDown);

        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [issue, handleClose]);

    return (
        <AnimatePresence>
            {issue ? (
                <motion.div
                    key="issue-modal-overlay"
                    className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/30 p-4 pt-12 backdrop-blur-xs sm:pt-20 dark:bg-black/50"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0 }}
                    transition={{ duration: 0.15 }}
                    onClick={handleClose}
                >
                    <IssueModalPanel
                        key={issue.id}
                        projectKey={projectKey}
                        issue={issue}
                        columns={columns}
                        sprints={sprints}
                        members={members}
                        labels={labels}
                        onClose={handleClose}
                    />
                </motion.div>
            ) : null}
        </AnimatePresence>
    );
}

type IssueModalPanelProps = {
    projectKey: string;
    issue: IssueDetail;
    columns: Array<Pick<BoardColumn, 'id' | 'name'>>;
    sprints: Sprint[];
    members: BoardMember[];
    labels: BoardLabel[];
    onClose: () => void;
};

/**
 * The panel's own component, keyed by `issue.id` from the parent — this
 * fully remounts (and resets every local draft) whenever the deep link
 * points at a different issue, instead of needing to reconcile stale
 * local state against a changed `issue` prop.
 */
function IssueModalPanel({
    projectKey,
    issue,
    columns,
    sprints,
    members,
    labels,
    onClose,
}: IssueModalPanelProps) {
    const url = update.url({ project: projectKey, issue: issue.id });
    const title = useAutosaveField<string>(url, 'title', issue.title);
    const description = useAutosaveField<string | null>(
        url,
        'description',
        issue.description,
    );

    const [titleDraft, setTitleDraft] = useState(issue.title);
    const [descriptionDraft, setDescriptionDraft] = useState(
        issue.description ?? '',
    );

    function saveTitle() {
        const trimmed = titleDraft.trim();

        if (trimmed === '' || trimmed === title.value) {
            setTitleDraft(title.value);

            return;
        }

        title.save(trimmed);
    }

    function saveDescription() {
        const normalized =
            descriptionDraft.trim() === '' ? null : descriptionDraft;

        if (normalized === description.value) {
            return;
        }

        description.save(normalized);
    }

    function openParent() {
        if (!issue.parent) {
            return;
        }

        router.visit(
            showIssue.url({
                project: projectKey,
                issueKey: issue.parent.key,
            }),
            { preserveScroll: true },
        );
    }

    return (
        <motion.div
            role="dialog"
            aria-modal="true"
            aria-label={`${issue.key} — ${issue.title}`}
            className="flex max-h-[calc(100vh-6rem)] w-full max-w-2xl flex-col gap-4 overflow-y-auto rounded-xl bg-popover p-5 text-sm text-popover-foreground shadow-lg ring-1 ring-foreground/10"
            initial={{ opacity: 0, y: 16, scale: 0.98 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 8, scale: 0.98 }}
            transition={{ duration: 0.18, ease: 'easeOut' }}
            onClick={(event) => event.stopPropagation()}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="flex items-center gap-2">
                    <IssueTypeIcon
                        type={issue.type}
                        className="size-4 text-muted-foreground"
                    />
                    <span className="text-xs font-medium text-muted-foreground">
                        {issue.key}
                    </span>
                    {issue.parent ? (
                        <>
                            <span className="text-xs text-muted-foreground">
                                ·
                            </span>
                            <button
                                type="button"
                                onClick={openParent}
                                className="text-xs text-muted-foreground underline-offset-2 hover:underline"
                            >
                                {issue.parent.key} {issue.parent.title}
                            </button>
                        </>
                    ) : null}
                </div>

                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    onClick={onClose}
                >
                    <X />
                    <span className="sr-only">Cerrar</span>
                </Button>
            </div>

            <input
                value={titleDraft}
                onChange={(event) => setTitleDraft(event.target.value)}
                onBlur={saveTitle}
                aria-label="Título"
                className="w-full rounded-lg border border-transparent bg-transparent px-1 text-lg font-medium transition-colors outline-none hover:border-input focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            />

            <LabelsPicker
                projectKey={projectKey}
                issueId={issue.id}
                attachedLabels={issue.labels}
                allLabels={labels}
            />

            <EditForm
                projectKey={projectKey}
                issue={issue}
                columns={columns}
                sprints={sprints}
                members={members}
            />

            <div className="grid gap-1">
                <span className="text-xs font-medium text-muted-foreground">
                    Descripción
                </span>
                <Textarea
                    value={descriptionDraft}
                    onChange={(event) =>
                        setDescriptionDraft(event.target.value)
                    }
                    onBlur={saveDescription}
                    placeholder="Sin descripción"
                    rows={4}
                />
            </div>

            <p className="text-xs text-muted-foreground">
                Reportado por {issue.reporter.name}
            </p>

            <div className="grid gap-2">
                <h3 className="text-xs font-semibold text-muted-foreground uppercase">
                    Sub-tareas
                </h3>
                <SubtasksList
                    projectKey={projectKey}
                    parentIssueId={issue.id}
                    parentSprintId={issue.sprint_id}
                    subtasks={issue.children}
                    columns={columns}
                />
            </div>

            <div className="grid gap-2">
                <h3 className="text-xs font-semibold text-muted-foreground uppercase">
                    Comentarios
                </h3>
                <CommentsList
                    projectKey={projectKey}
                    issueId={issue.id}
                    comments={issue.comments}
                />
            </div>
        </motion.div>
    );
}
