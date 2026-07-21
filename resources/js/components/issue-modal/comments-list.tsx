import { Form, router, usePage } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { es } from 'date-fns/locale';
import { Pencil, Trash2 } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useState } from 'react';

import {
    destroy,
    store,
    update,
} from '@/actions/App/Http/Controllers/CommentController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import type { IssueComment } from '@/types/board';

type CommentsListProps = {
    projectKey: string;
    issueId: number;
    comments: IssueComment[];
};

function formatRelativeTimestamp(value: string | null): string {
    if (!value) {
        return '';
    }

    return formatDistanceToNow(new Date(value), {
        addSuffix: true,
        locale: es,
    });
}

/**
 * The issue modal's comments section: post, and — author-only, not even
 * the project owner (`CommentPolicy`) — edit inline or delete with a
 * confirmation dialog. New/removed comments animate in/out via Motion,
 * matching the Gate 2 animation-stack decision.
 */
export function CommentsList({
    projectKey,
    issueId,
    comments,
}: CommentsListProps) {
    const { props } = usePage();
    const currentUserId = props.auth.user.id;

    const [editingId, setEditingId] = useState<number | null>(null);
    const [editDraft, setEditDraft] = useState('');
    const [deletingId, setDeletingId] = useState<number | null>(null);

    function startEditing(comment: IssueComment) {
        setEditingId(comment.id);
        setEditDraft(comment.body);
    }

    function cancelEditing() {
        setEditingId(null);
        setEditDraft('');
    }

    function saveEditing(commentId: number) {
        const trimmed = editDraft.trim();

        if (trimmed === '') {
            return;
        }

        router.patch(
            update.url({
                project: projectKey,
                issue: issueId,
                comment: commentId,
            }),
            { body: trimmed },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setEditingId(null),
            },
        );
    }

    function confirmDelete() {
        if (deletingId === null) {
            return;
        }

        router.delete(
            destroy.url({
                project: projectKey,
                issue: issueId,
                comment: deletingId,
            }),
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setDeletingId(null),
            },
        );
    }

    return (
        <div className="flex flex-col gap-3">
            {comments.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Todavía no hay comentarios.
                </p>
            ) : (
                <ul className="flex flex-col gap-3">
                    <AnimatePresence initial={false}>
                        {comments.map((comment) => {
                            const isAuthor =
                                comment.author.id === currentUserId;
                            const isEditing = editingId === comment.id;

                            return (
                                <motion.li
                                    key={comment.id}
                                    layout
                                    initial={{ opacity: 0, y: 8 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0, scale: 0.97 }}
                                    transition={{
                                        duration: 0.18,
                                        ease: 'easeOut',
                                    }}
                                    className="rounded-lg border bg-muted/30 p-2.5"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-xs font-medium">
                                            {comment.author.name}
                                        </span>
                                        <div className="flex items-center gap-1">
                                            <span className="text-xs text-muted-foreground">
                                                {formatRelativeTimestamp(
                                                    comment.created_at,
                                                )}
                                            </span>
                                            {isAuthor && !isEditing ? (
                                                <>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        onClick={() =>
                                                            startEditing(
                                                                comment,
                                                            )
                                                        }
                                                    >
                                                        <Pencil />
                                                        <span className="sr-only">
                                                            Editar
                                                        </span>
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        onClick={() =>
                                                            setDeletingId(
                                                                comment.id,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 />
                                                        <span className="sr-only">
                                                            Borrar
                                                        </span>
                                                    </Button>
                                                </>
                                            ) : null}
                                        </div>
                                    </div>

                                    {isEditing ? (
                                        <div className="mt-1.5 flex flex-col gap-2">
                                            <Textarea
                                                value={editDraft}
                                                onChange={(event) =>
                                                    setEditDraft(
                                                        event.target.value,
                                                    )
                                                }
                                                rows={3}
                                                autoFocus
                                            />
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    disabled={
                                                        editDraft.trim() === ''
                                                    }
                                                    onClick={() =>
                                                        saveEditing(comment.id)
                                                    }
                                                >
                                                    Guardar
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={cancelEditing}
                                                >
                                                    Cancelar
                                                </Button>
                                            </div>
                                        </div>
                                    ) : (
                                        <p className="mt-1 text-sm whitespace-pre-wrap">
                                            {comment.body}
                                        </p>
                                    )}
                                </motion.li>
                            );
                        })}
                    </AnimatePresence>
                </ul>
            )}

            <Form
                {...store.form({ project: projectKey, issue: issueId })}
                resetOnSuccess
                resetOnError
                className="flex flex-col gap-2"
            >
                {({ errors, processing }) => (
                    <>
                        <Textarea
                            name="body"
                            placeholder="Escribí un comentario…"
                            rows={2}
                            aria-invalid={Boolean(errors.body)}
                        />
                        {errors.body ? (
                            <p className="text-xs text-destructive">
                                {errors.body}
                            </p>
                        ) : null}
                        <Button
                            type="submit"
                            size="sm"
                            className="self-start"
                            disabled={processing}
                        >
                            {processing ? 'Comentando…' : 'Comentar'}
                        </Button>
                    </>
                )}
            </Form>

            <Dialog
                open={deletingId !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeletingId(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>¿Borrar este comentario?</DialogTitle>
                        <DialogDescription>
                            Esta acción no se puede deshacer.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={confirmDelete}
                        >
                            Borrar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
