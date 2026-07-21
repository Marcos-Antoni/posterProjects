import { Form } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';

import { store } from '@/actions/App/Http/Controllers/ProjectController';
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

/**
 * "Nuevo proyecto" dialog: creates a project with the default board
 * columns (To Do, In Progress, Done) via `ProjectController::store`.
 */
export function CreateProjectDialog() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button">
                    <Plus />
                    Nuevo proyecto
                </Button>
            </DialogTrigger>

            <DialogContent>
                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2, ease: 'easeOut' }}
                >
                    <DialogHeader>
                        <DialogTitle>Nuevo proyecto</DialogTitle>
                        <DialogDescription>
                            Se crea con el tablero por defecto: To Do, In
                            Progress y Done.
                        </DialogDescription>
                    </DialogHeader>

                    <Form
                        {...store.form()}
                        resetOnSuccess
                        resetOnError
                        onSuccess={() => setOpen(false)}
                        className="mt-4 flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <ProjectFormFields errors={errors} />

                                <DialogFooter>
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Creando…'
                                            : 'Crear proyecto'}
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
