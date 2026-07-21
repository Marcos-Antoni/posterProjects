import { Form } from '@inertiajs/react';
import { useState } from 'react';

import { destroy } from '@/actions/App/Http/Controllers/BoardColumnController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { BoardColumn } from '@/types/models';

type DeleteColumnDialogProps = {
    projectKey: string;
    column: Pick<BoardColumn, 'id' | 'name'> & { issuesCount: number };
    otherColumns: Array<Pick<BoardColumn, 'id' | 'name'>>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Owner-only "delete column" confirmation. When the column still has
 * issues, a destination column is required — the server moves every one
 * of them there (appended to the bottom of its own sprint scope) before
 * deleting the column, in a single transaction (`issues.board_column_id`
 * is `restrict`, there's no FK cascade to lean on).
 */
export function DeleteColumnDialog({
    projectKey,
    column,
    otherColumns,
    open,
    onOpenChange,
}: DeleteColumnDialogProps) {
    const [destinationId, setDestinationId] = useState('');
    const hasIssues = column.issuesCount > 0;

    function handleOpenChange(nextOpen: boolean) {
        onOpenChange(nextOpen);

        if (!nextOpen) {
            setDestinationId('');
        }
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        ¿Borrar la columna “{column.name}”?
                    </DialogTitle>
                    <DialogDescription>
                        {hasIssues
                            ? `Esta columna tiene ${column.issuesCount} ${column.issuesCount === 1 ? 'tarea' : 'tareas'}. Elegí a qué columna se mueven antes de borrarla.`
                            : 'Esta acción no se puede deshacer.'}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...destroy.form({
                        project: projectKey,
                        boardColumn: column.id,
                    })}
                    onSuccess={() => handleOpenChange(false)}
                    className="mt-4 flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            {hasIssues ? (
                                <div className="grid gap-2">
                                    <Select
                                        value={destinationId}
                                        onValueChange={setDestinationId}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Columna destino" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {otherColumns.map((candidate) => (
                                                <SelectItem
                                                    key={candidate.id}
                                                    value={String(candidate.id)}
                                                >
                                                    {candidate.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <input
                                        type="hidden"
                                        name="destination_board_column_id"
                                        value={destinationId}
                                    />
                                    {errors.destination_board_column_id ? (
                                        <p className="text-sm text-destructive">
                                            {errors.destination_board_column_id}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}

                            <DialogFooter>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={
                                        processing ||
                                        (hasIssues && destinationId === '')
                                    }
                                >
                                    {processing
                                        ? 'Borrando…'
                                        : 'Borrar columna'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
