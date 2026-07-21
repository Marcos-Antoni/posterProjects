import { Form } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

import { store } from '@/actions/App/Http/Controllers/BoardColumnController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type CreateColumnDialogProps = {
    projectKey: string;
};

/**
 * Owner-only "add column" trigger, rendered after the last column.
 * Always appends at the end of the board (`BoardColumn::nextPositionInProject`).
 */
export function CreateColumnDialog({ projectKey }: CreateColumnDialogProps) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className="h-fit w-72 shrink-0 justify-start text-muted-foreground"
                >
                    <Plus />
                    Nueva columna
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nueva columna</DialogTitle>
                </DialogHeader>

                <Form
                    {...store.form(projectKey)}
                    resetOnSuccess
                    resetOnError
                    onSuccess={() => setOpen(false)}
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
                                    placeholder="Review"
                                    autoFocus
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
                                    {processing ? 'Creando…' : 'Crear columna'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
