import { Head, usePage } from '@inertiajs/react';
import type { ReactElement } from 'react';

import { CreateSprintDialog } from '@/components/backlog/create-sprint-dialog';
import { IssueRow } from '@/components/backlog/issue-row';
import { SprintSection } from '@/components/backlog/sprint-section';
import AppLayout from '@/layouts/app-layout';
import type { BacklogIssue, BacklogSprint } from '@/types/backlog';
import type { Project } from '@/types/models';

type BacklogPageProps = {
    project: Pick<Project, 'id' | 'key' | 'name' | 'owner_id'>;
    sprints: BacklogSprint[];
    backlogIssues: BacklogIssue[];
};

/**
 * A project's backlog: every sprint (collapsible, with its goal, date
 * range, and story-point sum) plus the Backlog section itself (issues
 * with no sprint). Reassigning an issue reuses the same
 * `IssueController::update` endpoint the board's modal uses — see
 * `IssueRow`. Sprint management (create/edit/delete) is owner only.
 */
export default function Backlog({
    project,
    sprints,
    backlogIssues,
}: BacklogPageProps) {
    const { props } = usePage();
    const isOwner = props.auth.user.id === project.owner_id;
    const sprintOptions = sprints.map((sprint) => ({
        id: sprint.id,
        name: sprint.name,
    }));

    return (
        <>
            <Head title={`Backlog — ${project.name}`} />

            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="font-heading text-2xl font-medium">
                            {project.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Backlog · {project.key}
                        </p>
                    </div>

                    {isOwner ? (
                        <CreateSprintDialog projectKey={project.key} />
                    ) : null}
                </div>

                <div className="flex flex-col gap-3">
                    {sprints.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Todavía no hay sprints en este proyecto.
                        </p>
                    ) : (
                        sprints.map((sprint) => (
                            <SprintSection
                                key={sprint.id}
                                projectKey={project.key}
                                sprint={sprint}
                                sprintOptions={sprintOptions}
                                isOwner={isOwner}
                            />
                        ))
                    )}
                </div>

                <div className="flex flex-col gap-2 rounded-xl border bg-card p-3">
                    <h2 className="font-heading text-lg font-medium">
                        Backlog
                    </h2>

                    {backlogIssues.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No hay tareas sin sprint.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            {backlogIssues.map((issue) => (
                                <IssueRow
                                    key={issue.id}
                                    projectKey={project.key}
                                    issue={issue}
                                    sprintOptions={sprintOptions}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

Backlog.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
