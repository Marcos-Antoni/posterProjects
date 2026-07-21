import { Form } from '@inertiajs/react';

import { update } from '@/actions/App/Http/Controllers/SprintController';
import { SprintFormFields } from '@/components/backlog/sprint-form-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BacklogSprint } from '@/types/backlog';

type EditSprintDialogProps = {
    projectKey: string;
    sprint: Pick<
        BacklogSprint,
        'id' | 'name' | 'goal' | 'start_date' | 'end_date'
    >;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/** Owner-only "edit sprint" dialog, opened from `SprintManagementMenu`. */
export function EditSprintDialog({
    projectKey,
    sprint,
    open,
    onOpenChange,
}: EditSprintDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Editar sprint</DialogTitle>
                </DialogHeader>

                <Form
                    {...update.form({ project: projectKey, sprint: sprint.id })}
                    onSuccess={() => onOpenChange(false)}
                    className="mt-4 flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <SprintFormFields
                                errors={errors}
                                defaults={{
                                    name: sprint.name,
                                    goal: sprint.goal,
                                    start_date: sprint.start_date,
                                    end_date: sprint.end_date,
                                }}
                            />

                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Guardando…'
                                        : 'Guardar cambios'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
