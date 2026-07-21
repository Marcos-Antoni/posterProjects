import { router } from '@inertiajs/react';
import { MoreVertical } from 'lucide-react';
import { useState } from 'react';

import { reorder } from '@/actions/App/Http/Controllers/BoardColumnController';
import { DeleteColumnDialog } from '@/components/board/delete-column-dialog';
import { RenameColumnDialog } from '@/components/board/rename-column-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { BoardColumn } from '@/types/models';

type ColumnManagementMenuProps = {
    projectKey: string;
    column: Pick<BoardColumn, 'id' | 'name'> & { issuesCount: number };
    otherColumns: Array<Pick<BoardColumn, 'id' | 'name'>>;
    isFirst: boolean;
    isLast: boolean;
    columnIndex: number;
};

/**
 * Owner-only column controls: rename, move left/right (reorders the
 * board via `BoardColumnController::reorder`), and delete. `BoardColumn`
 * only renders this when `isOwner` is true — non-owners never see it.
 */
export function ColumnManagementMenu({
    projectKey,
    column,
    otherColumns,
    isFirst,
    isLast,
    columnIndex,
}: ColumnManagementMenuProps) {
    const [renameOpen, setRenameOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    function move(targetPosition: number) {
        router.patch(
            reorder.url({ project: projectKey, boardColumn: column.id }),
            { position: targetPosition },
            { preserveScroll: true },
        );
    }

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button type="button" variant="ghost" size="icon-xs">
                        <MoreVertical />
                        <span className="sr-only">Gestionar columna</span>
                    </Button>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => setRenameOpen(true)}>
                        Renombrar
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={isFirst}
                        onSelect={() => move(columnIndex - 1)}
                    >
                        Mover a la izquierda
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={isLast}
                        onSelect={() => move(columnIndex + 1)}
                    >
                        Mover a la derecha
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => setDeleteOpen(true)}
                    >
                        Borrar columna
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <RenameColumnDialog
                projectKey={projectKey}
                column={column}
                open={renameOpen}
                onOpenChange={setRenameOpen}
            />
            <DeleteColumnDialog
                projectKey={projectKey}
                column={column}
                otherColumns={otherColumns}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
            />
        </>
    );
}
