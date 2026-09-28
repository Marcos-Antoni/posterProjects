import { router } from '@inertiajs/react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { useEffect, useId, useState } from 'react';
import type { FormEvent } from 'react';

import {
    AddIcon,
    ArchiveIcon,
    ArrowIcon,
    CheckIcon,
    CloseIcon,
    DecisionIcon,
} from '@/components/marcos/retire-icons';
import type {
    RetirableKind,
    RetirementDecision,
} from '@/components/marcos/retire-icons';
import { toast } from '@/components/ui/toast';
import { cn } from '@/lib/utils';

/** What `RetireContext` (GET …/retire) answers. */
export type RetireContextPayload = {
    kind: RetirableKind;
    kind_label: string;
    feminine: boolean;
    title: string;
    subtitle: string;
    decisions: {
        value: RetirementDecision;
        label: string;
        description: string;
    }[];
    default_decision: RetirementDecision | null;
    content: { id: number; title: string }[];
    targets: { value: string; label: string }[];
    path: {
        before: { title: string; state: string }[];
        after: { title: string; state: string }[];
    } | null;
    used_reasons: string[];
};

type Part = { title: string; two_minute_version: string };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** GET endpoint answering the dialog context (JSON). */
    contextUrl: string;
    /** POST endpoint that retires the element. */
    actionUrl: string;
};

const MIN_REASON = 10;

const LETTERS = 'abcdefghij';

const ACTION_LABELS: Record<RetirementDecision, string> = {
    move: 'Retirar y mover',
    split: 'Retirar y dividir',
    archive_as_is: 'Retirar y archivar',
};

/**
 * The retire flow (screen 23, mockup visual/screens/23-retire-flow.html):
 * a written reason (10+ characters, with the reasons already used as
 * one-click shortcuts) and one decision on the content — move, split or
 * archive as-is — before anything is retired. No "¿Estás seguro?": the
 * primary button names the whole action and stays disabled, with its reason
 * written beside it, until both steps are complete. Nothing is deleted.
 */
