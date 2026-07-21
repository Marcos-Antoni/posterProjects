import { Form } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

import { store } from '@/actions/App/Http/Controllers/IssueController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type QuickAddIssueProps = {
    projectKey: string;
    boardColumnId: number;
    sprintId: number | null;
};

/**
 * Per-column "add issue" form at the foot of the board. Only asks for a
 * title — everything else (Task type, Medium priority, reporter,
 * position) defaults server-side. Stays open after a successful add so
 * multiple issues can be typed in quick succession.
 */
export function QuickAddIssue({
    projectKey,
    boardColumnId,
    sprintId,
}: QuickAddIssueProps) {
    const [isAdding, setIsAdding] = useState(false);

    if (!isAdding) {
        return (
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="justify-start text-muted-foreground"
                onClick={() => setIsAdding(true)}
            >
                <Plus />
                Añadir tarea
            </Button>
        );
    }

    return (
        <Form
            {...store.form(projectKey)}
            resetOnSuccess
            resetOnError
            className="flex flex-col gap-2"
        >
            {({ errors, processing }) => (
                <>
                    <input
                        type="hidden"
                        name="board_column_id"
                        value={boardColumnId}
                    />
                    <input
                        type="hidden"
                        name="sprint_id"
                        value={sprintId ?? ''}
                    />

                    <Input
                        name="title"
                        placeholder="Título de la tarea"
                        autoFocus
                        aria-invalid={Boolean(errors.title)}
                    />
                    {errors.title ? (
                        <p className="text-xs text-destructive">
                            {errors.title}
                        </p>
                    ) : null}

                    <div className="flex items-center gap-2">
                        <Button type="submit" size="sm" disabled={processing}>
                            {processing ? 'Agregando…' : 'Agregar'}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            onClick={() => setIsAdding(false)}
                        >
                            <X />
                            <span className="sr-only">Cancelar</span>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
