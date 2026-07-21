import { Form } from '@inertiajs/react';

import { update } from '@/actions/App/Http/Controllers/LabelController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RenameLabelDialogProps = {
    projectKey: string;
    label: { id: number; name: string };
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Owner-only "rename label" dialog. `UpdateLabelRequest` already returns a
 * friendly Spanish message when the new name collides with another label
 * in the same project ("Ya existe una etiqueta con ese nombre en este
 * proyecto.") — this just surfaces `errors.name` as-is, no client-side
 * duplicate check needed.
 */
export function RenameLabelDialog({
    projectKey,
    label,
    open,
    onOpenChange,
}: RenameLabelDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Renombrar etiqueta</DialogTitle>
                </DialogHeader>

                <Form
                    {...update.form({ project: projectKey, label: label.id })}
                    onSuccess={() => onOpenChange(false)}
                    className="mt-4 flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nombre</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    defaultValue={label.name}
                                    aria-invalid={Boolean(errors.name)}
                                />
                                {errors.name ? (
                                    <p className="text-sm text-destructive">
                                        {errors.name}
                                    </p>
                                ) : null}
                            </div>

                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Guardando…' : 'Guardar'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
