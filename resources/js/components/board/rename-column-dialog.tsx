import { Form } from '@inertiajs/react';

import { update } from '@/actions/App/Http/Controllers/BoardColumnController';
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
import type { BoardColumn } from '@/types/models';

type RenameColumnDialogProps = {
    projectKey: string;
    column: Pick<BoardColumn, 'id' | 'name'>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Owner-only "rename column" dialog, opened from `ColumnManagementMenu`.
 */
export function RenameColumnDialog({
    projectKey,
    column,
    open,
    onOpenChange,
}: RenameColumnDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Renombrar columna</DialogTitle>
                </DialogHeader>

                <Form
                    {...update.form({
                        project: projectKey,
                        boardColumn: column.id,
                    })}
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
                                    defaultValue={column.name}
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
