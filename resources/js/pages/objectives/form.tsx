import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import {
    ActivationChecklist,
    ControlMapFields,
    controlPlanPayload,
    EMPTY_CONTROL_PLAN,
    FivePointFields,
    missingPoints,
    POINT_LABELS,
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
    store as objectiveStore,
    update as objectiveUpdate,
} from '@/routes/objectives';
import type {
    ControlMapEntry,
    ControlPlan,
    ObjectiveState,
} from '@/types/models';

type EditableObjective = {
    key: string;
    title: string;
    identity_statement: string | null;
    state: ObjectiveState;
    control_plan: ControlPlan | null;
    control_map: ControlMapEntry[];
};

type Draft = ControlPlanDraft & {
    key: string;
    title: string;
    identity_statement: string;
};

const STORAGE_KEY = 'marcos-os:new-objective';

function initialDraft(objective: EditableObjective | null): Draft {
    if (objective === null) {
        try {
            const saved = window.localStorage.getItem(STORAGE_KEY);

            if (saved) {
                return {
                    ...EMPTY_CONTROL_PLAN,
                    key: '',
                    title: '',
                    identity_statement: '',
                    ...(JSON.parse(saved) as Partial<Draft>),
                };
            }
        } catch {
            // Storage unavailable: start empty.
        }

        return {
            ...EMPTY_CONTROL_PLAN,
            key: '',
            title: '',
            identity_statement: '',
        };
    }

    const plan = objective.control_plan;
    const contingency = splitContingency(plan?.contingency ?? null);

    return {
        key: objective.key,
        title: objective.title,
        identity_statement: objective.identity_statement ?? '',
        outcome: plan?.outcome ?? '',
        deadline: plan?.deadline ?? '',
        metric_name: plan?.metric.name ?? '',
        metric_target: formatNumber(plan?.metric.target ?? null),
        metric_current: formatNumber(plan?.metric.current ?? null),
        risks: plan && plan.risks.length > 0 ? plan.risks : [''],
        contingency_when: contingency.when,
        contingency_then: contingency.then,
        control_map: [],
    };
}

/**
 * Screen 5 (mockup visual/screens/05-objective-form.html): the direct path,
 * without AI. A new objective is created — and born active — only with its
 * five points complete; the checklist says what is missing. What you type is
 * kept in this browser until the objective is created.
 */
