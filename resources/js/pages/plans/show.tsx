import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import { Crumbs } from '@/components/marcos/crumbs';
import { AttentionIcon } from '@/components/marcos/icons';
import { ItemRow } from '@/components/marcos/item-row';
import AppLayout from '@/layouts/app-layout';
import { formatLongDateCapitalized, formatNumber } from '@/lib/marcos';
import {
    index as objectivesIndex,
    show as objectiveShow,
} from '@/routes/objectives';
import {
    activate as planActivate,
    edit as planEdit,
    move as planMove,
    show as planShow,
} from '@/routes/objectives/plans';
import { store as itemStore } from '@/routes/objectives/plans/items';
import type {
    ControlPlan,
    ItemKind,
    PlanState,
    TreePlan,
} from '@/types/models';

type Candidate = {
    id: number;
    key: string;
    title: string;
    objective_title: string;
    same_objective: boolean;
};

type Props = {
    objective: {
        key: string;
        title: string;
        state: string;
        is_writable: boolean;
    };
    plan: Pick<
        TreePlan,
        | 'id'
        | 'title'
        | 'state'
        | 'level'
        | 'progress'
        | 'next_milestone'
        | 'retired_titles'
        | 'items'
    >;
    controlPlan: ControlPlan | null;
    ladder: {
        id: number;
        title: string;
        level: number;
        state: PlanState;
        progress: { done: number; total: number };
    }[];
    nextNumber: number;
    prerequisiteCandidates: Candidate[];
};

const STATE_WORDS: Record<PlanState, string> = {
    draft: 'Plan en borrador',
    active: 'Plan activo',
    done: 'Plan hecho',
    retired: 'Plan retirado',
};

/**
 * Screen 6 (mockup visual/screens/06-plan-detail.html): a plan's tasks and
 * milestones in manual order (reorderable), the form that appends one with
 * its required 2-minute version, and the plan's own 5-point plan.
 */