export function RetireDialog({
    open,
    onOpenChange,
    contextUrl,
    actionUrl,
}: Props) {
    const [context, setContext] = useState<RetireContextPayload | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        fetch(contextUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json() as Promise<RetireContextPayload>;
            })
            .then((payload) => {
                setFailed(false);
                setContext(payload);
            })
            .catch((error: unknown) => {
                if (!(error instanceof DOMException)) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [open, contextUrl]);

    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 overlay-basalt duration-200 data-open:animate-in data-open:fade-in-0" />
                <DialogPrimitive.Content
                    className="mos mos-s23 fixed top-1/2 left-1/2 z-50 max-h-[calc(100svh-32px)] w-[min(680px,calc(100%-32px))] -translate-x-1/2 -translate-y-1/2 overflow-y-auto outline-none"
                    aria-describedby={undefined}
                >
                    {context ? (
                        <RetireForm
                            key={contextUrl}
                            context={context}
                            actionUrl={actionUrl}
                            onDone={() => onOpenChange(false)}
                        />
                    ) : (
                        <div className="dlg" aria-busy={!failed}>
                            <div className="dlg-head">
                                <DialogPrimitive.Title asChild>
                                    <h2>Retirar</h2>
                                </DialogPrimitive.Title>
                                <DialogPrimitive.Close
                                    className="icon-btn close"
                                    aria-label="Cerrar sin retirar"
                                >
                                    <CloseIcon />
                                </DialogPrimitive.Close>
                            </div>
                            <div className="dlg-body">
                                <p className="step" role="status">
                                    {failed
                                        ? 'No se pudo cargar. Cerrá y probá de nuevo.'
                                        : 'Cargando…'}
                                </p>
                            </div>
                        </div>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

function RetireForm({
    context,
    actionUrl,
    onDone,
}: {
    context: RetireContextPayload;
    actionUrl: string;
    onDone: () => void;
}) {
    const ids = useId();
    const [reason, setReason] = useState('');
    const [decision, setDecision] = useState<RetirementDecision | null>(
        context.default_decision ??
            (context.decisions.length === 1
                ? context.decisions[0].value
                : null),
    );
    const [target, setTarget] = useState(context.targets[0]?.value ?? '');
    const [parts, setParts] = useState<Part[]>([
        { title: '', two_minute_version: '' },
        { title: '', two_minute_version: '' },
    ]);
    const [assignments, setAssignments] = useState<Record<number, number>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const it = context.feminine ? 'la' : 'lo';
    const isPlan = context.kind === 'plan';
    const isObjective = context.kind === 'objective';
    const trimmed = reason.trim();
    const missing = Math.max(0, MIN_REASON - [...trimmed].length);
    const reasonReady = missing === 0;
    const splitReady =
        decision !== 'split' ||
        parts.every(
            (part) =>
                part.title.trim() !== '' &&
                (isPlan || part.two_minute_version.trim() !== ''),
        );
    const moveReady = decision !== 'move' || target !== '';
    const ready = reasonReady && decision !== null && splitReady && moveReady;
    const blockedBecause = !reasonReady
        ? `Escribí al menos ${MIN_REASON} caracteres para que te sirva después. Faltan ${missing}.`
        : decision === null
          ? 'Elegí qué hacés con lo que contiene.'
          : !moveReady
            ? 'Elegí adónde va lo que contiene.'
            : !splitReady
              ? isPlan
                  ? 'Cada plan nuevo necesita su título.'
                  : 'Cada parte necesita su título y su versión de 2 minutos.'
              : null;
    const errorFor = (key: string) => errors[key];

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!ready || decision === null) {
            return;
        }

        router.post(
            actionUrl,
            {
                reason: trimmed,
                decision,
                ...(decision === 'move' ? { target } : {}),
                ...(decision === 'split'
                    ? {
                          parts: parts.map((part) =>
                              isPlan ? { title: part.title } : part,
                          ),
                          ...(isPlan ? { assignments } : {}),
                      }
                    : {}),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (bag) => setErrors(bag),
                onSuccess: (page) => {
                    const flash = (
                        page.flash as {
                            retirement?: { message: string; url: string };
                        }
                    ).retirement;

                    onDone();
                    toast(
                        flash?.message ??
                            `${context.feminine ? 'Retirada' : 'Retirado'}. Está en Retirados.`,
                        flash
                            ? {
                                  action: {
                                      label: 'Ver en Retirados',
                                      onClick: () => router.visit(flash.url),
                                  },
                              }
                            : {},
                    );
                },
            },
        );
    };

    return (
        <form className="dlg" onSubmit={submit} noValidate>
            <div className="dlg-head">
                <div>
                    <DialogPrimitive.Title asChild>
                        <h2>
                            {isPlan
                                ? `Retirar el plan “${context.title}”`
                                : isObjective
                                  ? `Retirar el objetivo “${context.title}”`
                                  : `Retirar “${context.title}”`}
                        </h2>
                    </DialogPrimitive.Title>
                    <p>{context.subtitle}</p>
                </div>
                <DialogPrimitive.Close
                    className="icon-btn close"
                    aria-label="Cerrar sin retirar"
                    type="button"
                >
                    <CloseIcon />
                </DialogPrimitive.Close>
            </div>

            <div className="dlg-body">
                <div className="step">
                    <div className="step-h">
                        <span className={cn('step-n', reasonReady && 'ok')}>
                            1
                        </span>
                        <h3>
                            <label htmlFor={`${ids}-why`}>
                                ¿Por qué {it} retirás?
                            </label>
                        </h3>
                    </div>
                    <textarea
                        id={`${ids}-why`}
                        name="reason"
                        className="input"
                        rows={2}
                        value={reason}
                        maxLength={1000}
                        onChange={(event) => setReason(event.target.value)}
                        aria-describedby={`${ids}-why-h`}
                        aria-invalid={Boolean(errorFor('reason')) || undefined}
                    />
                    {errorFor('reason') ? (
                        <p className="field-err" id={`${ids}-why-h`}>
                            {errorFor('reason')}
                        </p>
                    ) : trimmed !== '' && !reasonReady ? (
                        <p className="field-err" id={`${ids}-why-h`}>
                            Escribí al menos {MIN_REASON} caracteres para que te
                            sirva después. Faltan {missing}.
                        </p>
                    ) : (
                        <div className="count" id={`${ids}-why-h`}>
                            <span>
                                Queda escrita en Retirados, para ver patrones
                                después.
                            </span>
                            {reasonReady && (
                                <span className="okt">
                                    <CheckIcon />
                                    Lista
                                </span>
                            )}
                        </div>
                    )}
                    {context.used_reasons.length > 0 && (
                        <div className="reuse">
                            <span>Razones que ya usaste:</span>
                            {context.used_reasons.map((used) => (
                                <button
                                    key={used}
                                    type="button"
                                    onClick={() => setReason(used)}
                                >
                                    {used}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                <div className="step">
                    <div className="step-h">
                        <span
                            className={cn('step-n', decision !== null && 'ok')}
                        >
                            2
                        </span>
                        <h3 id={`${ids}-dec`}>
                            ¿Qué hacés con lo que contiene?
                        </h3>
                    </div>
                    <div
                        className="opts"
                        role="radiogroup"
                        aria-labelledby={`${ids}-dec`}
                        style={
                            context.decisions.length < 3
                                ? {
                                      gridTemplateColumns: `repeat(${context.decisions.length}, 1fr)`,
                                  }
                                : undefined
                        }
                    >
                        {context.decisions.map((option) => (
                            <label
                                key={option.value}
                                className={cn(
                                    'opt',
                                    decision === option.value && 'on',
                                )}
                            >
                                <input
                                    type="radio"
                                    name={`${ids}-decision`}
                                    value={option.value}
                                    checked={decision === option.value}
                                    onChange={() => setDecision(option.value)}
                                />
                                <span className="t">
                                    <DecisionIcon decision={option.value} />
                                    {option.label}
                                </span>
                                <span className="d">{option.description}</span>
                                {context.default_decision === option.value &&
                                    context.decisions.length > 1 && (
                                        <span className="def">Por defecto</span>
                                    )}
                            </label>
                        ))}
                    </div>
                    {errorFor('decision') && (
                        <p className="field-err">{errorFor('decision')}</p>
                    )}

                    {decision === 'move' && (
                        <div className="move-box">
                            <div className="field">
                                <label htmlFor={`${ids}-target`}>
                                    {context.kind === 'plan'
                                        ? 'Mover sus tareas a'
                                        : isObjective
                                          ? 'Mover sus planes a'
                                          : 'Colgar lo que abre de'}
                                </label>
                                <select
                                    id={`${ids}-target`}
                                    className="input"
                                    value={target}
                                    onChange={(event) =>
                                        setTarget(event.target.value)
                                    }
                                >
                                    {context.targets.length === 0 && (
                                        <option value="">
                                            No hay adónde moverlo
                                        </option>
                                    )}
                                    {context.targets.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            {context.content.length > 0 && (
                                <ul>
                                    {context.content.map((row) => (
                                        <li key={row.id}>{row.title}</li>
                                    ))}
                                </ul>
                            )}
                            <p className="help">
                                {isPlan
                                    ? 'Conservan su número y sus dependencias.'
                                    : isObjective
                                      ? 'Sus tareas toman números nuevos en ese objetivo.'
                                      : 'Lo que abría pasa a esperar a la tarea que elijas.'}
                            </p>
                            {errorFor('target') && (
                                <p className="field-err">
                                    {errorFor('target')}
                                </p>
                            )}
                        </div>
                    )}

                    {decision === 'split' && (
                        <SplitBox
                            ids={ids}
                            context={context}
                            parts={parts}
                            setParts={setParts}
                            assignments={assignments}
                            setAssignments={setAssignments}
                            errorFor={errorFor}
                        />
                    )}
                </div>
            </div>

            <div className="dlg-foot">
                <p>
                    <ArchiveIcon size={18} />
                    Nada se borra. {context.feminine ? 'La' : 'Lo'} vas a ver en
                    Retirados y {it} podés devolver al mapa.
                </p>
                <div className="row">
                    <DialogPrimitive.Close
                        className="btn btn-outline"
                        type="button"
                    >
                        Cancelar
                    </DialogPrimitive.Close>
                    <button
                        className="btn btn-primary"
                        type="submit"
                        aria-disabled={!ready || processing || undefined}
                        aria-describedby={
                            blockedBecause ? `${ids}-blocked` : undefined
                        }
                    >
                        {decision ? ACTION_LABELS[decision] : 'Retirar'}
                    </button>
                </div>
                {blockedBecause && (
                    <div className="hint" id={`${ids}-blocked`}>
                        {blockedBecause}
                    </div>
                )}
            </div>
        </form>
    );
}

function SplitBox({
    ids,
    context,
    parts,
    setParts,
    assignments,
    setAssignments,
    errorFor,
}: {
    ids: string;
    context: RetireContextPayload;
    parts: Part[];
    setParts: (parts: Part[]) => void;
    assignments: Record<number, number>;
    setAssignments: (assignments: Record<number, number>) => void;
    errorFor: (key: string) => string | undefined;
}) {
    const isPlan = context.kind === 'plan';
    const noun = isPlan ? 'plan' : 'tarea';
    const update = (index: number, change: Partial<Part>) =>
        setParts(
            parts.map((part, other) =>
                other === index ? { ...part, ...change } : part,
            ),
        );
    const letters = parts.map((_, index) => LETTERS[index]);

    return (
        <div className="split-box">
            <div className="cols-h">
                <span />
                <span>{isPlan ? 'Nuevo plan' : 'Nueva tarea'}</span>
                {!isPlan && <span>Su versión de 2 minutos</span>}
            </div>
            {parts.map((part, index) => (
                <div className="newt" key={index}>
                    <span className="k">{LETTERS[index]}</span>
                    <input
                        className="input"
                        aria-label={`${isPlan ? 'Nuevo plan' : 'Nueva tarea'} ${LETTERS[index]}`}
                        value={part.title}
                        maxLength={255}
                        onChange={(event) =>
                            update(index, { title: event.target.value })
                        }
                    />
                    {!isPlan && (
                        <span className="two">
                            <i aria-hidden="true" />
                            <input
                                className="input"
                                aria-label={`Versión de 2 minutos de la tarea ${LETTERS[index]}`}
                                value={part.two_minute_version}
                                maxLength={255}
                                onChange={(event) =>
                                    update(index, {
                                        two_minute_version: event.target.value,
                                    })
                                }
                            />
                        </span>
                    )}
                    {(errorFor(`parts.${index}.title`) ||
                        errorFor(`parts.${index}.two_minute_version`)) && (
                        <p
                            className="field-err"
                            style={{ gridColumn: '2 / -1' }}
                        >
                            {errorFor(`parts.${index}.title`) ??
                                errorFor(`parts.${index}.two_minute_version`)}
                        </p>
                    )}
                </div>
            ))}
            {parts.length < LETTERS.length && (
                <button
                    className="btn btn-ghost btn-sm addrow"
                    type="button"
                    onClick={() =>
                        setParts([
                            ...parts,
                            { title: '', two_minute_version: '' },
                        ])
                    }
                >
                    <AddIcon />
                    Agregar otr{isPlan ? 'o plan' : 'a tarea'}
                </button>
            )}
            {errorFor('parts') && (
                <p className="field-err">{errorFor('parts')}</p>
            )}

            {isPlan && context.content.length > 0 && (
                <div className="inherit">
                    Sus tareas pasan al plan que elijas:
                    {context.content.map((row) => (
                        <div
                            className="newt"
                            key={row.id}
                            style={{ gridTemplateColumns: '1fr auto' }}
                        >
                            <label htmlFor={`${ids}-as-${row.id}`}>
                                {row.title}
                            </label>
                            <select
                                id={`${ids}-as-${row.id}`}
                                className="input"
                                value={assignments[row.id] ?? 0}
                                onChange={(event) =>
                                    setAssignments({
                                        ...assignments,
                                        [row.id]: Number(event.target.value),
                                    })
                                }
                            >
                                {letters.map((letter, index) => (
                                    <option key={letter} value={index}>
                                        {noun} {letter}
                                    </option>
                                ))}
                            </select>
                        </div>
                    ))}
                </div>
            )}

            {!isPlan && context.path && (
                <div className="inherit">
                    {parts.length === 2
                        ? 'Las dos heredan'
                        : `Las ${parts.length} heredan`}{' '}
                    su lugar en el camino, en el mismo plan:
                    <div className="path">
                        {context.path.before.map((node) => (
                            <span
                                key={`b-${node.title}`}
                                className={cn(
                                    'nd',
                                    node.state === 'done' && 'done',
                                    node.state === 'locked' && 'lock',
                                )}
                            >
                                {node.title}
                            </span>
                        ))}
                        {context.path.before.length > 0 && (
                            <span className="arr">
                                <ArrowIcon />
                            </span>
                        )}
                        <span className="nd new">
                            {letters.slice(0, -1).join(', ')} y{' '}
                            {letters[letters.length - 1]}
                        </span>
                        {context.path.after.length > 0 && (
                            <span className="arr">
                                <ArrowIcon />
                            </span>
                        )}
                        {context.path.after.map((node) => (
                            <span key={`a-${node.title}`} className="nd lock">
                                {node.title}
                            </span>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * The quiet "Retirar" affordance every element screen shows, wired to the
 * dialog. Never destructive-red: retiring deletes nothing.
 */
export function RetireButton({
    contextUrl,
    actionUrl,
    className = 'btn-sm btn-ghost',
    label = 'Retirar',
}: {
    contextUrl: string;
    actionUrl: string;
    className?: string;
    label?: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button
                className={className}
                type="button"
                onClick={() => setOpen(true)}
            >
                {label}
            </button>
            <RetireDialog
                open={open}
                onOpenChange={setOpen}
                contextUrl={contextUrl}
                actionUrl={actionUrl}
            />
        </>
    );
}
