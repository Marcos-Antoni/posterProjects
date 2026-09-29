import { AttentionIcon, PlusIcon } from '@/components/marcos/icons';
import type { ControlZone } from '@/types/models';

/**
 * The editable state of the Control 5-point plan and control map, shared by
 * the objective form (mockup 05) and the plan form (mockup 06, "Nuevo plan").
 * The contingency is written as trigger + response ("Cuando … entonces …")
 * and stored as one sentence.
 */
export type ControlPlanDraft = {
    outcome: string;
    deadline: string;
    metric_name: string;
    metric_target: string;
    metric_current: string;
    risks: string[];
    contingency_when: string;
    contingency_then: string;
    control_map: { zone: ControlZone; text: string }[];
};

export const EMPTY_CONTROL_PLAN: ControlPlanDraft = {
    outcome: '',
    deadline: '',
    metric_name: '',
    metric_target: '',
    metric_current: '',
    risks: [''],
    contingency_when: '',
    contingency_then: '',
    control_map: [],
};

/** Split "Cuando X, entonces Y." back into its two halves (else all in "entonces"). */
export function splitContingency(contingency: string | null): {
    when: string;
    then: string;
} {
    const match = (contingency ?? '').match(
        /^Cuando (.+?),? entonces (.+?)\.?$/i,
    );

    return match
        ? { when: match[1], then: match[2] }
        : { when: '', then: contingency ?? '' };
}

export function joinContingency(when: string, then: string): string {
    const trigger = when.trim();
    const response = then.trim();

    if (trigger === '' && response === '') {
        return '';
    }

    if (trigger === '') {
        return response;
    }

    return `Cuando ${trigger}, entonces ${response}.`;
}

export type PointKey =
    'outcome' | 'deadline' | 'metric' | 'risks' | 'contingency';

export const POINT_LABELS: Record<PointKey, string> = {
    outcome: 'Resultado',
    deadline: 'Fecha límite',
    metric: 'Una métrica',
    risks: 'Qué puede salir mal',
    contingency: 'Contingencia',
};

/** Which of the five points are still missing, in order. */
export function missingPoints(draft: ControlPlanDraft): PointKey[] {
    const missing: PointKey[] = [];

    if (draft.outcome.trim() === '') {
        missing.push('outcome');
    }

    if (draft.deadline === '') {
        missing.push('deadline');
    }

    if (draft.metric_name.trim() === '' || draft.metric_target.trim() === '') {
        missing.push('metric');
    }

    if (draft.risks.every((risk) => risk.trim() === '')) {
        missing.push('risks');
    }

    if (
        joinContingency(draft.contingency_when, draft.contingency_then) === ''
    ) {
        missing.push('contingency');
    }

    return missing;
}

/** The request payload for the 5 points and the map (keys of the form requests). */
export function controlPlanPayload(draft: ControlPlanDraft) {
    return {
        outcome: draft.outcome,
        deadline: draft.deadline || null,
        metric: {
            name: draft.metric_name,
            target: draft.metric_target === '' ? null : draft.metric_target,
            current: draft.metric_current === '' ? null : draft.metric_current,
        },
        risks: draft.risks.map((risk) => risk.trim()).filter(Boolean),
        contingency: joinContingency(
            draft.contingency_when,
            draft.contingency_then,
        ),
    };
}

type FieldsProps = {
    draft: ControlPlanDraft;
    errors: Record<string, string | undefined>;
    onChange: (next: ControlPlanDraft) => void;
    /** "objetivo" or "plan", for the one-metric message. */
    noun: string;
};

function Marker({ index, ok }: { index: number; ok: boolean }) {
    return (
        <span className={ok ? 'n ok' : 'n miss'} aria-hidden="true">
            {index}
        </span>
    );
}

function Problem({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p className="msg-attn" role="alert">
            <AttentionIcon />
            {message}
        </p>
    );
}

