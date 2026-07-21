import { Head, usePage } from '@inertiajs/react';
import type { ReactElement } from 'react';

import { LabelRow } from '@/components/labels/label-row';
import AppLayout from '@/layouts/app-layout';
import type { Label, Project } from '@/types/models';

type LabelsPageProps = {
    project: Pick<Project, 'id' | 'key' | 'name' | 'owner_id'>;
    labels: Array<Pick<Label, 'id' | 'name'> & { issues_count: number }>;
};

/**
 * A project's label management screen (`LabelController::index`): every
 * label with how many issues currently wear it. Any member may view it;
 * only the owner sees rename/delete controls (`LabelPolicy`). Creating a
 * label stays a modal-only action for any member — the inline picker in
 * the issue modal (`labels-picker.tsx`), not this page.
 */
export default function Labels({ project, labels }: LabelsPageProps) {
    const { props } = usePage();
    const isOwner = props.auth.user.id === project.owner_id;

    return (
        <>
            <Head title={`Etiquetas — ${project.name}`} />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="font-heading text-2xl font-medium">
                        {project.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Etiquetas · {project.key}
                    </p>
                </div>

                {labels.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Todavía no hay etiquetas en este proyecto. Se crean
                        desde una tarea, con el botón “+” junto a sus etiquetas.
                    </p>
                ) : (
                    <div className="flex flex-col gap-2">
                        {labels.map((label) => (
                            <LabelRow
                                key={label.id}
                                projectKey={project.key}
                                label={label}
                                isOwner={isOwner}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

Labels.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