export default function PlanShow({
    objective,
    plan,
    controlPlan,
    ladder,
    nextNumber,
    prerequisiteCandidates,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const writable = objective.is_writable && plan.state !== 'retired';
    const current = ladder.find((rung) => rung.id === plan.id);
    const hasNextRung =
        plan.level !== null &&
        ladder.some((rung) => rung.level === (plan.level ?? 0) + 1);

    return (
        <div className="mos-s06">
            <Head title={plan.title} />
            <Crumbs
                items={[
                    { label: 'Objetivos', href: objectivesIndex().url },
                    {
                        label: objective.title,
                        href: objectiveShow(objective.key).url,
                    },
                    { label: plan.title },
                ]}
            />
            <div className="phead">
                <div>
                    <h1>{plan.title}</h1>
                    <p className="meta">
                        {STATE_WORDS[plan.state]}.
                        {plan.progress.total > 0 &&
                            ` ${plan.progress.done} de ${plan.progress.total} hechas.`}
                    </p>
                    {plan.level !== null && (
                        <div
                            className="ladder"
                            aria-label="Escalera de niveles"
                        >
                            {ladder.map((rung, index) => (
                                <span
                                    key={rung.id}
                                    style={{ display: 'contents' }}
                                >
                                    {index > 0 && (
                                        <span
                                            className="link"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {rung.id === plan.id ? (
                                        <span className="rung on">
                                            Nivel {rung.level}: {rung.title}
                                        </span>
                                    ) : (
                                        <Link
                                            className={
                                                current &&
                                                rung.level > current.level &&
                                                rung.state === 'draft'
                                                    ? 'rung next'
                                                    : 'rung'
                                            }
                                            href={planShow([
                                                objective.key,
                                                rung.id,
                                            ])}
                                            style={{ textDecoration: 'none' }}
                                        >
                                            Nivel {rung.level}: {rung.title}
                                            {current &&
                                                rung.level > current.level &&
                                                rung.state === 'draft' &&
                                                ', se sugiere al 80 %'}
                                        </Link>
                                    )}
                                </span>
                            ))}
                            {current && !hasNextRung && (
                                <>
                                    <span className="link" aria-hidden="true" />
                                    <span className="rung next">
                                        Nivel {current.level + 1}: se sugiere al
                                        80 %
                                    </span>
                                </>
                            )}
                        </div>
                    )}
                </div>
                {writable && (
                    <div className="acts">
                        {plan.state === 'draft' && (
                            <button
                                className="btn-sm btn-outline"
                                type="button"
                                onClick={() =>
                                    router.post(
                                        planActivate([objective.key, plan.id])
                                            .url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Activar el plan
                            </button>
                        )}
                        <button
                            className="btn-sm btn-ghost"
                            type="button"
                            aria-label="Subir el plan en el objetivo"
                            onClick={() =>
                                router.post(
                                    planMove([objective.key, plan.id]).url,
                                    { direction: 'up' },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Subir
                        </button>
                        <button
                            className="btn-sm btn-ghost"
                            type="button"
                            aria-label="Bajar el plan en el objetivo"
                            onClick={() =>
                                router.post(
                                    planMove([objective.key, plan.id]).url,
                                    { direction: 'down' },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Bajar
                        </button>
                        <Link
                            className="btn-sm btn-ghost"
                            href={planEdit([objective.key, plan.id])}
                        >
                            Editar el plan
                        </Link>
                    </div>
                )}
            </div>

            {plan.state === 'draft' &&
                Object.keys(errors).some((key) =>
                    [
                        'outcome',
                        'deadline',
                        'metric',
                        'risks',
                        'contingency',
                        'plan',
                    ].includes(key),
                ) && (
                    <div
                        className="attn-box"
                        role="alert"
                        style={{
                            margin: '0 0 20px',
                            background: 'var(--attention-bg)',
                            borderLeft: '3px solid var(--attention)',
                            borderRadius: 6,
                            padding: '12px 16px',
                        }}
                    >
                        <b>Todavía no se puede activar.</b>{' '}
                        {[
                            'outcome',
                            'deadline',
                            'metric',
                            'risks',
                            'contingency',
                            'plan',
                        ]
                            .map((key) => errors[key])
                            .filter(Boolean)
                            .join(' ')}
                    </div>
                )}

            {plan.state === 'done' && (
                <div className="done-card" style={{ marginBottom: 20 }}>
                    <div className="row">
                        <svg
                            width="26"
                            height="26"
                            viewBox="0 0 22 22"
                            aria-hidden="true"
                        >
                            <circle
                                cx="11"
                                cy="11"
                                r="8"
                                style={{
                                    fill: 'var(--blaze)',
                                    stroke: 'var(--blaze-ink)',
                                }}
                                strokeWidth="2.5"
                            />
                            <path
                                d="M7.5 11 l2.5 2.5 l4.5 -5.5"
                                fill="none"
                                style={{ stroke: 'var(--blaze-foreground)' }}
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            />
                        </svg>
                        <b>{plan.title}, hecho.</b>
                    </div>
                    <p className="small muted" style={{ margin: '8px 0 0' }}>
                        {plan.progress.done} de {plan.progress.total} hechas.
                        Desmarcar una tarea lo devuelve a activo, sin penalidad.
                    </p>
                </div>
            )}

            <div className="layout">
                <section className="plan" aria-labelledby="it">
                    <div className="plan-h">
                        <h2 id="it">Tareas e hitos, en orden</h2>
                        <span className="small muted">
                            Se abren según sus dependencias, no por el orden.
                        </span>
                    </div>
                    <ol className="items">
                        {plan.items.map((item, index) => (
                            <ItemRow
                                key={item.id}
                                objectiveKey={objective.key}
                                item={item}
                                reorder={
                                    writable
                                        ? {
                                              first: index === 0,
                                              last:
                                                  index ===
                                                  plan.items.length - 1,
                                          }
                                        : undefined
                                }
                            />
                        ))}
                    </ol>
                    {plan.items.length === 0 && (
                        <p
                            className="small muted"
                            style={{
                                padding: '12px 24px',
                                margin: 0,
                                borderTop: '1px solid var(--border)',
                            }}
                        >
                            Todavía no hay tareas en este plan.
                        </p>
                    )}

                    {writable && (
                        <AddItemForm
                            objectiveKey={objective.key}
                            planId={plan.id}
                            nextKey={`${objective.key}-${nextNumber}`}
                            candidates={prerequisiteCandidates}
                        />
                    )}
                </section>

                <aside className="side" aria-label="Plan de 5 puntos del plan">
                    <section className="card5">
                        <h2>
                            Plan de 5 puntos
                            {writable && (
                                <Link href={planEdit([objective.key, plan.id])}>
                                    Editar
                                </Link>
                            )}
                        </h2>
                        <dl>
                            <dt>Resultado</dt>
                            <dd>
                                {controlPlan?.outcome ?? (
                                    <span className="muted">Falta.</span>
                                )}
                            </dd>
                            <dt>Fecha límite</dt>
                            <dd>
                                {controlPlan?.deadline ? (
                                    formatLongDateCapitalized(
                                        controlPlan.deadline,
                                    )
                                ) : (
                                    <span className="muted">Falta.</span>
                                )}
                            </dd>
                            <dt>Una métrica</dt>
                            <dd>
                                {controlPlan?.metric.name ? (
                                    <>
                                        {controlPlan.metric.name}
                                        <div className="bar" aria-hidden="true">
                                            <i
                                                style={{
                                                    width: `${controlPlan.metric.progress_percent ?? 0}%`,
                                                }}
                                            />
                                        </div>
                                        <span className="small muted tnum">
                                            {formatNumber(
                                                controlPlan.metric.current ?? 0,
                                            )}{' '}
                                            de{' '}
                                            {formatNumber(
                                                controlPlan.metric.target,
                                            )}
                                        </span>
                                    </>
                                ) : (
                                    <span className="muted">Falta.</span>
                                )}
                            </dd>
                            <dt>Qué puede salir mal</dt>
                            <dd>
                                {controlPlan && controlPlan.risks.length > 0 ? (
                                    controlPlan.risks.join(' ')
                                ) : (
                                    <span className="muted">Falta.</span>
                                )}
                            </dd>
                            <dt>Contingencia</dt>
                            <dd>
                                {controlPlan?.contingency ?? (
                                    <span className="muted">Falta.</span>
                                )}
                            </dd>
                        </dl>
                    </section>
                    {plan.retired_titles.length > 0 && (
                        <section className="card5">
                            <h2>Retirados</h2>
                            <p className="small muted" style={{ margin: 0 }}>
                                {plan.retired_titles.length === 1
                                    ? '1 retirado oculto'
                                    : `${plan.retired_titles.length} retirados ocultos`}
                                : {plan.retired_titles.join(', ')}.
                            </p>
                        </section>
                    )}
                </aside>
            </div>
        </div>
    );
}

function AddItemForm({
    objectiveKey,
    planId,
    nextKey,
    candidates,
}: {
    objectiveKey: string;
    planId: number;
    nextKey: string;
    candidates: Candidate[];
}) {
    const form = useForm<{
        kind: ItemKind;
        title: string;
        two_minute_version: string;
        target_date: string;
        prerequisite_ids: number[];
    }>({
        kind: 'task',
        title: '',
        two_minute_version: '',
        target_date: '',
        prerequisite_ids: [],
    });
    const [search, setSearch] = useState('');

    const chosen = candidates.filter((candidate) =>
        form.data.prerequisite_ids.includes(candidate.id),
    );
    const needle = search.trim().toLowerCase();
    const matches =
        needle === ''
            ? []
            : candidates
                  .filter(
                      (candidate) =>
                          !form.data.prerequisite_ids.includes(candidate.id),
                  )
                  .filter((candidate) =>
                      `${candidate.key} ${candidate.title}`
                          .toLowerCase()
                          .includes(needle),
                  )
                  .slice(0, 6);
    const ready =
        form.data.title.trim() !== '' &&
        form.data.two_minute_version.trim() !== '';
    const missingTwoMinute =
        form.data.title.trim() !== '' &&
        form.data.two_minute_version.trim() === '';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            target_date: data.target_date || null,
        }));
        form.post(itemStore([objectiveKey, planId]).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setSearch('');
            },
        });
    };

    return (
        <form
            className="addf"
            id="agregar"
            onSubmit={submit}
            aria-labelledby="af"
            noValidate
        >
            <h3 id="af">Agregar al final del plan</h3>
            <p className="sub">
                Será {nextKey}. Una tarea se marca con un check; un hito pide
                una línea de evidencia al terminarlo.
            </p>
            <div className="seg" role="group" aria-label="Tipo">
                <button
                    type="button"
                    aria-pressed={form.data.kind === 'task'}
                    onClick={() => form.setData('kind', 'task')}
                >
                    Tarea
                </button>
                <button
                    type="button"
                    aria-pressed={form.data.kind === 'milestone'}
                    onClick={() => form.setData('kind', 'milestone')}
                >
                    Hito
                </button>
            </div>
            <label className="fld">
                <span className="l">Título</span>
                <input
                    className="in"
                    name="title"
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                />
                {form.errors.title && (
                    <span className="msg-attn" role="alert">
                        <AttentionIcon />
                        {form.errors.title}
                    </span>
                )}
            </label>
            <label className="fld two-min">
                <span className="l">
                    <i aria-hidden="true" />
                    Versión de 2 minutos
                </span>
                <input
                    className={
                        form.data.two_minute_version.trim() === ''
                            ? 'in need'
                            : 'in'
                    }
                    placeholder="El primer movimiento físico, en unos 2 minutos"
                    name="two_minute_version"
                    value={form.data.two_minute_version}
                    onChange={(e) =>
                        form.setData('two_minute_version', e.target.value)
                    }
                />
                {(form.errors.two_minute_version || missingTwoMinute) && (
                    <span
                        className="msg-attn"
                        role={
                            form.errors.two_minute_version ? 'alert' : undefined
                        }
                    >
                        <AttentionIcon />
                        {form.errors.two_minute_version ??
                            'Falta la versión de 2 minutos: es lo primero que vas a ver en Ahora.'}
                    </span>
                )}
                <span className="ex">
                    Empezá con un verbo que se pueda hacer con las manos. Ej.:
                    "abrir la libreta en una página nueva", no "reflexionar".
                </span>
            </label>
            <div className="row2">
                <div className="fld">
                    <span className="l">Se abre al terminar (opcional)</span>
                    <div className="chips">
                        {chosen.map((candidate) => (
                            <span key={candidate.id}>
                                {candidate.title}{' '}
                                <button
                                    type="button"
                                    aria-label={`Quitar ${candidate.title}`}
                                    onClick={() =>
                                        form.setData(
                                            'prerequisite_ids',
                                            form.data.prerequisite_ids.filter(
                                                (id) => id !== candidate.id,
                                            ),
                                        )
                                    }
                                >
                                    ×
                                </button>
                            </span>
                        ))}
                        <input
                            aria-label="Buscar tarea o hito"
                            placeholder="Buscar en cualquier objetivo…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    {matches.length > 0 && (
                        <ul
                            className="mt-1 grid gap-0.5 rounded-sm border border-border bg-surface p-1"
                            role="listbox"
                            aria-label="Coincidencias"
                        >
                            {matches.map((candidate) => (
                                <li key={candidate.id}>
                                    <button
                                        type="button"
                                        className="w-full rounded-sm px-2 py-1.5 text-left text-sm hover:bg-sunken"
                                        onClick={() => {
                                            form.setData('prerequisite_ids', [
                                                ...form.data.prerequisite_ids,
                                                candidate.id,
                                            ]);
                                            setSearch('');
                                        }}
                                    >
                                        <b>{candidate.key}</b> {candidate.title}
                                        {!candidate.same_objective && (
                                            <span className="muted">
                                                {' '}
                                                · {candidate.objective_title}
                                            </span>
                                        )}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <span className="ex">
                        Si elegís una de otro objetivo, en el mapa aparece como
                        transbordo.
                    </span>
                </div>
                <label className="fld">
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
            </div>
            <div className="foot">
                <button
                    className="btn btn-primary"
                    type="submit"
                    disabled={!ready || form.processing}
                >
                    {form.data.kind === 'milestone'
                        ? 'Agregar hito'
                        : 'Agregar tarea'}
                </button>
                {!ready && (
                    <span className="why">
                        Se habilita con título y versión de 2 minutos.
                    </span>
                )}
            </div>
        </form>
    );
}

PlanShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
