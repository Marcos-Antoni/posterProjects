import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactElement } from 'react';

import { RetireButton } from '@/components/marcos/retire-dialog';
import { toast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';
import {
    convertToHabit,
    convertToItem,
    convertToObjective,
    retire as retireCapture,
    retireContext as captureRetireContext,
} from '@/routes/captures';

type Capture = {
    id: number;
    text: string;
    source: 'web' | 'mobile' | 'ai';
    source_label: string;
    created_at: string | null;
};

type Decided = {
    id: number;
    text: string;
    triaged: boolean;
    result_url: string | null;
};

type ObjectiveOption = {
    key: string;
    title: string;
    plans: { id: number; title: string }[];
};

type Props = {
    captures: Capture[];
    decided: Decided[];
    objectives: ObjectiveOption[];
};

type Tab = 'item' | 'habit' | 'objective' | 'retire';

/**
 * Screen 16 (mockup visual/screens/16-capture-inbox.html): untriaged
 * captures oldest first, one decision at a time. Nothing here is a
 * priority: no dates, no urgency — only the source and when it came in
 * (capture-inbox spec).
 */
export default function CaptureIndex({ captures, decided, objectives }: Props) {
    const [openId, setOpenId] = useState<number | null>(
        captures[0]?.id ?? null,
    );

    return (
        <div className="mos-s16">
            <Head title="Inbox de capturas" />
            <div className="head">
                <div>
                    <h1 className="h1">Inbox</h1>
                    <p className="lede">
                        Ideas capturadas sin fecha ni prioridad. Nada de acá
                        aparece en Ahora hasta que decidas qué es. Empezá por la
                        más antigua.
                    </p>
                </div>
            </div>

            {captures.length === 0 ? (
                <div className="card" style={{ padding: 24 }}>
                    <h3 style={{ margin: '0 0 4px' }}>
                        No hay nada sin decidir.
                    </h3>
                    <p className="meta">
                        Lo que captures con <kbd>C</kbd> desde cualquier
                        pantalla, o desde el teléfono, llega acá sin fecha ni
                        prioridad.
                    </p>
                </div>
            ) : (
                <ol className="card" style={{ padding: 8, listStyle: 'none' }}>
                    {captures.map((capture) => (
                        <CaptureRow
                            key={capture.id}
                            capture={capture}
                            objectives={objectives}
                            open={openId === capture.id}
                            onToggle={() =>
                                setOpenId((current) =>
                                    current === capture.id ? null : capture.id,
                                )
                            }
                        />
                    ))}
                </ol>
            )}

            {decided.length > 0 && (
                <details className="triaged" open style={{ marginTop: 32 }}>
                    <summary>Decididas hoy ({decided.length})</summary>
                    <ul style={{ listStyle: 'none', padding: 0 }}>
                        {decided.map((entry) => (
                            <li key={entry.id} className="meta">
                                <b>“{entry.text}”</b>{' '}
                                {entry.triaged ? 'se convirtió.' : 'se retiró.'}
                                {entry.result_url && (
                                    <>
                                        {' '}
                                        <a href={entry.result_url}>Abrir</a>
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}

function CaptureRow({
    capture,
    objectives,
    open,
    onToggle,
}: {
    capture: Capture;
    objectives: ObjectiveOption[];
    open: boolean;
    onToggle: () => void;
}) {
    const [tab, setTab] = useState<Tab>('item');

    return (
        <li className={open ? 'cap open' : 'cap'} style={{ padding: 16 }}>
            <div
                style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    gap: 12,
                    alignItems: 'flex-start',
                }}
            >
                <div>
                    <p className="txt" style={{ margin: 0, fontWeight: 600 }}>
                        {capture.text}
                    </p>
                    <p className="meta">
                        {capture.source_label}
                        {capture.created_at
                            ? `, ${new Date(capture.created_at).toLocaleString('es')}`
                            : ''}
                    </p>
                </div>
                <button className="btn-text" type="button" onClick={onToggle}>
                    {open ? 'Cerrar' : 'Decidir'}
                </button>
            </div>

            {open && (
                <div className="tri" style={{ marginTop: 12 }}>
                    <div
                        className="seg"
                        role="tablist"
                        aria-label="Convertir en"
                    >
                        <button
                            role="tab"
                            aria-selected={tab === 'item'}
                            type="button"
                            onClick={() => setTab('item')}
                        >
                            Tarea
                        </button>
                        <button
                            role="tab"
                            aria-selected={tab === 'habit'}
                            type="button"
                            onClick={() => setTab('habit')}
                        >
                            Hábito
                        </button>
                        <button
                            role="tab"
                            aria-selected={tab === 'objective'}
                            type="button"
                            onClick={() => setTab('objective')}
                        >
                            Objetivo nuevo
                        </button>
                        <button
                            role="tab"
                            aria-selected={tab === 'retire'}
                            type="button"
                            onClick={() => setTab('retire')}
                        >
                            Retirar
                        </button>
                    </div>

                    {tab === 'item' && (
                        <ConvertToItemForm
                            capture={capture}
                            objectives={objectives}
                        />
                    )}
                    {tab === 'habit' && (
                        <ConvertToHabitForm capture={capture} />
                    )}
                    {tab === 'objective' && (
                        <ConvertToObjectiveForm capture={capture} />
                    )}
                    {tab === 'retire' && (
                        <div style={{ marginTop: 12 }}>
                            <RetireButton
                                className="btn btn-outline"
                                label="Retirar esta captura"
                                contextUrl={
                                    captureRetireContext(capture.id).url
                                }
                                actionUrl={retireCapture(capture.id).url}
                            />
                        </div>
                    )}
                </div>
            )}
        </li>
    );
}

function ConvertToItemForm({
    capture,
    objectives,
}: {
    capture: Capture;
    objectives: ObjectiveOption[];
}) {
    const [objectiveKey, setObjectiveKey] = useState(objectives[0]?.key ?? '');
    const [planId, setPlanId] = useState(
        String(objectives[0]?.plans[0]?.id ?? ''),
    );
    const [title, setTitle] = useState(capture.text);
    const [twoMinute, setTwoMinute] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const plans =
        objectives.find((objective) => objective.key === objectiveKey)?.plans ??
        [];

    if (objectives.length === 0) {
        return (
            <p className="meta" style={{ marginTop: 12 }}>
                No hay objetivos activos todavía.
            </p>
        );
    }

    const submit = () => {
        setProcessing(true);
        router.post(
            convertToItem(capture.id).url,
            {
                objective_key: objectiveKey,
                plan_id: planId,
                title,
                two_minute_version: twoMinute,
            },
            {
                preserveScroll: true,
                onSuccess: () => toast('Convertida en tarea.'),
                onError: (validationErrors) =>
                    setErrors(validationErrors as Record<string, string>),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="tri-grid" style={{ marginTop: 12 }}>
            <label className="fld full">
                <span className="l">Título de la tarea</span>
                <input
                    className="in"
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                />
            </label>
            <label className="fld">
                <span className="l">Objetivo</span>
                <select
                    className="in"
                    value={objectiveKey}
                    onChange={(event) => {
                        setObjectiveKey(event.target.value);
                        const next = objectives.find(
                            (objective) => objective.key === event.target.value,
                        );
                        setPlanId(String(next?.plans[0]?.id ?? ''));
                    }}
                >
                    {objectives.map((objective) => (
                        <option key={objective.key} value={objective.key}>
                            {objective.title}
                        </option>
                    ))}
                </select>
            </label>
            <label className="fld">
                <span className="l">Plan</span>
                <select
                    className="in"
                    value={planId}
                    onChange={(event) => setPlanId(event.target.value)}
                >
                    {plans.map((plan) => (
                        <option key={plan.id} value={plan.id}>
                            {plan.title}
                        </option>
                    ))}
                </select>
            </label>
            <label className="fld full">
                <span className="l">Versión de 2 minutos</span>
                <input
                    className="in"
                    value={twoMinute}
                    onChange={(event) => setTwoMinute(event.target.value)}
                    placeholder="Un verbo físico"
                />
            </label>
            {Object.values(errors).length > 0 && (
                <p className="err full" role="alert">
                    {Object.values(errors)[0]}
                </p>
            )}
            <div className="tri-actions full">
                <button
                    className="btn btn-primary"
                    type="button"
                    disabled={
                        processing ||
                        twoMinute.trim() === '' ||
                        title.trim() === '' ||
                        planId === ''
                    }
                    onClick={submit}
                >
                    Crear tarea
                </button>
                {twoMinute.trim() === '' && (
                    <span className="disabled-why">
                        Falta la versión de 2 minutos.
                    </span>
                )}
            </div>
        </div>
    );
}

function ConvertToHabitForm({ capture }: { capture: Capture }) {
    const [name, setName] = useState(capture.text);
    const [twoMinute, setTwoMinute] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        router.post(
            convertToHabit(capture.id).url,
            { name, two_minute_version: twoMinute },
            {
                preserveScroll: true,
                onSuccess: () => toast('Convertida en hábito.'),
                onError: (validationErrors) =>
                    setErrors(validationErrors as Record<string, string>),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div style={{ marginTop: 12 }}>
            <label className="fld">
                <span className="l">Nombre del hábito</span>
                <input
                    className="in"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                />
            </label>
            <label className="fld">
                <span className="l">Versión de 2 minutos</span>
                <input
                    className="in"
                    value={twoMinute}
                    onChange={(event) => setTwoMinute(event.target.value)}
                />
            </label>
            {Object.values(errors).length > 0 && (
                <p className="err" role="alert">
                    {Object.values(errors)[0]}
                </p>
            )}
            <button
                className="btn btn-primary"
                type="button"
                disabled={
                    processing || twoMinute.trim() === '' || name.trim() === ''
                }
                onClick={submit}
            >
                Crear hábito
            </button>
        </div>
    );
}

function ConvertToObjectiveForm({ capture }: { capture: Capture }) {
    const [key, setKey] = useState('');
    const [title, setTitle] = useState(capture.text);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        router.post(
            convertToObjective(capture.id).url,
            { key: key.toUpperCase(), title },
            {
                preserveScroll: true,
                onError: (validationErrors) =>
                    setErrors(validationErrors as Record<string, string>),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div style={{ marginTop: 12 }}>
            <label className="fld">
                <span className="l">Clave (2 a 10 letras)</span>
                <input
                    className="in"
                    value={key}
                    onChange={(event) =>
                        setKey(event.target.value.toUpperCase())
                    }
                    maxLength={10}
                />
            </label>
            <label className="fld">
                <span className="l">Título</span>
                <input
                    className="in"
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                />
            </label>
            {Object.values(errors).length > 0 && (
                <p className="err" role="alert">
                    {Object.values(errors)[0]}
                </p>
            )}
            <button
                className="btn btn-primary"
                type="button"
                disabled={
                    processing || key.trim() === '' || title.trim() === ''
                }
                onClick={submit}
            >
                Crear objetivo (borrador)
            </button>
        </div>
    );
}

CaptureIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
