import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import { ControlMapCard } from '@/components/marcos/control-map-card';
import {
    ActivationChecklist,
    ControlMapFields,
    controlPlanPayload,
    EMPTY_CONTROL_PLAN,
    FivePointFields,
    missingPoints,
    splitContingency,
} from '@/components/marcos/control-plan-fields';
import type { ControlPlanDraft } from '@/components/marcos/control-plan-fields';
import { Crumbs } from '@/components/marcos/crumbs';
import { AttentionIcon } from '@/components/marcos/icons';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/marcos';
import {
    index as objectivesIndex,
    show as objectiveShow,
} from '@/routes/objectives';
import {
    show as planShow,
    store as planStore,
    update as planUpdate,
} from '@/routes/objectives/plans';
import planControlMap from '@/routes/objectives/plans/control-map';
import type { ControlMapEntry, ControlPlan, PlanState } from '@/types/models';

type EditablePlan = {
    id: number;
    title: string;
    state: PlanState;
    level: number | null;
    control_plan: ControlPlan | null;
    control_map: ControlMapEntry[];
};

type Draft = ControlPlanDraft & { title: string; level: string };

function initialDraft(
    plan: EditablePlan | null,
    suggestedLevel: number | null,
): Draft {
    if (plan === null) {
        return {
            ...EMPTY_CONTROL_PLAN,
            title: '',
            level: suggestedLevel === null ? '' : String(suggestedLevel),
        };
    }

    const controlPlan = plan.control_plan;
    const contingency = splitContingency(controlPlan?.contingency ?? null);

    return {
        title: plan.title,
        level: plan.level === null ? '' : String(plan.level),
        outcome: controlPlan?.outcome ?? '',
        deadline: controlPlan?.deadline ?? '',
        metric_name: controlPlan?.metric.name ?? '',
        metric_target: formatNumber(controlPlan?.metric.target ?? null),
        metric_current: formatNumber(controlPlan?.metric.current ?? null),
        risks:
            controlPlan && controlPlan.risks.length > 0
                ? controlPlan.risks
                : [''],
        contingency_when: contingency.when,
        contingency_then: contingency.then,
        control_map: [],
    };
}

/**
 * The plan form (mockup 06, state "Nuevo plan"): the same five points as the
 * objective plus the plan's rung on the level ladder. A plan may be saved as
 * a draft with only its title; it activates only with the five points.
 */
