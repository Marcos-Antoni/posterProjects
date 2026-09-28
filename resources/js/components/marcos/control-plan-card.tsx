import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

import { PencilIcon } from '@/components/marcos/icons';
import { formatLongDateCapitalized, formatNumber } from '@/lib/marcos';
import type { ControlPlan } from '@/types/models';

type Point = 'outcome' | 'deadline' | 'metric' | 'risks' | 'contingency';

const LABELS: Record<Point, string> = {
    outcome: 'Resultado',
    deadline: 'Fecha límite',
    metric: 'Una métrica',
    risks: 'Qué puede salir mal',
    contingency: 'Contingencia',
};

const EDIT_LABELS: Record<Point, string> = {
    outcome: 'Editar resultado',
    deadline: 'Editar fecha',
    metric: 'Actualizar métrica',
    risks: 'Editar riesgos',
    contingency: 'Editar contingencia',
};

/**
 * The "Plan de 5 puntos" card of the objective screen (mockup 04 `.card5`):
 * the five points in order, each editable in place (one PATCH per point).
 * The metric is progress toward its target, drawn in blaze ink — never in the
 * danger color, whatever the deadline (control-plan spec).
 */
export function ControlPlanCard({
    controlPlan,
    updateUrl,
    writable,
}: {
    controlPlan: ControlPlan | null;
    updateUrl: string;
    writable: boolean;
}) {
    const [editing, setEditing] = useState<Point | null>(null);
    const points: Point[] = [
        'outcome',
        'deadline',
        'metric',
        'risks',
        'contingency',
    ];

    return (
        <section className="card5" aria-labelledby="p5-title">
            <h2 id="p5-title">Plan de 5 puntos</h2>
            <ol className="p5">
                {points.map((point) => (
                    <li key={point}>
                        <span className="l">{LABELS[point]}</span>
                        {editing === point ? (
                            <PointEditor
                                point={point}
                                controlPlan={controlPlan}
                                updateUrl={updateUrl}
                                onDone={() => setEditing(null)}
                            />
                        ) : (
                            <>
                                <PointValue
                                    point={point}
                                    controlPlan={controlPlan}
                                />
                                {writable && (
                                    <button
                                        className="ed"
                                        type="button"
                                        aria-label={EDIT_LABELS[point]}
                                        onClick={() => setEditing(point)}
                                    >
                                        <PencilIcon />
                                    </button>
                                )}
                            </>
                        )}
                    </li>
                ))}
            </ol>
        </section>
    );
}

function PointValue({
    point,
    controlPlan,
}: {
    point: Point;
    controlPlan: ControlPlan | null;
}): ReactNode {
    const missing = <p className="v muted">Falta.</p>;

    if (controlPlan === null) {
        return missing;
    }

    switch (point) {
        case 'outcome':
            return controlPlan.outcome ? (
                <p className="v">{controlPlan.outcome}</p>
            ) : (
                missing
            );
        case 'deadline':
            return controlPlan.deadline ? (
                <p className="v">
                    {formatLongDateCapitalized(controlPlan.deadline)}
                </p>
            ) : (
                missing
            );
        case 'metric':
            return controlPlan.metric.name ? (
                <div className="v">
                    {controlPlan.metric.name}
                    <div className="bar" aria-hidden="true">
                        <i
                            style={{
                                width: `${controlPlan.metric.progress_percent ?? 0}%`,
                            }}
                        />
                    </div>
                    <span className="small muted tnum">
                        {formatNumber(controlPlan.metric.current ?? 0)} de{' '}
                        {formatNumber(controlPlan.metric.target)}
                    </span>
                </div>
            ) : (
                missing
            );
        case 'risks':
            return controlPlan.risks.length > 0 ? (
                <div className="v">
                    <ul>
                        {controlPlan.risks.map((risk) => (
                            <li key={risk}>{risk}</li>
                        ))}
                    </ul>
                </div>
            ) : (
                missing
            );
        case 'contingency':
            return controlPlan.contingency ? (
                <p className="v">{controlPlan.contingency}</p>
            ) : (
                missing
            );
    }
}

