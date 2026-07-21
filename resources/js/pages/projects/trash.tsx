import { Form, Head, Link } from '@inertiajs/react';
import { AnimatePresence, motion } from 'motion/react';
import type { ReactElement } from 'react';

import { ForceDeleteDialog } from '@/components/projects/force-delete-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { index as projectsIndex, restore } from '@/routes/projects';
import type { Project } from '@/types/models';

type ArchivedProject = Project & {
    issues_count: number;
    sprints_count: number;
};

type ProjectsTrashProps = {
    projects: ArchivedProject[];
};

export default function ProjectsTrash({ projects }: ProjectsTrashProps) {
    return (
        <>
            <Head title="Papelera de proyectos" />

            <div className="flex flex-col gap-6">
                <div>
                    <Link
                        href={projectsIndex()}
                        className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        ← Volver a proyectos
                    </Link>
                    <h1 className="mt-2 font-heading text-2xl font-medium">
                        Papelera
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Proyectos archivados. Podés restaurarlos o borrarlos
                        para siempre.
                    </p>
                </div>

                {projects.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        La papelera está vacía.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <AnimatePresence mode="popLayout">
                            {projects.map((project) => (
                                <motion.div
                                    key={project.id}
                                    layout
                                    initial={{ opacity: 0, y: 8 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0, scale: 0.96 }}
                                    transition={{
                                        duration: 0.2,
                                        ease: 'easeOut',
                                    }}
                                >
                                    <Card className="h-full">
                                        <CardHeader>
                                            <CardTitle>
                                                {project.name}
                                            </CardTitle>
                                            <CardDescription>
                                                {project.key}
                                            </CardDescription>
                                        </CardHeader>

                                        <CardContent>
                                            <p className="text-sm text-muted-foreground">
                                                Archivado el{' '}
                                                {project.deleted_at
                                                    ? new Date(
                                                          project.deleted_at,
                                                      ).toLocaleDateString(
                                                          'es-AR',
                                                      )
                                                    : '—'}
                                            </p>
                                        </CardContent>

                                        <CardFooter className="flex-col items-stretch gap-2 sm:flex-row sm:justify-between">
                                            <Form {...restore.form(project.id)}>
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        {processing
                                                            ? 'Restaurando…'
                                                            : 'Restaurar'}
                                                    </Button>
                                                )}
                                            </Form>

                                            <ForceDeleteDialog
                                                project={project}
                                            />
                                        </CardFooter>
                                    </Card>
                                </motion.div>
                            ))}
                        </AnimatePresence>
                    </div>
                )}
            </div>
        </>
    );
}

ProjectsTrash.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
