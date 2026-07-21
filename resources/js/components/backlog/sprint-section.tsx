import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

import { IssueRow } from '@/components/backlog/issue-row';
import { SprintManagementMenu } from '@/components/backlog/sprint-management-menu';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { parseIsoDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import type { BacklogSprint } from '@/types/backlog';

type SprintSectionProps = {
    projectKey: string;
    sprint: BacklogSprint;
    sprintOptions: Array<{ id: number; name: string }>;
    isOwner: boolean;
};

function formatDateRange(sprint: BacklogSprint): string {
    const start = parseIsoDate(sprint.start_date).toLocaleDateString('es-AR');
    const end = parseIsoDate(sprint.end_date).toLocaleDateString('es-AR');

    return `${start} – ${end}`;
}

/**
 * A single collapsible sprint: name, goal, date range, story-point sum,
 * and its issues (each with a "move to" select — see `IssueRow`). Sprint
 * management (edit/delete) only renders for the project owner.
 */
export function SprintSection({
    projectKey,
    sprint,
    sprintOptions,
    isOwner,
}: SprintSectionProps) {
    const [open, setOpen] = useState(true);

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="rounded-xl border bg-card"
        >
            <div className="flex items-center justify-between gap-2 p-3">
                <CollapsibleTrigger className="flex min-w-0 flex-1 items-center gap-2 text-left">
                    <ChevronDown
                        className={cn(
                            'size-4 shrink-0 text-muted-foreground transition-transform',
                            !open && '-rotate-90',
                        )}
                    />
                    <div className="min-w-0">
                        <p className="truncate font-medium">
                            {sprint.name}{' '}
                            <span className="font-normal text-muted-foreground">
                                ({formatDateRange(sprint)})
                            </span>
                        </p>
                        {sprint.goal ? (
                            <p className="truncate text-xs text-muted-foreground">
                                {sprint.goal}
                            </p>
                        ) : null}
                    </div>
                </CollapsibleTrigger>

                <div className="flex shrink-0 items-center gap-2">
                    <span className="text-xs text-muted-foreground">
                        {sprint.story_points_sum} pts · {sprint.issues.length}{' '}
                        {sprint.issues.length === 1 ? 'tarea' : 'tareas'}
                    </span>
                    {isOwner ? (
                        <SprintManagementMenu
                            projectKey={projectKey}
                            sprint={sprint}
                        />
                    ) : null}
                </div>
            </div>

            <CollapsibleContent className="flex flex-col gap-2 border-t p-3">
                {sprint.issues.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Sin tareas en este sprint.
                    </p>
                ) : (
                    sprint.issues.map((issue) => (
                        <IssueRow
                            key={issue.id}
                            projectKey={projectKey}
                            issue={issue}
                            sprintOptions={sprintOptions}
                        />
                    ))
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}
