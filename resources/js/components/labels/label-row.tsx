import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { DeleteLabelDialog } from '@/components/labels/delete-label-dialog';
import { RenameLabelDialog } from '@/components/labels/rename-label-dialog';
import { Button } from '@/components/ui/button';

type LabelRowProps = {
    projectKey: string;
    label: { id: number; name: string; issues_count: number };
    isOwner: boolean;
};

/**
 * A single row in the label management list: name + how many issues wear
 * it. Rename/delete only render for the project owner — creating a label
 * stays a modal-only action for any member (`labels-picker.tsx`), this
 * page never exposes a "create" form.
 */
export function LabelRow({ projectKey, label, isOwner }: LabelRowProps) {
    const [renameOpen, setRenameOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    return (
        <div className="flex items-center gap-2 rounded-lg border bg-card px-3 py-2">
            <span className="min-w-0 flex-1 truncate text-sm font-medium">
                {label.name}
            </span>
            <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                {label.issues_count}{' '}
                {label.issues_count === 1 ? 'tarea' : 'tareas'}
            </span>

            {isOwner ? (
                <div className="flex shrink-0 items-center gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        onClick={() => setRenameOpen(true)}
                        aria-label={`Renombrar la etiqueta ${label.name}`}
                    >
                        <Pencil />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        onClick={() => setDeleteOpen(true)}
                        aria-label={`Borrar la etiqueta ${label.name}`}
                    >
                        <Trash2 />
                    </Button>
                </div>
            ) : null}

            {isOwner ? (
                <>
                    <RenameLabelDialog
                        projectKey={projectKey}
                        label={label}
                        open={renameOpen}
                        onOpenChange={setRenameOpen}
                    />
                    <DeleteLabelDialog
                        projectKey={projectKey}
                        label={label}
                        open={deleteOpen}
                        onOpenChange={setDeleteOpen}
                    />
                </>
            ) : null}
        </div>
    );
}
