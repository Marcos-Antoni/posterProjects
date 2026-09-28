import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import { Crumbs } from '@/components/marcos/crumbs';
import { LockIcon, PlusIcon } from '@/components/marcos/icons';
import { ItemMiniMap } from '@/components/marcos/item-mini-map';
import { RetireButton } from '@/components/marcos/retire-dialog';
import { StateGlyph } from '@/components/marcos/state-glyph';
import { toast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';
import {
    formatLongDate,
    formatLongDateCapitalized,
    formatMoment,
    ITEM_STATE_LABELS,
    todayIso,
} from '@/lib/marcos';
import { show as mapShow } from '@/routes/map';
import {
    index as objectivesIndex,
    show as objectiveShow,
} from '@/routes/objectives';
import {
    check as itemCheck,
    retire as itemRetire,
    retireContext as itemRetireContext,
    show as itemShow,
    uncheck as itemUncheck,
    update as itemUpdate,
} from '@/routes/objectives/items';
import prerequisites from '@/routes/objectives/items/prerequisites';
import unlocks from '@/routes/objectives/items/unlocks';
import { show as planShow } from '@/routes/objectives/plans';
import type { ItemKind, ItemRef, ItemState, PlanState } from '@/types/models';

type ItemPayload = {
    id: number;
    key: string;
    number: number;
    kind: ItemKind;
    title: string;
    description: string | null;
    two_minute_version: string;
    state: ItemState;
    target_date: string | null;
    target_date_passed: boolean;
    plan: { id: number; title: string };
    plan_state: PlanState;
    prerequisites: ItemRef[];
    unlocks: ItemRef[];
    completed_at: string | null;
    created_at: string | null;
    evidence: { text: string; link: string | null } | null;
};

type Version = {
    text: string;
    current: boolean;
    source: 'created' | 'owner' | 'ai';
    at: string | null;
};

type Props = {
    objective: { key: string; title: string; is_writable: boolean };
    item: ItemPayload;
    twoMinuteVersions: Version[];
    focus: {
        today_minutes: number;
        yesterday_minutes: number;
        open_since: string | null;
    };
    plans: { id: number; title: string }[];
};

/**
 * Screen 7 (mockup visual/screens/07-item-detail.html): one task or
 * milestone at its refresh-safe deep link — its 2-minute version and how it
 * shrank, what opened it and what it opens, its data and target date (never
 * rendered as failure), and the single check.
 */
export default function ItemShow({
    objective,
    item,
    twoMinuteVersions,
    focus,
    plans,
}: Props) {
    const { flash } = usePage().props;
    const writable = objective.is_writable;
    const checkable =
        writable && (item.state === 'available' || item.state === 'active');

    useEffect(() => {
        if (flash.unlocked.length > 0) {
            toast(
                `Hecho. Se abrió: ${flash.unlocked.map((unlocked) => unlocked.title).join(', ')}.`,
            );
        }
    }, [flash.unlocked]);

    useEffect(() => {
        if (!checkable || item.kind !== 'task') {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                event.key === ' ' &&
                target &&
                ['BODY', 'MAIN'].includes(target.tagName)
            ) {
                event.preventDefault();
                router.post(
                    itemCheck([objective.key, item.key]).url,
                    {},
                    { preserveScroll: true },
                );
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [checkable, item.kind, item.key, objective.key]);

    return (
        <div className="mos-s07">
            <Head title={`${item.key} ${item.title}`} />
            <Crumbs
                items={[
                    { label: 'Objetivos', href: objectivesIndex().url },
                    {
                        label: objective.title,
                        href: objectiveShow(objective.key).url,
                    },
                    {
                        label: item.plan.title,
                        href: planShow([objective.key, item.plan.id]).url,
                    },
                    { label: item.key },
                ]}
            />
            <div className="ihead">
                <div>
                    <div className="top">
                        <span className="key">{item.key}</span>
                        <span
                            className={`st ${item.state === 'done' ? 'done' : item.state === 'active' ? 'active' : item.state === 'available' ? 'avail' : ''}`}
                        >
                            <StateGlyph
                                state={item.state}
                                kind={item.kind}
                                size="small"
                            />
                            {stateLine(item)}
                        </span>
                    </div>
                    <h1>{item.title}</h1>
                </div>
                <div className="acts">
                    {checkable && item.kind === 'task' && (
                        <button
                            className="btn btn-primary"
                            type="button"
                            onClick={() =>
                                router.post(
                                    itemCheck([objective.key, item.key]).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Marcar hecho<kbd>Espacio</kbd>
                        </button>
                    )}
                    {writable && item.state === 'done' && (
                        <button
                            className="btn btn-outline"
                            type="button"
                            onClick={() =>
                                router.post(
                                    itemUncheck([objective.key, item.key]).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Desmarcar
                        </button>
                    )}
                    {writable && (
                        <RetireButton
                            className="btn btn-outline"
                            contextUrl={
                                itemRetireContext([objective.key, item.key]).url
                            }
                            actionUrl={
                                itemRetire([objective.key, item.key]).url
                            }
                        />
                    )}
                </div>
            </div>

            <div className="layout">
                <div>
                    {item.state === 'locked' && (
                        <div className="lockbox" role="note">
                            <LockIcon />
                            <span>
                                Se abre al terminar{' '}
                                {item.prerequisites
                                    .filter(
                                        (prerequisite) =>
                                            prerequisite.state !== 'done',
                                    )
                                    .map((prerequisite, index, open) => (
                                        <span key={prerequisite.key}>
                                            <b>{prerequisite.title}</b>
                                            {index < open.length - 2
                                                ? ', '
                                                : index === open.length - 2
                                                  ? ' y '
                                                  : ''}
                                        </span>
                                    ))}
                                . Mientras tanto no se ofrece marcarla.
                            </span>
                        </div>
                    )}

                    {checkable && item.kind === 'milestone' && (
                        <SummitAsk objectiveKey={objective.key} item={item} />
                    )}

                    {item.kind === 'milestone' && item.evidence && (
                        <section className="box" aria-labelledby="ev">
                            <h2 id="ev">Evidencia del hito</h2>
                            <p style={{ margin: 0 }}>{item.evidence.text}</p>
                            {item.evidence.link && (
                                <p
                                    className="small"
                                    style={{ margin: '8px 0 0' }}
                                >
                                    <a
                                        href={item.evidence.link}
                                        rel="noreferrer noopener"
                                        target="_blank"
                                    >
                                        {item.evidence.link}
                                    </a>
                                </p>
                            )}
                        </section>
                    )}

                    <TwoMinuteBox
                        objectiveKey={objective.key}
                        item={item}
                        writable={writable && item.state !== 'done'}
                    />

                    <section className="box" aria-labelledby="hi">
                        <h2 id="hi">Cómo se fue achicando</h2>
                        <ol className="hist">
                            {twoMinuteVersions.map((version, index) => (
                                <li
                                    key={index}
                                    className={
                                        version.current ? 'cur' : undefined
                                    }
                                >
                                    <i aria-hidden="true" />
                                    <span className="txt">
                                        {capitalize(version.text)}
                                    </span>
                                    <span className="src">
                                        <b>
                                            {version.current
                                                ? 'Actual'
                                                : 'Reemplazada'}
                                        </b>
                                        {versionSource(version)}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <section className="box" aria-labelledby="fo">
                        <h2 id="fo">Tiempo en foco</h2>
                        <div className="sess">
                            <div>
                                <span>Hoy</span>
                                <b>{focus.today_minutes} min</b>
                                {focus.open_since
                                    ? `, desde las ${formatMoment(focus.open_since).replace(/^hoy /, '')}`
                                    : ''}
                            </div>
                            <div>
                                <span>Ayer</span>
                                <b>{focus.yesterday_minutes} min</b>
                            </div>
                        </div>
                        <p
                            className="small muted"
                            style={{ margin: '12px 0 0' }}
                        >
                            Sin cuenta regresiva: el reloj solo sirve para el
                            aviso silencioso de 25 minutos.
                        </p>
                    </section>
                </div>

                <aside>
                    <DependenciesBox
                        objectiveKey={objective.key}
                        item={item}
                        writable={writable}
                    />
                    <DataBox
                        objective={objective}
                        item={item}
                        plans={plans}
                        writable={writable}
                    />
                </aside>
            </div>
        </div>
    );
}

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function stateLine(item: ItemPayload): string {
    if (item.state === 'done' && item.completed_at) {
        return `Hecha ${formatMoment(item.completed_at)}`;
    }

    if (item.state === 'active') {
        return 'Activa en Ahora';
    }

    return ITEM_STATE_LABELS[item.state];
}

function versionSource(version: Version): string {
    if (version.source === 'created') {
        return version.at
            ? `Vos, al crearla, ${formatLongDate(todayIso(new Date(version.at)))}`
            : 'Vos, al crearla';
    }

    const who = version.source === 'ai' ? 'IA' : 'Vos';

    return version.at ? `${who}, ${formatMoment(version.at)}` : who;
}

function SummitAsk({
    objectiveKey,
    item,
}: {
    objectiveKey: string;
    item: ItemPayload;
}) {
    const form = useForm({ evidence: '', link: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            evidence: data.evidence,
            link: data.link || null,
        }));
        form.post(itemCheck([objectiveKey, item.key]).url, {
            preserveScroll: true,
        });
    };

    return (
        <form
            className="box summit-ask"
            onSubmit={submit}
            aria-labelledby="summit"
        >
            <h2 id="summit">Marcar el hito</h2>
            <label className="fld" style={{ margin: 0 }}>
                <span className="l">Una línea de evidencia</span>
                <input
                    className="in"
                    placeholder="Qué quedó hecho y dónde se ve"
                    value={form.data.evidence}
                    onChange={(e) => form.setData('evidence', e.target.value)}
                />
            </label>
            <label className="fld" style={{ margin: 0 }}>
                <span className="l">Enlace (opcional)</span>
                <input
                    className="in"
                    type="url"
                    placeholder="https://…"
                    value={form.data.link}
                    onChange={(e) => form.setData('link', e.target.value)}
                />
            </label>
            {(form.errors.evidence || form.errors.link) && (
                <p className="attn" role="alert">
                    {form.errors.evidence ?? form.errors.link}
                </p>
            )}
            <button
                className="btn btn-primary"
                type="submit"
                disabled={form.data.evidence.trim() === '' || form.processing}
            >
                Marcar el hito
            </button>
            {form.data.evidence.trim() === '' && (
                <span className="small muted" style={{ marginLeft: 12 }}>
                    Se habilita con la evidencia.
                </span>
            )}
        </form>
    );
}

function TwoMinuteBox({
    objectiveKey,
    item,
    writable,
}: {
    objectiveKey: string;
    item: ItemPayload;
    writable: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ two_minute_version: item.two_minute_version });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(itemUpdate([objectiveKey, item.key]).url, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    return (
        <section className="box" aria-labelledby="tm">
            <h2 id="tm">
                Versión de 2 minutos
                {writable && !editing && (
                    <button
                        className="linkbtn"
                        type="button"
                        onClick={() => setEditing(true)}
                    >
                        Editar
                    </button>
                )}
            </h2>
            {editing ? (
                <form onSubmit={submit} className="grid gap-2">
                    <label className="sr" htmlFor="two-minute">
                        Versión de 2 minutos
                    </label>
                    <input
                        id="two-minute"
                        className="in"
                        value={form.data.two_minute_version}
                        onChange={(e) =>
                            form.setData('two_minute_version', e.target.value)
                        }
                    />
                    {form.errors.two_minute_version && (
                        <p className="attn" role="alert">
                            {form.errors.two_minute_version}
                        </p>
                    )}
                    <span className="flex gap-2">
                        <button
                            className="btn-sm btn-primary"
                            type="submit"
                            disabled={form.processing}
                        >
                            Guardar
                        </button>
                        <button
                            className="btn-sm btn-ghost"
                            type="button"
                            onClick={() => {
                                form.reset();
                                setEditing(false);
                            }}
                        >
                            Cancelar
                        </button>
                    </span>
                </form>
            ) : (
                <div className="chip2">
                    <span className="mini" aria-hidden="true" />2 min:{' '}
                    {item.two_minute_version}
                </div>
            )}
            {item.description && <p className="desc">{item.description}</p>}
        </section>
    );
}

function DependenciesBox({
    objectiveKey,
    item,
    writable,
}: {
    objectiveKey: string;
    item: ItemPayload;
    writable: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const form = useForm({
        direction: 'prerequisite' as 'prerequisite' | 'unlock',
        key: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const problem = errors.prerequisite ?? errors.key;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ key: data.key }));
        const target =
            form.data.direction === 'prerequisite'
                ? prerequisites.store([objectiveKey, item.key])
                : unlocks.store([objectiveKey, item.key]);
        form.post(target.url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });
    };

    const remove = (other: ItemRef, direction: 'prerequisite' | 'unlock') => {
        const target =
            direction === 'prerequisite'
                ? prerequisites.destroy([objectiveKey, item.key, other.key])
                : unlocks.destroy([objectiveKey, item.key, other.key]);

        router.delete(target.url, { preserveScroll: true });
    };

    return (
        <section className="box deps" aria-labelledby="mp">
            <h2 id="mp">
                En el mapa{' '}
                <Link
                    href={mapShow(objectiveKey, { query: { sel: item.key } })}
                >
                    Abrir mapa
                </Link>
                {/* phase 5 */}
            </h2>
            <ItemMiniMap
                self={{ key: item.key, title: item.title, state: item.state }}
                prerequisites={item.prerequisites}
                unlocks={item.unlocks}
            />
            <h3>La abrió</h3>
            <NeighbourList
                objectiveKey={objectiveKey}
                items={item.prerequisites}
                empty="Nada: está libre desde el principio."
                onRemove={
                    writable
                        ? (other) => remove(other, 'prerequisite')
                        : undefined
                }
            />
            <h3>Abre al terminar</h3>
            <NeighbourList
                objectiveKey={objectiveKey}
                items={item.unlocks}
                empty="Nada todavía."
                onRemove={
                    writable ? (other) => remove(other, 'unlock') : undefined
                }
            />
            {writable && !adding && (
                <button
                    className="addl"
                    type="button"
                    onClick={() => setAdding(true)}
                >
                    <PlusIcon />
                    Agregar una dependencia
                </button>
            )}
            {writable && adding && (
                <form onSubmit={submit} className="mt-3 grid gap-2">
                    <label className="fld" style={{ margin: 0 }}>
                        <span className="l">Relación</span>
                        <select
                            className="in"
                            value={form.data.direction}
                            onChange={(e) =>
                                form.setData(
                                    'direction',
                                    e.target.value as 'prerequisite' | 'unlock',
                                )
                            }
                        >
                            <option value="prerequisite">
                                Esta tarea depende de
                            </option>
                            <option value="unlock">
                                Al terminar esta tarea se abre
                            </option>
                        </select>
                    </label>
                    <label className="fld" style={{ margin: 0 }}>
                        <span className="l">Clave de la otra tarea o hito</span>
                        <input
                            className="in"
                            placeholder="Ej.: DIARIO-3"
                            value={form.data.key}
                            onChange={(e) =>
                                form.setData(
                                    'key',
                                    e.target.value.toUpperCase(),
                                )
                            }
                        />
                    </label>
                    {problem && (
                        <div className="attn" role="alert">
                            {problem.startsWith(
                                'No se puede: armaría un círculo.',
                            ) ? (
                                <>
                                    <b>No se puede: armaría un círculo.</b>
                                    {problem.replace(
                                        'No se puede: armaría un círculo. ',
                                        '',
                                    )}
                                </>
                            ) : (
                                problem
                            )}
                        </div>
                    )}
                    <span className="flex gap-2">
                        <button
                            className="btn-sm btn-primary"
                            type="submit"
                            disabled={
                                form.data.key.trim() === '' || form.processing
                            }
                        >
                            Agregar
                        </button>
                        <button
                            className="btn-sm btn-ghost"
                            type="button"
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                                setAdding(false);
                            }}
                        >
                            Cancelar
                        </button>
                    </span>
                </form>
            )}
        </section>
    );
}

function NeighbourList({
    objectiveKey,
    items,
    empty,
    onRemove,
}: {
    objectiveKey: string;
    items: ItemRef[];
    empty: string;
    onRemove?: (item: ItemRef) => void;
}) {
    if (items.length === 0) {
        return (
            <p className="small muted" style={{ margin: '0 0 4px' }}>
                {empty}
            </p>
        );
    }

    return (
        <ul>
            {items.map((neighbour) => {
                const neighbourObjective = neighbour.key.slice(
                    0,
                    neighbour.key.lastIndexOf('-'),
                );

                return (
                    <li key={neighbour.key}>
                        <span className="l2">
                            <StateGlyph state={neighbour.state} size="small" />
                            <Link
                                href={itemShow([
                                    neighbourObjective,
                                    neighbour.key,
                                ])}
                            >
                                {neighbour.title}
                            </Link>
                            {neighbourObjective !== objectiveKey && (
                                <span className="small muted">
                                    ({neighbour.key})
                                </span>
                            )}
                        </span>
                        <span className="flex items-center gap-2">
                            <span
                                className={`st ${neighbour.state === 'done' ? 'done' : ''}`}
                            >
                                {ITEM_STATE_LABELS[neighbour.state]}
                            </span>
                            {onRemove && (
                                <button
                                    className="linkbtn quiet"
                                    type="button"
                                    aria-label={`Quitar la dependencia con ${neighbour.title}`}
                                    onClick={() => onRemove(neighbour)}
                                >
                                    Quitar
                                </button>
                            )}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

function DataBox({
    objective,
    item,
    plans,
    writable,
}: {
    objective: Props['objective'];
    item: ItemPayload;
    plans: Props['plans'];
    writable: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        title: item.title,
        description: item.description ?? '',
        target_date: item.target_date ?? '',
        plan_id: String(item.plan.id),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            description: data.description || null,
            target_date: data.target_date || null,
            plan_id: Number(data.plan_id),
        }));
        form.patch(itemUpdate([objective.key, item.key]).url, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const clearDate = () =>
        router.patch(
            itemUpdate([objective.key, item.key]).url,
            { target_date: null },
            { preserveScroll: true },
        );

    return (
        <section className="box" aria-labelledby="da">
            <h2 id="da">
                Datos
                {writable && !editing && (
                    <button
                        className="linkbtn"
                        type="button"
                        onClick={() => setEditing(true)}
                    >
                        Editar
                    </button>
                )}
            </h2>
            {editing ? (
                <form onSubmit={submit} className="grid gap-3">
                    <label className="fld" style={{ margin: 0 }}>
                        <span className="l">Título</span>
                        <input
                            className="in"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                    </label>
                    <label className="fld" style={{ margin: 0 }}>
                        <span className="l">Descripción</span>
                        <textarea
                            className="in"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                    </label>
                    <label className="fld" style={{ margin: 0 }}>
                        <span className="l">Fecha objetivo (opcional)</span>
                        <input
                            className="in"
                            type="date"
                            value={form.data.target_date}
                            onChange={(e) =>
                                form.setData('target_date', e.target.value)
                            }
                        />
                    </label>
                    <label className="fld" style={{ margin: 0 }}>
                        <span className="l">Plan</span>
                        <select
                            className="in"
                            value={form.data.plan_id}
                            onChange={(e) =>
                                form.setData('plan_id', e.target.value)
                            }
                        >
                            {plans.map((plan) => (
                                <option key={plan.id} value={plan.id}>
                                    {plan.title}
                                </option>
                            ))}
                        </select>
                        <span className="ex">
                            Solo planes de este objetivo.
                        </span>
                    </label>
                    {Object.values(form.errors).length > 0 && (
                        <p className="attn" role="alert">
                            {Object.values(form.errors).join(' ')}
                        </p>
                    )}
                    <span className="flex gap-2">
                        <button
                            className="btn-sm btn-primary"
                            type="submit"
                            disabled={form.processing}
                        >
                            Guardar
                        </button>
                        <button
                            className="btn-sm btn-ghost"
                            type="button"
                            onClick={() => {
                                form.reset();
                                setEditing(false);
                            }}
                        >
                            Cancelar
                        </button>
                    </span>
                </form>
            ) : (
                <>
                    <dl className="fields">
                        <dt>Tipo</dt>
                        <dd>{item.kind === 'milestone' ? 'Hito' : 'Tarea'}</dd>
                        <dt>Plan</dt>
                        <dd>
                            <Link
                                href={planShow([objective.key, item.plan.id])}
                            >
                                {item.plan.title}
                            </Link>
                        </dd>
                        <dt>Objetivo</dt>
                        <dd>{objective.title}</dd>
                        <dt>Fecha objetivo</dt>
                        <dd>
                            {item.target_date
                                ? formatLongDateCapitalized(item.target_date)
                                : 'Sin fecha'}
                        </dd>
                        <dt>Creada</dt>
                        <dd>
                            {item.created_at
                                ? formatLongDateCapitalized(
                                      todayIso(new Date(item.created_at)),
                                  )
                                : '—'}
                        </dd>
                    </dl>
                    {item.target_date_passed && writable && (
                        <div className="neutral" style={{ marginTop: 12 }}>
                            <span className="muted">
                                La fecha objetivo pasó. ¿La ajustamos?
                            </span>
                            <button
                                className="btn-sm btn-outline"
                                type="button"
                                onClick={() => setEditing(true)}
                            >
                                Elegir otra fecha
                            </button>
                            <button
                                className="linkbtn quiet"
                                type="button"
                                onClick={clearDate}
                            >
                                Quitar la fecha
                            </button>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

ItemShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