function PointEditor({
    point,
    controlPlan,
    updateUrl,
    onDone,
}: {
    point: Point;
    controlPlan: ControlPlan | null;
    updateUrl: string;
    onDone: () => void;
}) {
    const form = useForm({
        outcome: controlPlan?.outcome ?? '',
        deadline: controlPlan?.deadline ?? '',
        metric_name: controlPlan?.metric.name ?? '',
        metric_target: controlPlan?.metric.target?.toString() ?? '',
        metric_current: controlPlan?.metric.current?.toString() ?? '',
        risks: (controlPlan?.risks ?? []).join('\n'),
        contingency: controlPlan?.contingency ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => {
            switch (point) {
                case 'metric':
                    return {
                        metric: {
                            name: data.metric_name,
                            target:
                                data.metric_target === ''
                                    ? null
                                    : data.metric_target,
                            current:
                                data.metric_current === ''
                                    ? null
                                    : data.metric_current,
                        },
                    };
                case 'risks':
                    return {
                        risks: data.risks
                            .split('\n')
                            .map((risk) => risk.trim())
                            .filter(Boolean),
                    };
                case 'deadline':
                    return { deadline: data.deadline || null };
                default:
                    return { [point]: data[point] };
            }
        });

        form.patch(updateUrl, { preserveScroll: true, onSuccess: onDone });
    };

    const errors = form.errors as Record<string, string | undefined>;
    const error =
        errors[point] ??
        errors[`${point}.name`] ??
        errors['metric.target'] ??
        errors['metric.current'];

    return (
        <form className="editing" onSubmit={submit}>
            {point === 'metric' ? (
                <div className="grid gap-2">
                    <label className="sr" htmlFor="metric-name">
                        Nombre de la métrica
                    </label>
                    <input
                        id="metric-name"
                        className="in"
                        value={form.data.metric_name}
                        onChange={(e) =>
                            form.setData('metric_name', e.target.value)
                        }
                        placeholder="Qué vas a contar"
                    />
                    <div className="grid grid-cols-2 gap-2">
                        <label>
                            <span className="sr">Meta</span>
                            <input
                                className="in"
                                inputMode="decimal"
                                value={form.data.metric_target}
                                onChange={(e) =>
                                    form.setData(
                                        'metric_target',
                                        e.target.value,
                                    )
                                }
                                placeholder="Meta"
                            />
                        </label>
                        <label>
                            <span className="sr">Valor actual</span>
                            <input
                                className="in"
                                inputMode="decimal"
                                value={form.data.metric_current}
                                onChange={(e) =>
                                    form.setData(
                                        'metric_current',
                                        e.target.value,
                                    )
                                }
                                placeholder="Actual"
                            />
                        </label>
                    </div>
                </div>
            ) : point === 'deadline' ? (
                <>
                    <label className="sr" htmlFor="deadline">
                        Fecha límite
                    </label>
                    <input
                        id="deadline"
                        type="date"
                        className="in"
                        value={form.data.deadline}
                        onChange={(e) =>
                            form.setData('deadline', e.target.value)
                        }
                    />
                </>
            ) : (
                <>
                    <label className="sr" htmlFor={`point-${point}`}>
                        {LABELS[point]}
                    </label>
                    <textarea
                        id={`point-${point}`}
                        className="in"
                        value={form.data[point]}
                        onChange={(e) => form.setData(point, e.target.value)}
                        placeholder={
                            point === 'risks'
                                ? 'Un riesgo por línea'
                                : undefined
                        }
                    />
                </>
            )}
            {error && (
                <p className="msg-attn" role="alert">
                    {error}
                </p>
            )}
            <div className="row">
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
                    onClick={onDone}
                >
                    Cancelar
                </button>
            </div>
        </form>
    );
}
