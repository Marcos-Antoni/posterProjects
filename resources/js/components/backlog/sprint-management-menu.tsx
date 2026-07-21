import { MoreVertical } from 'lucide-react';
import { useState } from 'react';

import { DeleteSprintDialog } from '@/components/backlog/delete-sprint-dialog';
import { EditSprintDialog } from '@/components/backlog/edit-sprint-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { BacklogSprint } from '@/types/backlog';

type SprintManagementMenuProps = {
    projectKey: string;
    sprint: BacklogSprint;
};

/**
 * Owner-only sprint controls: edit and delete. `SprintSection` only
 * renders this when `isOwner` is true — non-owners never see it.
 */
export function SprintManagementMenu({
    projectKey,
    sprint,
}: SprintManagementMenuProps) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button type="button" variant="ghost" size="icon-xs">
                        <MoreVertical />
                        <span className="sr-only">Gestionar sprint</span>
                    </Button>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => setEditOpen(true)}>
                        Editar
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => setDeleteOpen(true)}
                    >
                        Borrar sprint
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <EditSprintDialog
                projectKey={projectKey}
                sprint={sprint}
                open={editOpen}
                onOpenChange={setEditOpen}
            />
            <DeleteSprintDialog
                projectKey={projectKey}
                sprint={sprint}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
            />
        </>
    );
}
