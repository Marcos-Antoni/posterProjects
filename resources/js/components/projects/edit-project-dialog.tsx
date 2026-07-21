import { Form } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';

import { update } from '@/actions/App/Http/Controllers/ProjectController';
import { ProjectFormFields } from '@/components/projects/project-form-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { Project } from '@/types/models';

type EditProjectDialogProps = {
    project: Project;
};

/**
 * "Editar" dialog for the project's owner — updates key, name, and
 * description via `ProjectController::update`.
 */
export function EditProjectDialog({ project }: EditProjectDialogProps) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <Pencil />
                    Editar
                </Button>
            </DialogTrigger>

            <DialogContent>
                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2, ease: 'easeOut' }}
                >
                    <DialogHeader>
                        <DialogTitle>Editar proyecto</DialogTitle>
                        <DialogDescription>
                            Actualizá la clave, el nombre o la descripción de{' '}
                            {project.name}.
                        </DialogDescription>
                    </DialogHeader>

                    <Form
                        {...update.form(project.id)}
                        onSuccess={() => setOpen(false)}
                        className="mt-4 flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <ProjectFormFields
                                    errors={errors}
                                    defaults={{
                                        key: project.key,
                                        name: project.name,
                                        description: project.description,
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
                </motion.div>
            </DialogContent>
        </Dialog>
    );
}
