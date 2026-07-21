import { Head, Link, usePage } from '@inertiajs/react';
import { AnimatePresence } from 'motion/react';
import type { ReactElement } from 'react';

import { CreateProjectDialog } from '@/components/projects/create-project-dialog';
import { ProjectCard } from '@/components/projects/project-card';
import AppLayout from '@/layouts/app-layout';
import { trash } from '@/routes/projects';
import type { Project } from '@/types/models';

type ProjectsIndexProps = {
    projects: Array<Project & { issues_count: number }>;
};

export default function ProjectsIndex({ projects }: ProjectsIndexProps) {
    const { props } = usePage();
    const currentUserId = props.auth.user.id;

    return (
        <>
            <Head title="Proyectos" />

            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="font-heading text-2xl font-medium">
                            Proyectos
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Los proyectos de los que formás parte.
                        </p>
                    </div>

                    <div className="flex items-center gap-4">
                        <Link
                            href={trash()}
                            className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        >
                            Papelera
                        </Link>
                        <CreateProjectDialog />
                    </div>
                </div>

                {projects.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Todavía no formás parte de ningún proyecto. Creá el
                        primero con el botón de arriba.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <AnimatePresence mode="popLayout">
                            {projects.map((project) => (
                                <ProjectCard
                                    key={project.id}
                                    project={project}
                                    isOwner={project.owner_id === currentUserId}
                                />
                            ))}
                        </AnimatePresence>
                    </div>
                )}
            </div>
        </>
    );
}

ProjectsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