/** The five numbered points (mockup 05 `.pt`). */
export function FivePointFields({
    draft,
    errors,
    onChange,
    noun,
}: FieldsProps) {
    const missing = missingPoints(draft);
    const set = (patch: Partial<ControlPlanDraft>) =>
        onChange({ ...draft, ...patch });
    const metricError =
        errors.metric ??
        errors.metrics ??
        errors['metric.name'] ??
        errors['metric.target'] ??
        errors['metric.current'];

    return (
        <>
            <div className="pt">
                <Marker index={1} ok={!missing.includes('outcome')} />
                <label className="fld">
                    <span className="l">Resultado</span>
                    <textarea
                        className="in"
                        name="outcome"
                        value={draft.outcome}
                        onChange={(e) => set({ outcome: e.target.value })}
                    />
                    <Problem message={errors.outcome} />
                    <span className="ex">
                        Qué es verdad cuando está hecho, en una frase que se
                        pueda comprobar. Ej.: "Corro 5 km sin parar".
                    </span>
                </label>
            </div>

            <div className="pt">
                <Marker index={2} ok={!missing.includes('deadline')} />
                <label className="fld">
                    <span className="l">Fecha límite</span>
                    <input
                        className="in"
                        type="date"
                        name="deadline"
                        value={draft.deadline}
                        onChange={(e) => set({ deadline: e.target.value })}
                        style={{ maxWidth: 340 }}
                    />
                    <Problem message={errors.deadline} />
                    <span className="ex">
                        Hora de Guatemala. Una fecha, no "cuando pueda".
                    </span>
                </label>
            </div>

            <div className="pt">
                <Marker index={3} ok={!missing.includes('metric')} />
                <div className="fld">
                    <span className="l">Una métrica</span>
                    <div className="three">
                        <label>
                            <span className="sr">Nombre de la métrica</span>
                            <input
                                className={
                                    draft.metric_name.trim() === ''
                                        ? 'in need'
                                        : 'in'
                                }
                                name="metric_name"
                                placeholder="Qué vas a contar"
                                value={draft.metric_name}
                                onChange={(e) =>
                                    set({ metric_name: e.target.value })
                                }
                            />
                        </label>
                        <label>
                            <span className="sr">Meta</span>
                            <input
                                className={
                                    draft.metric_target.trim() === ''
                                        ? 'in need'
                                        : 'in'
                                }
                                name="metric_target"
                                placeholder="Meta"
                                inputMode="decimal"
                                value={draft.metric_target}
                                onChange={(e) =>
                                    set({ metric_target: e.target.value })
                                }
                            />
                        </label>
                        <label>
                            <span className="sr">Valor actual</span>
                            <input
                                className="in"
                                name="metric_current"
                                placeholder="Actual (opc.)"
                                inputMode="decimal"
                                value={draft.metric_current}
                                onChange={(e) =>
                                    set({ metric_current: e.target.value })
                                }
                            />
                        </label>
                    </div>
                    {metricError ? (
                        <Problem message={metricError} />
                    ) : (
                        missing.includes('metric') && (
                            <p className="msg-attn">
                                <AttentionIcon />
                                Falta la métrica. Una sola: la que mejor te diga
                                si vas bien.
                            </p>
                        )
                    )}
                    <span className="ex">
                        Ej. de Control: "Pantallas en uso diario: 2". Nombre,
                        número meta y, si querés, dónde estás hoy. Solo una
                        métrica por {noun}.
                    </span>
                </div>
            </div>

            <div className="pt">
                <Marker index={4} ok={!missing.includes('risks')} />
                <div className="fld">
                    <span className="l">Qué puede salir mal</span>
                    {draft.risks.map((risk, index) => (
                        <div className="risk" key={index}>
                            <input
                                className="in"
                                name="risks[]"
                                aria-label={`Riesgo ${index + 1}`}
                                value={risk}
                                onChange={(e) =>
                                    set({
                                        risks: draft.risks.map((value, i) =>
                                            i === index
                                                ? e.target.value
                                                : value,
                                        ),
                                    })
                                }
                            />
                            {draft.risks.length > 1 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        set({
                                            risks: draft.risks.filter(
                                                (_, i) => i !== index,
                                            ),
                                        })
                                    }
                                >
                                    Quitar
                                </button>
                            )}
                        </div>
                    ))}
                    <button
                        className="addl"
                        type="button"
                        onClick={() => set({ risks: [...draft.risks, ''] })}
                    >
                        <PlusIcon />
                        Agregar otro riesgo
                    </button>
                    <Problem message={errors.risks} />
                </div>
            </div>

            <div className="pt">
                <Marker index={5} ok={!missing.includes('contingency')} />
                <div className="fld">
                    <span className="l">Contingencia</span>
                    <div className="whenthen">
                        <span>Cuando</span>
                        <input
                            className="in"
                            name="contingency_when"
                            aria-label="Cuando"
                            value={draft.contingency_when}
                            onChange={(e) =>
                                set({ contingency_when: e.target.value })
                            }
                        />
                        <span>entonces</span>
                        <input
                            className="in"
                            name="contingency_then"
                            aria-label="Entonces"
                            value={draft.contingency_then}
                            onChange={(e) =>
                                set({ contingency_then: e.target.value })
                            }
                        />
                    </div>
                    <Problem message={errors.contingency} />
                    <span className="ex">
                        Qué hacés si un riesgo pasa. Escribilo como disparador y
                        respuesta.
                    </span>
                </div>
            </div>
        </>
    );
}