export default function PlanForm({
    objective,
    plan,
    suggestedLevel,
}: {
    objective: { key: string; title: string };
    plan: EditablePlan | null;
    suggestedLevel: number | null;
}) {
    const creating = plan === null;
    const [draft, setDraft] = useState<Draft>(() =>
        initialDraft(plan, suggestedLevel),
    );
    const [errors, setErrors] = useState<Record<string, string | undefined>>(
        {},
    );
    const [processing, setProcessing] = useState(false);

    const complete = missingPoints(draft).length === 0;
    const titled = draft.title.trim() !== '';
    const mustStayComplete =
        !creating && (plan.state === 'active' || plan.state === 'done');

    const send = (activate: boolean) => {
        const payload = {
            title: draft.title,
            level: draft.level === '' ? null : Number(draft.level),
            ...controlPlanPayload(draft),
            ...(creating ? { control_map: draft.control_map, activate } : {}),
        };
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (next: Record<string, string>) => setErrors(next),
        };

        if (creating) {
            router.post(planStore(objective.key).url, payload, options);
        } else {
            router.patch(planUpdate([objective.key, plan.id]).url, payload, {
                ...options,
                onSuccess: () =>
                    router.visit(planShow([objective.key, plan.id]).url),
            });
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(false);
    };

    const heading = creating
        ? `Nuevo plan en ${objective.title}`
        : `Editar ${plan.title}`;

    return (
        <div className="mos-s05">
            <Head title={heading} />
            <Crumbs
                items={[
                    { label: 'Objetivos', href: objectivesIndex().url },
                    {
                        label: objective.title,
                        href: objectiveShow(objective.key).url,
                    },
                    ...(creating
                        ? [{ label: 'Nuevo plan' }]
                        : [
                              {
                                  label: plan.title,
                                  href: planShow([objective.key, plan.id]).url,
                              },
                              { label: 'Editar' },
                          ]),
                ]}
            />
            <div className="fhead">
                <h1>{heading}</h1>
                <p>
                    Mismos cinco puntos que el objetivo, más su nivel en la
                    escalera. Se guarda como borrador y se activa con los cinco.
                </p>
            </div>

            <div className="flayout">
                <form id="plan-form" onSubmit={submit} noValidate>
                    <section className="fsec" aria-labelledby="ps1">
                        <h2 id="ps1">Qué es</h2>
                        <p className="sec-sub">
                            Un plan agrupa los hitos y tareas de una etapa del
                            objetivo.
                        </p>
                        <div className="two">
                            <label className="fld">
                                <span className="l">Título</span>
                                <input
                                    className="in"
                                    value={draft.title}
                                    onChange={(e) =>
                                        setDraft({
                                            ...draft,
                                            title: e.target.value,
                                        })
                                    }
                                />
                                {errors.title && (
                                    <p className="msg-attn" role="alert">
                                        <AttentionIcon />
                                        {errors.title}
                                    </p>
                                )}
                            </label>
                            <label className="fld">
                                <span className="l">Nivel (opcional)</span>
                                <input
                                    className="in"
                                    inputMode="numeric"
                                    value={draft.level}
                                    onChange={(e) =>
                                        setDraft({
                                            ...draft,
                                            level: e.target.value.replace(
                                                /\D/g,
                                                '',
                                            ),
                                        })
                                    }
                                />
                                {errors.level && (
                                    <p className="msg-attn" role="alert">
                                        <AttentionIcon />
                                        {errors.level}
                                    </p>
                                )}
                                <span className="ex">
                                    Se propone activar el siguiente nivel cuando
                                    este llegue al 80 %.
                                </span>
                            </label>
                        </div>
                    </section>

                    <section className="fsec" aria-labelledby="ps2">
                        <h2 id="ps2">Plan de 5 puntos</h2>
                        <p className="sec-sub">
                            Obligatorios para activar el plan; en borrador
                            pueden quedar a medias.
                        </p>
                        <FivePointFields
                            draft={draft}
                            errors={errors}
                            noun="plan"
                            onChange={(next) => setDraft({ ...draft, ...next })}
                        />
                    </section>

                    <section className="fsec" aria-labelledby="ps3">
                        <h2 id="ps3">Mapa de control</h2>
                        <p className="sec-sub">
                            Separar lo que depende de vos de lo que no. Solo las
                            dos primeras zonas pueden volverse tareas.
                        </p>
                        {creating ? (
                            <ControlMapFields
                                draft={draft}
                                onChange={(next) =>
                                    setDraft({ ...draft, ...next })
                                }
                            />
                        ) : (
                            <ControlMapCard
                                entries={plan.control_map}
                                storeUrl={
                                    planControlMap.store([
                                        objective.key,
                                        plan.id,
                                    ]).url
                                }
                                entryUrl={(entryId) =>
                                    planControlMap.destroy([
                                        objective.key,
                                        plan.id,
                                        entryId,
                                    ]).url
                                }
                                plans={[]}
                                writable
                            />
                        )}
                    </section>
                </form>

                <aside className="check5" aria-label="Qué falta para activar">
                    <h2>Para activar</h2>
                    <ActivationChecklist draft={draft} />
                    {creating ? (
                        <div className="grid gap-2">
                            <button
                                className="btn btn-primary"
                                type="button"
                                disabled={!titled || !complete || processing}
                                onClick={() => send(true)}
                            >
                                Crear y activar
                            </button>
                            <button
                                className="btn btn-outline"
                                type="submit"
                                form="plan-form"
                                disabled={!titled || processing}
                            >
                                Guardar como borrador
                            </button>
                        </div>
                    ) : (
                        <button
                            className="btn btn-primary"
                            type="submit"
                            form="plan-form"
                            disabled={
                                !titled ||
                                (mustStayComplete && !complete) ||
                                processing
                            }
                        >
                            Guardar cambios
                        </button>
                    )}
                    <p className="why">
                        {complete
                            ? 'Los cinco puntos están completos.'
                            : mustStayComplete
                              ? 'Un plan activo no puede perder ninguno de sus cinco puntos.'
                              : 'En borrador puede quedar a medias; se activa con los cinco.'}
                    </p>
                </aside>
            </div>
        </div>
    );
}

PlanForm.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
