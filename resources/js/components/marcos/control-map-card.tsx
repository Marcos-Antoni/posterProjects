import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import type { ControlMapEntry, ControlZone } from '@/types/models';

const ZONES: { zone: ControlZone; title: string }[] = [
    { zone: 'mine', title: 'Depende de mí' },
    { zone: 'influence', title: 'Puedo influir' },
    { zone: 'outside', title: 'No depende de mí' },
];

type PlanOption = { id: number; title: string };

/**
 * The "Mapa de control" card (mockup 04): three zones. The owner adds and
 * removes entries in place; an entry of the first two zones may become a
 * task of one of the objective's plans, an entry of "No depende de mí" is
 * written down to let it go and is never offered as a task (control-plan
 * spec).
 */
export function ControlMapCard({
    entries,
    storeUrl,
    entryUrl,
    convertUrl,
    plans,
    writable,
}: {
    entries: ControlMapEntry[];
    storeUrl: string;
    entryUrl: (entryId: number) => string;
    convertUrl?: (entryId: number) => string;
    plans: PlanOption[];
    writable: boolean;
}) {
    return (
        <section className="card5" aria-labelledby="cmap-title">
            <h2 id="cmap-title">Mapa de control</h2>
            <div className="cmap">
                {ZONES.map(({ zone, title }) => (
                    <div
                        key={zone}
                        className={zone === 'outside' ? 'zone out' : 'zone'}
                    >
                        <h3>{title}</h3>
                        <ul>
                            {entries
                                .filter((entry) => entry.zone === zone)
                                .map((entry) => (
                                    <EntryRow
                                        key={entry.id}
                                        entry={entry}
                                        entryUrl={entryUrl(entry.id)}
                                        convertUrl={
                                            entry.can_become_task && convertUrl
                                                ? convertUrl(entry.id)
                                                : undefined
                                        }
                                        plans={plans}
                                        writable={writable}
                                    />
                                ))}
                        </ul>
                        {writable && (
                            <AddEntry
                                zone={zone}
                                title={title}
                                storeUrl={storeUrl}
                            />
                        )}
                        {zone === 'outside' && (
                            <p className="note">
                                Esto se anota para soltarlo; no se convierte en
                                tarea.
                            </p>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

function EntryRow({
    entry,
    entryUrl,
    convertUrl,
    plans,
    writable,
}: {
    entry: ControlMapEntry;
    entryUrl: string;
    convertUrl?: string;
    plans: PlanOption[];
    writable: boolean;
}) {
    const [converting, setConverting] = useState(false);

    return (
        <li>
            <span className="flex flex-wrap items-center justify-between gap-2">
                <span>{entry.text}</span>
                {writable && (
                    <span className="acts flex gap-1">
                        {convertUrl && plans.length > 0 && (
                            <button
                                className="linkbtn"
                                type="button"
                                onClick={() => setConverting((open) => !open)}
                                aria-expanded={converting}
                            >
                                Convertir en tarea
                            </button>
                        )}
                        <button
                            className="linkbtn quiet"
                            type="button"
                            aria-label={`Quitar «${entry.text}»`}
                            onClick={() =>
                                router.delete(entryUrl, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Quitar
                        </button>
                    </span>
                )}
            </span>
            {converting && convertUrl && (
                <ConvertForm
                    convertUrl={convertUrl}
                    plans={plans}
                    onCancel={() => setConverting(false)}
                />
            )}
        </li>
    );
}

function ConvertForm({
    convertUrl,
    plans,
    onCancel,
}: {
    convertUrl: string;
    plans: PlanOption[];
    onCancel: () => void;
}) {
    const form = useForm({
        plan_id: String(plans[0]?.id ?? ''),
        two_minute_version: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(convertUrl, { preserveScroll: true });
    };

    return (
        <form className="mt-2 grid gap-2" onSubmit={submit}>
            <label>
                <span className="text-sm font-semibold">Plan</span>
                <select
                    className="in"
                    value={form.data.plan_id}
                    onChange={(e) => form.setData('plan_id', e.target.value)}
                >
                    {plans.map((plan) => (
                        <option key={plan.id} value={plan.id}>
                            {plan.title}
                        </option>
                    ))}
                </select>
            </label>
            <label>
                <span className="text-sm font-semibold">
                    Versión de 2 minutos
                </span>
                <input
                    className="in"
                    value={form.data.two_minute_version}
                    onChange={(e) =>
                        form.setData('two_minute_version', e.target.value)
                    }
                    placeholder="El primer movimiento físico, en unos 2 minutos"
                />
            </label>
            {(form.errors.two_minute_version || form.errors.plan_id) && (
                <p className="msg-attn" role="alert">
                    {form.errors.two_minute_version ?? form.errors.plan_id}
                </p>
            )}
            <span className="flex gap-2">
                <button
                    className="btn-sm btn-primary"
                    type="submit"
                    disabled={form.processing}
                >
                    Crear la tarea
                </button>
                <button
                    className="btn-sm btn-ghost"
                    type="button"
                    onClick={onCancel}
                >
                    Cancelar
                </button>
            </span>
        </form>
    );
}

function AddEntry({
    zone,
    title,
    storeUrl,
}: {
    zone: ControlZone;
    title: string;
    storeUrl: string;
}) {
    const form = useForm({ zone, text: '' });
    const [open, setOpen] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => form.reset('text'),
        });
    };

    if (!open) {
        return (
            <button
                className="linkbtn quiet"
                type="button"
                aria-label={`Agregar a ${title}`}
                onClick={() => setOpen(true)}
            >
                + Agregar
            </button>
        );
    }

    return (
        <form onSubmit={submit}>
            <input
                className="in"
                autoFocus
                style={{ minHeight: 40, fontSize: 15, padding: '6px 10px' }}
                placeholder="Agregar…"
                aria-label={`Agregar a ${title}`}
                onBlur={() => {
                    if (form.data.text.trim() === '') {
                        setOpen(false);
                    }
                }}
                value={form.data.text}
                onChange={(e) => form.setData('text', e.target.value)}
            />
            {form.errors.text && (
                <p className="msg-attn" role="alert">
                    {form.errors.text}
                </p>
            )}
        </form>
    );
}
