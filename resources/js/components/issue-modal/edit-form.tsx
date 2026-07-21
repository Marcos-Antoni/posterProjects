import { CalendarIcon } from 'lucide-react';

import { update } from '@/actions/App/Http/Controllers/IssueController';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAutosaveField } from '@/hooks/use-autosave-field';
import { issuePriorityLabels, issueTypeLabels } from '@/lib/issue-meta';
import type { BoardMember, IssueDetail } from '@/types/board';
import type {
    BoardColumn,
    IssuePriority,
    IssueType,
    Sprint,
} from '@/types/models';

const BACKLOG_VALUE = 'backlog';
const UNASSIGNED_VALUE = 'unassigned';

type EditFormProps = {
    projectKey: string;
    issue: IssueDetail;
    columns: Array<Pick<BoardColumn, 'id' | 'name'>>;
    sprints: Sprint[];
    members: BoardMember[];
};

function toIsoDateString(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

/** Parses a "YYYY-MM-DD" string into a local `Date` — never via `new Date(string)`,
 * which parses date-only ISO strings as UTC midnight and can shift the
 * displayed day backward in timezones behind UTC. */
function parseIsoDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

/**
 * The issue modal's select/date/number fields: type, priority, board
 * column, sprint, assignee, story points, due date. Every field
 * auto-saves independently via `IssueController::update` (a
 * `sometimes`-validated partial PATCH) through `useAutosaveField` —
 * changing a select is immediately reflected locally and rolled back only
 * if the server rejects it. Title and description live in
 * `issue-modal.tsx`; `parent_id` has no picker here (see T-9.7 apply
 * notes — the backend validates it, but no UI surfaces it yet).
 */
export function EditForm({
    projectKey,
    issue,
    columns,
    sprints,
    members,
}: EditFormProps) {
    const url = update.url({ project: projectKey, issue: issue.id });

    const type = useAutosaveField<IssueType>(url, 'type', issue.type);
    const priority = useAutosaveField<IssuePriority>(
        url,
        'priority',
        issue.priority,
    );
    const boardColumnId = useAutosaveField<number>(
        url,
        'board_column_id',
        issue.board_column_id,
    );
    const sprintId = useAutosaveField<number | null>(
        url,
        'sprint_id',
        issue.sprint_id,
    );
    const assigneeId = useAutosaveField<number | null>(
        url,
        'assignee_id',
        issue.assignee?.id ?? null,
    );
    const storyPoints = useAutosaveField<number | null>(
        url,
        'story_points',
        issue.story_points,
    );
    const dueDate = useAutosaveField<string | null>(
        url,
        'due_date',
        issue.due_date,
    );

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div className="grid gap-1">
                <span className="text-xs font-medium text-muted-foreground">
                    Tipo
                </span>
                <Select
                    value={type.value}
                    onValueChange={(value) => type.save(value as IssueType)}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {(Object.keys(issueTypeLabels) as IssueType[]).map(
                            (option) => (
                                <SelectItem key={option} value={option}>
                                    {issueTypeLabels[option]}
                                </SelectItem>
                            ),
                        )}
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-1">
                <span className="text-xs font-medium text-muted-foreground">
                    Prioridad
                </span>
                <Select
                    value={String(priority.value)}
                    onValueChange={(value) =>
                        priority.save(Number(value) as IssuePriority)
                    }
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {Object.keys(issuePriorityLabels)
                            .map(Number)
                            .map((option) => (
                                <SelectItem key={option} value={String(option)}>
                                    {
                                        issuePriorityLabels[
                                            option as IssuePriority
                                        ]
                                    }
                                </SelectItem>
                            ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-1">
                <span className="text-xs font-medium text-muted-foreground">
                    Columna
                </span>
                <Select
                    value={String(boardColumnId.value)}
                    onValueChange={(value) => boardColumnId.save(Number(value))}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {columns.map((column) => (
                            <SelectItem
                                key={column.id}
                                value={String(column.id)}
                            >
                                {column.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-1">
                <span className="text-xs font-medium text-muted-foreground">
                    Sprint
                </span>
                <Select
                    value={
                        sprintId.value === null
                            ? BACKLOG_VALUE
                            : String(sprintId.value)
                    }
                    onValueChange={(value) =>
                        sprintId.save(
                            value === BACKLOG_VALUE ? null : Number(value),
                        )
                    }
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={BACKLOG_VALUE}>Backlog</SelectItem>
                        {sprints.map((sprint) => (
                            <SelectItem
                                key={sprint.id}
                                value={String(sprint.id)}
                            >
                                {sprint.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-1">
                <span className="text-xs font-medium text-muted-foreground">
                    Asignado a
                </span>
                <Select
                    value={
                        assigneeId.value === null
                            ? UNASSIGNED_VALUE
                            : String(assigneeId.value)
                    }
                    onValueChange={(value) =>
                        assigneeId.save(
                            value === UNASSIGNED_VALUE ? null : Number(value),
                        )
                    }
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={UNASSIGNED_VALUE}>
                            Sin asignar
                        </SelectItem>
                        {members.map((member) => (
                            <SelectItem
                                key={member.id}
                                value={String(member.id)}
                            >
                                {member.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-1">
                <span className="text-xs font-medium text-muted-foreground">
                    Puntos de historia
                </span>
                <Input
                    type="number"
                    min={0}
                    value={storyPoints.value ?? ''}
                    onChange={(event) => {
                        const raw = event.target.value;

                        storyPoints.save(raw === '' ? null : Number(raw));
                    }}
                />
            </div>

            <div className="col-span-2 grid gap-1 sm:col-span-1">
                <span className="text-xs font-medium text-muted-foreground">
                    Fecha límite
                </span>
                <Popover>
                    <PopoverTrigger asChild>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full justify-start font-normal"
                        >
                            <CalendarIcon className="size-4" />
                            {dueDate.value
                                ? parseIsoDate(
                                      dueDate.value,
                                  ).toLocaleDateString('es-AR')
                                : 'Sin fecha'}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-0" align="start">
                        <Calendar
                            mode="single"
                            selected={
                                dueDate.value
                                    ? parseIsoDate(dueDate.value)
                                    : undefined
                            }
                            onSelect={(date) =>
                                dueDate.save(
                                    date ? toIsoDateString(date) : null,
                                )
                            }
                        />
                    </PopoverContent>
                </Popover>
            </div>
        </div>
    );
}