export default function ObjectiveForm({
    objective,
}: {
    objective: EditableObjective | null;
}) {
    const creating = objective === null;
    const [draft, setDraft] = useState<Draft>(() => initialDraft(objective));
    const [errors, setErrors] = useState<Record<string, string | undefined>>(
        {},
    );
    const [processing, setProcessing] = useState(false);
    const [offline, setOffline] = useState(false);

    useEffect(() => {
        if (!creating) {
            return;
        }

        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(draft));
        } catch {
            // Storage unavailable: the draft only lives in memory.
        }
    }, [creating, draft]);

    const missing = missingPoints(draft);
    const whatIsMissing = [
        ...(draft.title.trim() === '' ? ['el título'] : []),
        ...(creating && draft.key.trim() === '' ? ['la clave'] : []),
        ...missing.map((point) => POINT_LABELS[point].toLowerCase()),
    ];
    const ready = whatIsMissing.length === 0;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setOffline(false);

        const payload = {
            title: draft.title,
            identity_statement: draft.identity_statement || null,
            ...controlPlanPayload(draft),
            ...(creating
                ? { key: draft.key, control_map: draft.control_map }
                : {}),
        };

        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (next: Record<string, string>) => setErrors(next),
            onNetworkError: () => {
                setOffline(true);

                return false;
            },
            onSuccess: () => {
                setErrors({});

                if (creating) {
                    try {
                        window.localStorage.removeItem(STORAGE_KEY);
                    } catch {
                        // Nothing to clean.
                    }
                }
            },
        };

        if (creating) {
            router.post(objectiveStore().url, payload, options);
        } else {
            router.patch(objectiveUpdate(objective.key).url, payload, options);
        }
    };

    const title = creating ? 'Nuevo objetivo' : `Editar ${objective.title}`;

    return (
        <div className="mos-s05">
            <Head title={title} />
            <Crumbs
                items={
                    creating
                        ? [
                              {
                                  label: 'Objetivos',
                                  href: objectivesIndex().url,
                              },
                              { label: 'Nuevo objetivo' },
                          ]
                        : [
                              {
                                  label: 'Objetivos',
                                  href: objectivesIndex().url,
                              },
                              {
                                  label: objective.title,
                                  href: objectiveShow(objective.key).url,
                              },
                              { label: 'Editar' },
                          ]
                }
            />
            <div className="fhead">
                <h1>{title}</h1>
                <p>
                    {creating
                        ? 'Camino directo, sin IA. Un objetivo se activa solo con sus cinco puntos completos.'
                        : 'Un objetivo activo no puede perder ninguno de sus cinco puntos.'}
                </p>
            </div>

            <div className="flayout">
                <form id="objective-form" onSubmit={submit} noValidate>
                    <section className="fsec" aria-labelledby="s1">
                        <h2 id="s1">Qué es</h2>
                        <p className="sec-sub">
                            El nombre aparece en el mapa como terminal de la
                            línea.
                        </p>
                        <div className="two">
                            <label className="fld">
                                <span className="l">Título</span>
                                <input
                                    className="in"
                                    name="title"
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
                                <span className="l">Clave</span>
                                <input
                                    className="in"
                                    name="key"
                                    value={draft.key}
                                    disabled={!creating}
                                    maxLength={10}
                                    onChange={(e) =>
                                        setDraft({
                                            ...draft,
                                            key: e.target.value
                                                .toUpperCase()
                                                .replace(/[^A-Z]/g, ''),
                                        })
                                    }
                                    style={{ textTransform: 'uppercase' }}
                                />
                                {errors.key && (
                                    <p className="msg-attn" role="alert">
                                        <AttentionIcon />
                                        {errors.key}
                                    </p>
                                )}
                                <span className="ex">
                                    {draft.key
                                        ? `Las tareas serán ${draft.key}-1, ${draft.key}-2…`
                                        : 'De 2 a 10 letras. Las tareas se numeran con ella.'}
                                </span>
                            </label>
                        </div>
                        <label className="fld">
                            <span className="l">
                                Frase de identidad (opcional)
                            </span>
                            <input
                                className="in"
                                name="identity_statement"
                                value={draft.identity_statement}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        identity_statement: e.target.value,
                                    })
                                }
                            />
                            <span className="ex">
                                Ej.: "Soy alguien que entrena". Suma votos
                                cuando marcás sus hábitos.
                            </span>
                        </label>
                    </section>

                    <section className="fsec" aria-labelledby="s2">
                        <h2 id="s2">Plan de 5 puntos</h2>
                        <p className="sec-sub">
                            Los cinco son obligatorios para activar. Podés
                            escribirlos cortos; se editan después.
                        </p>
                        <FivePointFields
                            draft={draft}
                            errors={errors}
                            noun="objetivo"
                            onChange={(next) => setDraft({ ...draft, ...next })}
                        />
                    </section>

                    {creating && (
                        <section className="fsec" aria-labelledby="s3">
                            <h2 id="s3">Mapa de control</h2>
                            <p className="sec-sub">
                                Separar lo que depende de vos de lo que no. Solo
                                las dos primeras zonas pueden volverse tareas.
                            </p>
                            <ControlMapFields
                                draft={draft}
                                onChange={(next) =>
                                    setDraft({ ...draft, ...next })
                                }
                            />
                        </section>
                    )}
                </form>

                <aside className="check5" aria-label="Qué falta para activar">
                    <h2>{creating ? 'Para activar' : 'Los cinco puntos'}</h2>
                    <ActivationChecklist draft={draft} />
                    <button
                        className="btn btn-primary"
                        type="submit"
                        form="objective-form"
                        disabled={!ready || processing}
                        aria-describedby="why"
                    >
                        {creating ? 'Crear objetivo' : 'Guardar cambios'}
                    </button>
                    <p className="why" id="why">
                        {ready
                            ? creating
                                ? 'Se crea activo, con sus cinco puntos.'
                                : 'Los cinco puntos están completos.'
                            : `Completá ${whatIsMissing.join(', ')} para ${creating ? 'activar' : 'guardar'}.${creating ? ' Lo escrito no se pierde si salís.' : ''}`}
                    </p>
                    {offline && (
                        <div
                            className="err"
                            role="alert"
                            style={{ marginTop: 12 }}
                        >
                            <AttentionIcon />
                            <span>
                                <b>No se pudo guardar: sin conexión.</b>
                                {creating
                                    ? 'Todo lo que escribiste queda en este navegador. '
                                    : ''}
                                Tocá el botón de nuevo cuando vuelva la
                                conexión.
                            </span>
                        </div>
                    )}
                    {!creating && (
                        <p className="alt">
                            El mapa de control se edita en{' '}
                            <Link href={objectiveShow(objective.key)}>
                                la pantalla del objetivo
                            </Link>
                            .
                        </p>
                    )}
                </aside>
            </div>
        </div>
    );
}

ObjectiveForm.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
