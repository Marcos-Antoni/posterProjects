import { Form } from '@inertiajs/react';

import { destroy } from '@/actions/App/Http/Controllers/LabelController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type DeleteLabelDialogProps = {
    projectKey: string;
    label: { id: number; name: string };
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Owner-only "delete label" confirmation. `issue_label.label_id` cascades
 * at the database level (`LabelController::destroy`), so deleting a label
 * detaches it from every issue automatically — the issues themselves are
 * never touched.
 */
export function DeleteLabelDialog({
    projectKey,
    label,
    open,
    onOpenChange,
}: DeleteLabelDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        ¿Borrar la etiqueta “{label.name}”?
                    </DialogTitle>
                    <DialogDescription>
                        Se quita de todas las tareas que la tengan. Esta acción
                        no se puede deshacer.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...destroy.form({ project: projectKey, label: label.id })}
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
                                {processing ? 'Borrando…' : 'Borrar etiqueta'}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
