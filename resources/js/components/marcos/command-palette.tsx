import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import { destroy as logout } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { SearchIcon } from '@/components/marcos/icons';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { today as habitsToday } from '@/routes/habits';
import {
    index as objectivesIndex,
    show as objectiveShow,
} from '@/routes/objectives';
import { show as mcpTokenShow } from '@/routes/settings/mcp-token';

type Entry = { label: string; hint?: string; href: string };

/**
 * "Ir a…" (the appbar's Ctrl K button): jump to a section or to one of the
 * owner's ACTIVE objectives — the navigation objective list of the projects
 * spec lives here, so the appbar stays as short as the mockups draw it.
 * Opens with the button or Ctrl/⌘ K; never opens by itself.
 */
export function CommandPalette() {
    const { navigationObjectives } = usePage().props;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                (event.ctrlKey || event.metaKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                setOpen(true);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => router.on('start', () => setOpen(false)), []);

    const entries = useMemo<Entry[]>(
        () => [
            ...navigationObjectives.map((objective) => ({
                label: objective.title,
                hint: objective.key,
                href: objectiveShow(objective.key).url,
            })),
            {
                label: 'Objetivos',
                hint: 'Sección',
                href: objectivesIndex().url,
            },
            { label: 'Hábitos', hint: 'Sección', href: habitsToday().url },
            { label: 'Ajustes', hint: 'Sección', href: mcpTokenShow().url },
        ],
        [navigationObjectives],
    );

    const needle = query.trim().toLowerCase();
    const visible = entries.filter(
        (entry) =>
            needle === '' ||
            entry.label.toLowerCase().includes(needle) ||
            (entry.hint ?? '').toLowerCase().includes(needle),
    );

    return (
        <>
            <button
                className="btn-sm btn-ghost hide-md"
                type="button"
                aria-label="Paleta de comandos: ir a un objetivo o sección"
                onClick={() => setOpen(true)}
            >
                <SearchIcon />
                <kbd>Ctrl K</kbd>
            </button>
            <Dialog
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    setQuery('');
                }}
            >
                <DialogContent className="mos gap-3">
                    <DialogTitle>Ir a…</DialogTitle>
                    <DialogDescription className="text-sm text-muted-foreground">
                        Tus objetivos activos y las secciones.
                    </DialogDescription>
                    <input
                        className="in"
                        autoFocus
                        placeholder="Escribí para filtrar"
                        aria-label="Filtrar"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <ul className="grid max-h-[50vh] gap-0.5 overflow-y-auto">
                        {visible.map((entry) => (
                            <li key={entry.href}>
                                <Link
                                    href={entry.href}
                                    className="flex min-h-10 items-center justify-between gap-3 rounded-sm px-3 py-2 text-foreground no-underline hover:bg-sunken"
                                >
                                    <span className="font-semibold">
                                        {entry.label}
                                    </span>
                                    {entry.hint && (
                                        <span className="text-xs text-muted-foreground">
                                            {entry.hint}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                        {visible.length === 0 && (
                            <li className="px-3 py-2 text-sm text-muted-foreground">
                                Nada coincide.
                            </li>
                        )}
                    </ul>
                    <Link
                        href={logout()}
                        as="button"
                        className="linkbtn quiet justify-self-start"
                    >
                        Cerrar sesión
                    </Link>
                </DialogContent>
            </Dialog>
        </>
    );
}