const ZONES: { zone: ControlZone; title: string }[] = [
    { zone: 'mine', title: 'Depende de mí' },
    { zone: 'influence', title: 'Puedo influir' },
    { zone: 'outside', title: 'No depende de mí' },
];

/**
 * The three zones of the control map on a form (mockup 05 `.cmap3`): entries
 * added with Enter. Only used while creating; afterwards the map is edited in
 * place on the objective or plan screen.
 */
export function ControlMapFields({
    draft,
    onChange,
}: Pick<FieldsProps, 'draft' | 'onChange'>) {
    return (
        <div className="cmap3">
            {ZONES.map(({ zone, title }) => (
                <div
                    key={zone}
                    className={zone === 'outside' ? 'zone out' : 'zone'}
                >
                    <h3>{title}</h3>
                    <ul>
                        {draft.control_map
                            .map((entry, index) => ({ entry, index }))
                            .filter(({ entry }) => entry.zone === zone)
                            .map(({ entry, index }) => (
                                <li
                                    key={index}
                                    className="flex items-center justify-between gap-2"
                                >
                                    <span>{entry.text}</span>
                                    <button
                                        className="linkbtn quiet"
                                        type="button"
                                        aria-label={`Quitar «${entry.text}»`}
                                        onClick={() =>
                                            onChange({
                                                ...draft,
                                                control_map:
                                                    draft.control_map.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                            })
                                        }
                                    >
                                        Quitar
                                    </button>
                                </li>
                            ))}
                    </ul>
                    <input
                        className="in"
                        placeholder="Agregar…"
                        aria-label={`Agregar a ${title}`}
                        onKeyDown={(event) => {
                            const input = event.currentTarget;

                            if (event.key === 'Enter') {
                                event.preventDefault();

                                if (input.value.trim() !== '') {
                                    onChange({
                                        ...draft,
                                        control_map: [
                                            ...draft.control_map,
                                            { zone, text: input.value.trim() },
                                        ],
                                    });
                                    input.value = '';
                                }
                            }
                        }}
                    />
                    {zone === 'outside' && (
                        <p className="note">No se convierte en tarea.</p>
                    )}
                </div>
            ))}
        </div>
    );
}

/** The "Para activar" checklist (mockup 05 `.check5`). */
export function ActivationChecklist({ draft }: { draft: ControlPlanDraft }) {
    const missing = missingPoints(draft);

    return (
        <ol>
            {(Object.keys(POINT_LABELS) as PointKey[]).map((point) => (
                <li
                    key={point}
                    className={missing.includes(point) ? 'miss' : undefined}
                >
                    <i aria-hidden="true" />
                    {POINT_LABELS[point]}
                    {missing.includes(point) && ': falta'}
                </li>
            ))}
        </ol>
    );
}
