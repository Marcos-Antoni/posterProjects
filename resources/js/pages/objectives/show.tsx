import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactElement } from 'react';

import { ControlMapCard } from '@/components/marcos/control-map-card';
import { ControlPlanCard } from '@/components/marcos/control-plan-card';
import { Crumbs } from '@/components/marcos/crumbs';
import { PlusIcon } from '@/components/marcos/icons';
import { ItemRow } from '@/components/marcos/item-row';
import { RetireButton } from '@/components/marcos/retire-dialog';
import { StateGlyph } from '@/components/marcos/state-glyph';
import AppLayout from '@/layouts/app-layout';
import {
    edit as objectiveEdit,
    index as objectivesIndex,
    reopen as objectiveReopen,
    retire as objectiveRetire,
    retireContext as objectiveRetireContext,
    update as objectiveUpdate,
} from '@/routes/objectives';
import controlMap from '@/routes/objectives/control-map';
import {
    create as planCreate,
    show as planShow,
} from '@/routes/objectives/plans';
import type {
    ControlMapEntry,
    ControlPlan,
    ObjectiveState,
    TreePlan,
} from '@/types/models';

type Props = {
    objective: {
        key: string;
        title: string;
        identity_statement: string | null;
        state: ObjectiveState;
        is_writable: boolean;
    };
    controlPlan: ControlPlan | null;
    controlMap: ControlMapEntry[];
    plans: TreePlan[];
    levelSuggestion: { from_title: string; next_level: number } | null;
};

/**
 * Screen 4 (mockup visual/screens/04-objective-detail.html): one objective's
 * whole tree — its plans in order with their tasks and milestones (retired
 * ones hidden), the 5-point plan and the control map, editable in place.
 */
export default function ObjectiveShow({
    objective,
    controlPlan,
    controlMap: entries,
    plans,
    levelSuggestion,
}: Props) {
    const writable = objective.is_writable;

    return (
        <div className="mos-s04">
            <Head title={objective.title} />
            <Crumbs
                items={[
                    { label: 'Objetivos', href: objectivesIndex().url },
                    { label: objective.key },
                ]}
            />

            <div className="ohead">
                <div>
                    <h1 className="obj-title">
                        {objective.title}
                        <span className="key">{objective.key}</span>
                    </h1>
                    {objective.identity_statement && (
                        <p className="ident">{objective.identity_statement}</p>
                    )}
                </div>
                <div className="acts">
                    {objective.state === 'closed' && (
                        <button
                            className="btn-sm btn-outline"
                            type="button"
                            onClick={() =>
                                router.post(objectiveReopen(objective.key).url)
                            }
                        >
                            Reabrir
                        </button>
                    )}
                    {writable && (
                        <Link
                            className="btn-sm btn-ghost"
                            href={objectiveEdit(objective.key)}
                        >
                            Editar el objetivo
                        </Link>
                    )}
                    {writable && (
                        <RetireButton
                            contextUrl={
                                objectiveRetireContext(objective.key).url
                            }
                            actionUrl={objectiveRetire(objective.key).url}
                            label="Retirar el objetivo"
                        />
                    )}
                </div>
            </div>

            {objective.state === 'closed' && (
                <div className="sugg" style={{ marginBottom: 24 }}>
                    <p>
                        Este objetivo está cerrado: se lee, no se cambia.
                        Reabrilo si querés seguirlo.
                    </p>
                </div>
            )}

            <div className="layout">
                <div>
                    {levelSuggestion && writable && (
                        <div className="sugg" style={{ marginBottom: 20 }}>
                            <p>
                                {levelSuggestion.from_title} llegó al 80 %.
                                ¿Preparamos el nivel{' '}
                                {levelSuggestion.next_level}? No se activa solo.
                            </p>
                            <Link
                                className="btn-sm btn-outline"
                                href={planCreate(objective.key, {
                                    query: {
                                        level: levelSuggestion.next_level,
                                    },
                                })}
                            >
                                Preparar el nivel {levelSuggestion.next_level}
                            </Link>
                        </div>
                    )}

                    {plans.length === 0 && (
                        <section className="plan">
                            <div className="plan-h">
                                <div>
                                    <h2>Todavía no hay planes</h2>
                                    <p>
                                        Un plan agrupa los hitos y tareas de una
                                        etapa del objetivo.
                                    </p>
                                </div>
                            </div>
                        </section>
                    )}

                    {plans.map((plan) => (
                        <PlanSection
                            key={plan.id}
                            previousLevelTitle={
                                plan.level !== null
                                    ? (plans.find(
                                          (other) =>
                                              other.level ===
                                              (plan.level ?? 0) - 1,
                                      )?.title ?? null)
                                    : null
                            }
                            objectiveKey={objective.key}
                            plan={plan}
                            writable={writable}
                        />
                    ))}

                    {writable && (
                        <p className="small" style={{ margin: '0 0 20px' }}>
                            <Link
                                className="inline-flex items-center gap-1.5 font-semibold"
                                href={planCreate(objective.key)}
                            >
                                <PlusIcon />
                                Agregar un plan
                            </Link>
                        </p>
                    )}
                </div>

                <aside
                    className="side"
                    aria-label="Plan de 5 puntos y mapa de control"
                >
                    <ControlPlanCard
                        controlPlan={controlPlan}
                        updateUrl={objectiveUpdate(objective.key).url}
                        writable={writable}
                    />
                    <ControlMapCard
                        entries={entries}
                        storeUrl={controlMap.store(objective.key).url}
                        entryUrl={(entryId) =>
                            controlMap.destroy([objective.key, entryId]).url
                        }
                        convertUrl={(entryId) =>
                            controlMap.convert([objective.key, entryId]).url
                        }
                        plans={plans.map((plan) => ({
                            id: plan.id,
                            title: plan.title,
                        }))}
                        writable={writable}
                    />
                </aside>
            </div>
        </div>
    );
}

