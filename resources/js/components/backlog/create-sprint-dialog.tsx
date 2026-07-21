import { Form } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

import { store } from '@/actions/App/Http/Controllers/SprintController';
import { SprintFormFields } from '@/components/backlog/sprint-form-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

type CreateSprintDialogProps = {
    projectKey: string;
};

/** Owner-only "add sprint" trigger, rendered above the sprint list. */
export function CreateSprintDialog({ projectKey }: CreateSprintDialogProps) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button">
                    <Plus />
                    Nuevo sprint
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nuevo sprint</DialogTitle>
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
                            <SprintFormFields errors={errors} />

                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creando…' : 'Crear sprint'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
