import { Form } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';

import { forceDelete } from '@/actions/App/Http/Controllers/ProjectController';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Project } from '@/types/models';

type ForceDeleteDialogProps = {
    project: Project & { issues_count: number; sprints_count: number };
};

/**
 * GitHub-style destructive confirmation: the owner must type the
 * project's exact key before "Borrar definitivamente" enables. Permanently
 * deletes the project and, via DB cascade, all of its board columns,
 * sprints, labels, memberships, issues, and comments.
 */
export function ForceDeleteDialog({ project }: ForceDeleteDialogProps) {
    const [open, setOpen] = useState(false);
    const [confirmation, setConfirmation] = useState('');

    const canDelete = confirmation === project.key;

    function handleOpenChange(nextOpen: boolean) {
        setOpen(nextOpen);

        if (!nextOpen) {
            setConfirmation('');
        }
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>
                <Button type="button" variant="destructive" size="sm">
                    <Trash2 />
                    Borrar definitivamente
                </Button>
            </DialogTrigger>

            <DialogContent>
                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2, ease: 'easeOut' }}
                    className="flex flex-col gap-4"
                >
                    <DialogHeader>
                        <DialogTitle>
                            ¿Borrar {project.name} para siempre?
                        </DialogTitle>
                        <DialogDescription>
                            Esta acción no se puede deshacer. Vas a perder{' '}
                            <strong>{project.issues_count}</strong>{' '}
                            {project.issues_count === 1 ? 'tarea' : 'tareas'},{' '}
                            <strong>{project.sprints_count}</strong>{' '}
                            {project.sprints_count === 1 ? 'sprint' : 'sprints'}{' '}
                            y todo lo demás asociado al proyecto (columnas,
                            etiquetas, comentarios).
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="confirmation">
                            Escribí <strong>{project.key}</strong> para
                            confirmar
                        </Label>
                        <Input
                            id="confirmation"
                            value={confirmation}
                            onChange={(event) =>
                                setConfirmation(event.target.value)
                            }
                            autoComplete="off"
                            placeholder={project.key}
                        />
                    </div>

                    <Form
                        {...forceDelete.form(project.id)}
                        onSuccess={() => handleOpenChange(false)}
                    >
                        {({ processing }) => (
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={!canDelete || processing}
                                >
                                    {processing
                                        ? 'Borrando…'
                                        : 'Borrar definitivamente'}
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </motion.div>
            </DialogContent>
        </Dialog>
    );
}