/**
 * Parallel branches of a plan: items that share the same (non-empty) set of
 * prerequisites with at least one sibling, keyed by item id.
 */
function parallelGroups(
    items: TreePlan['items'],
): Map<number, { first: boolean; others: number[] }> {
    const groups = new Map<string, TreePlan['items']>();

    for (const item of items) {
        if (item.prerequisite_keys.length === 0) {
            continue;
        }

        const key = [...item.prerequisite_keys].sort().join('|');
        groups.set(key, [...(groups.get(key) ?? []), item]);
    }

    const result = new Map<number, { first: boolean; others: number[] }>();

    for (const group of groups.values()) {
        if (group.length < 2) {
            continue;
        }

        group.forEach((item, index) =>
            result.set(item.id, {
                first: index === 0,
                others: group
                    .filter((other) => other.id !== item.id)
                    .map((other) => other.number),
            }),
        );
    }

    return result;
}

function planSummary(
    plan: TreePlan,
    previousLevelTitle: string | null,
): string {
    if (plan.state === 'draft' && previousLevelTitle !== null) {
        return `Borrador de nivel: se prepara cuando ${previousLevelTitle} llegue al 80 %.`;
    }

    const state = {
        draft: 'Borrador',
        active: 'Activo',
        done: 'Hecho',
        retired: 'Retirado',
    }[plan.state];
    const parts = [`${state}.`];

    if (plan.progress.total > 0) {
        parts.push(`${plan.progress.done} de ${plan.progress.total} hechas.`);
    }

    if (plan.next_milestone && plan.next_milestone.remaining > 0) {
        const remaining = plan.next_milestone.remaining;
        parts.push(
            `Faltan ${remaining} ${remaining === 1 ? 'estación' : 'estaciones'} para el hito.`,
        );
    }

    return parts.join(' ');
}

function PlanSection({
    objectiveKey,
    plan,
    writable,
    previousLevelTitle,
}: {
    objectiveKey: string;
    plan: TreePlan;
    writable: boolean;
    previousLevelTitle: string | null;
}) {
    const parallel = parallelGroups(plan.items);
    const expandedByDefault = plan.state === 'active';
    const [expanded, setExpanded] = useState(expandedByDefault);
    const href = planShow([objectiveKey, plan.id]);

    return (
        <section className="plan" aria-labelledby={`plan-${plan.id}`}>
            <div className="plan-h">
                <div>
                    <h2 id={`plan-${plan.id}`}>
                        <Link href={href}>{plan.title}</Link>
                        {plan.level !== null && (
                            <span className="lvl">Nivel {plan.level}</span>
                        )}
                    </h2>
                    <p>{planSummary(plan, previousLevelTitle)}</p>
                </div>
                <Link className="small" href={href}>
                    Plan de 5 puntos del plan
                </Link>
            </div>

            {expanded ? (
                <ol className="items">
                    {plan.items.map((item) => (
                        <ItemRow
                            key={item.id}
                            objectiveKey={objectiveKey}
                            item={item}
                            parallel={parallel.get(item.id)}
                        />
                    ))}
                </ol>
            ) : (
                <div className="fold">
                    <StateGlyph state="locked" />
                    <span>
                        {plan.items.length === 0
                            ? 'Sin tareas todavía.'
                            : `${plan.items.length} ${plan.items.length === 1 ? 'estación' : 'estaciones'}, de ${plan.items[0].title} a ${plan.items[plan.items.length - 1].title}.`}
                    </span>
                    {plan.items.length > 0 && (
                        <button
                            className="linkbtn"
                            type="button"
                            aria-expanded="false"
                            onClick={() => setExpanded(true)}
                        >
                            Mostrar{' '}
                            {plan.items.length === 1
                                ? 'la tarea'
                                : `las ${plan.items.length}`}
                        </button>
                    )}
                </div>
            )}

            {(writable || plan.retired_titles.length > 0) && (
                <div className="plan-f">
                    {writable ? (
                        <Link className="add" href={`${href.url}#agregar`}>
                            <PlusIcon />
                            Agregar tarea o hito
                        </Link>
                    ) : (
                        <span />
                    )}
                    {plan.retired_titles.length > 0 && (
                        <span>
                            {plan.retired_titles.length === 1
                                ? '1 retirado oculto'
                                : `${plan.retired_titles.length} retirados ocultos`}
                            : {plan.retired_titles.join(', ')}.
                        </span>
                    )}
                </div>
            )}
        </section>
    );
}

ObjectiveShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
