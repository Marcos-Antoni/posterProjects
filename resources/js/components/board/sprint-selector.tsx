import { router } from '@inertiajs/react';
import type { ChangeEvent } from 'react';

import type { Sprint } from '@/types/models';

type SprintSelectorProps = {
    sprints: Sprint[];
    selectedSprintId: number | null;
    activeSprintId: number | null;
};

function formatDateRange(sprint: Sprint): string {
    const start = new Date(sprint.start_date).toLocaleDateString('es-AR');
    const end = new Date(sprint.end_date).toLocaleDateString('es-AR');

    return `${start} – ${end}`;
}

/**
 * Filters the board by sprint (or Backlog). Partial-reloads only the
 * columns/selection props via `router.reload`, keeping the rest of the
 * page (project header, sprint list) untouched.
 */
export function SprintSelector({
    sprints,
    selectedSprintId,
    activeSprintId,
}: SprintSelectorProps) {
    function handleChange(event: ChangeEvent<HTMLSelectElement>) {
        router.reload({
            data: { sprint: event.target.value },
            only: ['columns', 'selectedSprintId', 'activeSprintId'],
        });
    }

    return (
        <select
            value={selectedSprintId ?? ''}
            onChange={handleChange}
            className="h-8 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 dark:bg-input/30"
        >
            <option value="">Backlog</option>
            {sprints.map((sprint) => (
                <option key={sprint.id} value={sprint.id}>
                    {sprint.name} ({formatDateRange(sprint)})
                    {sprint.id === activeSprintId ? ' · activo' : ''}
                </option>
            ))}
        </select>
    );
}
