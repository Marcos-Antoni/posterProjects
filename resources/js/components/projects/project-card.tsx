import { router } from '@inertiajs/react';
import { motion } from 'motion/react';
import { useState } from 'react';

import { destroy } from '@/actions/App/Http/Controllers/ProjectController';
import { EditProjectDialog } from '@/components/projects/edit-project-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { Project } from '@/types/models';

type ProjectCardProps = {
    project: Project & { issues_count: number };
    isOwner: boolean;
};

/**
 * A single project tile in the /projects grid. Owners get "Editar" and
 * "Archivar" (one click, no confirmation — archiving is reversible from
 * the trash page).
 */
export function ProjectCard({ project, isOwner }: ProjectCardProps) {
    const [isArchiving, setIsArchiving] = useState(false);

    function archive() {
        setIsArchiving(true);

        router.delete(destroy.url(project.id), {
            preserveScroll: true,
            onFinish: () => setIsArchiving(false),
        });
    }

    return (
        <motion.div
            layout
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.96 }}
            transition={{ duration: 0.2, ease: 'easeOut' }}
        >
            <Card className="h-full">
                <CardHeader>
                    <CardTitle>{project.name}</CardTitle>
                    <CardDescription>{project.key}</CardDescription>
                </CardHeader>

                <CardContent>
                    <p className="line-clamp-2 text-sm text-muted-foreground">
                        {project.description ?? 'Sin descripción.'}
                    </p>
                    <p className="mt-3 text-xs text-muted-foreground">
                        {project.issues_count}{' '}
                        {project.issues_count === 1 ? 'tarea' : 'tareas'}
                    </p>
                </CardContent>

                {isOwner ? (
                    <CardFooter className="justify-between">
                        <EditProjectDialog project={project} />

                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            disabled={isArchiving}
                            onClick={archive}
                        >
                            {isArchiving ? 'Archivando…' : 'Archivar'}
                        </Button>
                    </CardFooter>
                ) : null}
            </Card>
        </motion.div>
    );
}
