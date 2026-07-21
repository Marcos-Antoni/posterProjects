import { Form } from '@inertiajs/react';

import { destroy } from '@/actions/App/Http/Controllers/SprintController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BacklogSprint } from '@/types/backlog';

type DeleteSprintDialogProps = {
    projectKey: string;
    sprint: Pick<BacklogSprint, 'id' | 'name'>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Owner-only "delete sprint" confirmation. A plain confirm — no typed
 * confirmation needed (Gate 2 decision): the server never deletes the
 * sprint's issues, `issues.sprint_id` has `nullOnDelete()`, so they simply
 * return to the backlog (see `SprintController::destroy`).
 */
export function DeleteSprintDialog({
    projectKey,
    sprint,
    open,
    onOpenChange,
}: DeleteSprintDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        ¿Borrar el sprint “{sprint.name}”?
                    </DialogTitle>
                    <DialogDescription>
                        Sus tareas vuelven al backlog — no se borran. Esta
                        acción no se puede deshacer.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...destroy.form({
                        project: projectKey,
                        sprint: sprint.id,
                    })}
                    onSuccess={() => onOpenChange(false)}
                    className="mt-4"
                >
                    {({ processing }) => (
                        <DialogFooter>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                {processing ? 'Borrando…' : 'Borrar sprint'}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
