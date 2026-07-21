import { router } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

import {
    destroy as detachLabel,
    store as attachLabel,
} from '@/actions/App/Http/Controllers/IssueLabelController';
import { store as createLabel } from '@/actions/App/Http/Controllers/LabelController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { BoardLabel } from '@/types/board';

type LabelsPickerProps = {
    projectKey: string;
    issueId: number;
    attachedLabels: BoardLabel[];
    allLabels: BoardLabel[];
};

/**
 * The issue modal's label chips + attach/detach/create-inline popover.
 * Any project member may manage labels here — the owner-only rename/delete
 * management screen ships in T-10.5. Creating a label that doesn't exist
 * yet is a two-step round trip (`LabelController::store` stays a pure
 * "create a label" resource action per T-10.5's own instructions): create,
 * then read the freshly created label back out of the reloaded `labels`
 * page prop (via `onSuccess`) to immediately attach it — a single combined
 * endpoint was deliberately NOT added to keep `IssueLabelController` and
 * `LabelController` each single-purpose.
 */
export function LabelsPicker({
    projectKey,
    issueId,
    attachedLabels,
    allLabels,
}: LabelsPickerProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    const attachedIds = new Set(attachedLabels.map((label) => label.id));
    const normalizedQuery = query.trim().toLowerCase();

    const candidates = allLabels.filter(
        (label) =>
            !attachedIds.has(label.id) &&
            label.name.toLowerCase().includes(normalizedQuery),
    );

    const hasExactMatch = allLabels.some(
        (label) => label.name.toLowerCase() === normalizedQuery,
    );
    const canCreate = normalizedQuery !== '' && !hasExactMatch;

    function detach(label: BoardLabel) {
        router.delete(
            detachLabel.url({
                project: projectKey,
                issue: issueId,
                label: label.id,
            }),
            { preserveScroll: true, preserveState: true },
        );
    }

    function attach(label: BoardLabel) {
        router.post(
            attachLabel.url({ project: projectKey, issue: issueId }),
            { label_id: label.id },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setQuery('');
                    setOpen(false);
                },
            },
        );
    }

    function createAndAttach() {
        const name = query.trim();

        if (name === '') {
            return;
        }

        router.post(
            createLabel.url({ project: projectKey }),
            { name },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const freshLabels =
                        (page.props.labels as BoardLabel[] | undefined) ?? [];
                    const created = freshLabels.find(
                        (label) => label.name === name,
                    );

                    if (created) {
                        attach(created);
                    }

                    setQuery('');
                    setOpen(false);
                },
            },
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {attachedLabels.map((label) => (
                <span
                    key={label.id}
                    className="flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                >
                    {label.name}
                    <button
                        type="button"
                        onClick={() => detach(label)}
                        aria-label={`Quitar la etiqueta ${label.name}`}
                        className="rounded-full hover:text-foreground"
                    >
                        <X className="size-3" />
                    </button>
                </span>
            ))}

            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Agregar etiqueta"
                    >
                        <Plus className="size-3.5" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-56 p-2" align="start">
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Buscar o crear etiqueta…"
                        autoFocus
                    />
                    <ul className="mt-2 flex max-h-40 flex-col gap-0.5 overflow-y-auto">
                        {candidates.map((label) => (
                            <li key={label.id}>
                                <button
                                    type="button"
                                    onClick={() => attach(label)}
                                    className="w-full rounded-md px-2 py-1 text-left text-sm hover:bg-accent"
                                >
                                    {label.name}
                                </button>
                            </li>
                        ))}
                    </ul>
                    {canCreate ? (
                        <button
                            type="button"
                            onClick={createAndAttach}
                            className="mt-1 w-full rounded-md px-2 py-1 text-left text-sm text-muted-foreground hover:bg-accent"
                        >
                            Crear “{query.trim()}”
                        </button>
                    ) : null}
                </PopoverContent>
            </Popover>
        </div>
    );
}
