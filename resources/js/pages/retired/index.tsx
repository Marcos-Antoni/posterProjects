import { Head, router } from '@inertiajs/react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { useState } from 'react';
import type { ReactElement } from 'react';

import {
    ArchiveIcon,
    DecisionIcon,
    KindGlyph,
    RestoreIcon,
} from '@/components/marcos/retire-icons';
import type {
    RetirableKind,
    RetirementDecision,
} from '@/components/marcos/retire-icons';
import { toast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';
import {
    index as retiredIndex,
    restore as retiredRestore,
} from '@/routes/retired';

type Entry = {
    id: number;
    kind: RetirableKind;
    kind_label: string;
    title: string;
    objective_key: string | null;
    objective_label: string;
    reason: string;
    decision: RetirementDecision;
    decision_text: string;
    is_root: boolean;
    includes: number;
    age_days: number;
    retired_at: string;
    retired_on: string;
    month: string;
    restore: {
        allowed: boolean;
        blocked_reason: string | null;
        summary: string[];
    };
};

type Filters = {
    kind: RetirableKind | null;
    objective: string | null;
    month: string | null;
};

type View = {
    total: number;
    filters: Filters;
    kinds: { value: RetirableKind | 'all'; label: string; count: number }[];
    objectives: { key: string; title: string }[];
    month_options: { value: string; label: string }[];
    months: { value: string; label: string; count: number; entries: Entry[] }[];
    patterns: {
        total: number;
        observation: string | null;
        question: string | null;
        reasons: { keyword: string; count: number }[];
        other_reasons: number;
        median_ages: {
            kind: RetirableKind;
            label: string;
            count: number;
            days: number;
        }[];
    };
    used_reasons: string[];
};

const MONTHS_SHORT = [
    'ene',
    'feb',
    'mar',
    'abr',
    'may',
    'jun',
    'jul',
    'ago',
    'sep',
    'oct',
    'nov',
    'dic',
];

function days(count: number): string {
    return count === 1 ? '1 día' : `${count} días`;
}

function shortDate(iso: string): string {
    const [, month, day] = iso.split('-').map(Number);

    return `${day} ${MONTHS_SHORT[month - 1]}`;
}

function retiros(count: number): string {
    return count === 1 ? '1 retiro' : `${count} retiros`;
}

/**
 * Screen 22 (mockup visual/screens/22-retired-view.html): everything Marco
 * set aside, with the reason he wrote at the time, grouped by month — a
 * reflective archive to notice patterns, never a failure list. The
 * observation leads; counts are of his own words; ages read as "how long it
 * took to notice", never as a rate. Every row can go back to the map.
 */
export default function RetiredIndex({ view }: { view: View }) {
    const [restoring, setRestoring] = useState<Entry | null>(null);
    const filters = view.filters;

    const filter = (change: Partial<Filters>) => {
        const next = { ...filters, ...change };

        router.get(
            retiredIndex.url({
                query: Object.fromEntries(
                    Object.entries(next).filter(([, value]) => value !== null),
                ),
            }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const topReasons = view.patterns.reasons;

    return (
        <div className="mos-s22">
            <Head title="Retirados" />
            <div className="page-head">
                <div>
                    <h1>Retirados</h1>
                    <p className="lede">
                        Lo que dejaste de lado, con la razón que escribiste en
                        ese momento. Sirve para ver cómo encarás las cosas. Nada
                        de esto se borró: cualquier elemento puede volver al
                        mapa.
                    </p>
                </div>
            </div>

            {view.patterns.total > 0 && (
                <section className="panel patterns" aria-labelledby="pat-h">
                    <div className="observe">
                        <p className="win" id="pat-h">
                            Lo que se repite, en {retiros(view.patterns.total)}
                        </p>
                        <p className="big">
                            {view.patterns.observation ??
                                'Todavía no se repite nada. Con más retiros aparecen los patrones.'}
                        </p>
                        {view.patterns.question && (
                            <p className="q">
                                Para tu próxima revisión semanal:{' '}
                                <b>{view.patterns.question}</b>
                            </p>
                        )}
                    </div>
                    <div>
                        <p className="mini-h">Razones que se repiten</p>
                        <ul className="kv">
                            {topReasons.map((reason) => (
                                <li key={reason.keyword}>
                                    <span>{reason.keyword}</span>
                                    <span className="n">{reason.count}</span>
                                    <Tally count={reason.count} />
                                </li>
                            ))}
                            {view.patterns.other_reasons > 0 && (
                                <li>
                                    <span>
                                        {topReasons.length > 0
                                            ? 'otras razones'
                                            : 'razones distintas'}
                                    </span>
                                    <span className="n">
                                        {view.patterns.other_reasons}
                                    </span>
                                    <Tally
                                        count={view.patterns.other_reasons}
                                    />
                                </li>
                            )}
                        </ul>
                        <p className="fine">
                            Se cuentan palabras clave de tus razones, tal como
                            las escribiste.
                        </p>
                    </div>
                    <div>
                        <p className="mini-h">Edad mediana al retirar</p>
                        <ul className="kv num">
                            {view.patterns.median_ages.map((age) => (
                                <li key={age.kind}>
                                    <span>
                                        {age.label}{' '}
                                        <span className="muted sm">
                                            ({age.count})
                                        </span>
                                    </span>
                                    <span className="n">{days(age.days)}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="fine">
                            Días entre crear algo y retirarlo. No es una tasa de
                            nada: es cuánto tardaste en darte cuenta.
                        </p>
                    </div>
                </section>
            )}

            <div className="filters" role="group" aria-label="Filtros">
                <div className="seg" role="group" aria-label="Tipo">
                    {view.kinds.map((kind) => {
                        const value = kind.value === 'all' ? null : kind.value;

                        return (
                            <button
                                key={kind.value}
                                type="button"
                                aria-pressed={filters.kind === value}
                                onClick={() => filter({ kind: value })}
                            >
                                {kind.label}
                                <span className="c">{kind.count}</span>
                            </button>
                        );
                    })}
                </div>
                <label className="sr" htmlFor="fObj">
                    Objetivo
                </label>
                <select
                    id="fObj"
                    className="input"
                    value={filters.objective ?? ''}
                    onChange={(event) =>
                        filter({ objective: event.target.value || null })
                    }
                >
                    <option value="">Todos los objetivos</option>
                    {view.objectives.map((objective) => (
                        <option key={objective.key} value={objective.key}>
                            {objective.title}
                        </option>
                    ))}
                </select>
                <label className="sr" htmlFor="fMes">
                    Mes
                </label>
                <select
                    id="fMes"
                    className="input"
                    value={filters.month ?? ''}
                    onChange={(event) =>
                        filter({ month: event.target.value || null })
                    }
                >
                    <option value="">Todos los meses</option>
                    {view.month_options.map((month) => (
                        <option key={month.value} value={month.value}>
                            {month.label}
                        </option>
                    ))}
                </select>
                <span className="sp sm muted">Más recientes primero</span>
            </div>

            {view.months.length === 0 && (
                <p className="nothing-lost">
                    <ArchiveIcon size={18} />
                    {view.kinds[0].count === 0 &&
                    filters.objective === null &&
                    filters.month === null
                        ? 'Todavía no retiraste nada. Cuando algo deje de servirte, “Retirar” lo guarda acá con tu razón.'
                        : 'Nada retirado con estos filtros.'}
                </p>
            )}

            {view.months.map((month) => (
                <section key={month.value} aria-labelledby={`m-${month.value}`}>
                    <div className="month">
                        <h2 id={`m-${month.value}`}>{month.label}</h2>
                        <span>{retiros(month.count)}</span>
                    </div>
                    <table className="ret">
                        <caption className="sr">
                            Retirados en {month.label.toLowerCase()}
                        </caption>
                        <colgroup>
                            <col style={{ width: '29%' }} />
                            <col style={{ width: '20%' }} />
                            <col style={{ width: '20%' }} />
                            <col style={{ width: '12%' }} />
                            <col style={{ width: '19%' }} />
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Qué</th>
                                <th scope="col">Razón que escribiste</th>
                                <th scope="col">Qué pasó con su contenido</th>
                                <th scope="col">Edad al retirar</th>
                                <th scope="col">
                                    <span className="sr">Acción</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {month.entries.map((entry) => (
                                <Row
                                    key={entry.id}
                                    entry={entry}
                                    onRestore={() => setRestoring(entry)}
                                />
                            ))}
                        </tbody>
                    </table>
                </section>
            ))}

            {view.months.length > 0 && (
                <p className="nothing-lost">
                    <ArchiveIcon size={18} />
                    Esta es toda la lista. Al devolver algo al mapa, su retiro
                    queda en el historial del elemento.
                </p>
            )}

            <RestoreDialog
                entry={restoring}
                onClose={() => setRestoring(null)}
            />
        </div>
    );
}

function Tally({ count }: { count: number }) {
    return (
        <span className="sub">
            <span className="tally" aria-hidden="true">
                {Array.from({ length: Math.min(count, 10) }, (_, index) => (
                    <i key={index} />
                ))}
            </span>
        </span>
    );
}

function Row({ entry, onRestore }: { entry: Entry; onRestore: () => void }) {
    const blockedId = `blk-${entry.id}`;

    return (
        <tr>
            <td className="c-what">
                <div className="w">
                    <span className="kind-glyph" aria-hidden="true">
                        <KindGlyph kind={entry.kind} />
                    </span>
                    <div>
                        <span className="kind-name">{entry.kind_label}</span>
                        <b className="ttl">{entry.title}</b>
                        <span className="obj">{entry.objective_label}</span>
                    </div>
                </div>
            </td>
            <td className="c-why">
                <q>{entry.reason}</q>
            </td>
            <td className="c-dec">
                <DecisionIcon decision={entry.decision} />
                <span>{entry.decision_text}</span>
                {entry.includes > 0 && (
                    <span className="date">
                        incluye{' '}
                        {entry.includes === 1
                            ? '1 elemento'
                            : `${entry.includes} elementos`}
                    </span>
                )}
            </td>
            <td className="c-age num">
                <span className="lbl">Vivió</span> {days(entry.age_days)}
                <span className="date">
                    {entry.kind === 'task' || entry.kind === 'capture'
                        ? 'retirada'
                        : 'retirado'}{' '}
                    el {shortDate(entry.retired_on)}
                </span>
            </td>
            <td className="c-act">
                <button
                    className="btn btn-outline btn-sm"
                    type="button"
                    aria-disabled={!entry.restore.allowed || undefined}
                    aria-describedby={
                        entry.restore.allowed ? undefined : blockedId
                    }
                    onClick={() => entry.restore.allowed && onRestore()}
                >
                    <RestoreIcon />
                    Devolver al mapa
                </button>
                {!entry.restore.allowed && (
                    <p className="blk" id={blockedId}>
                        {entry.restore.blocked_reason}
                    </p>
                )}
            </td>
        </tr>
    );
}

function RestoreDialog({
    entry,
    onClose,
}: {
    entry: Entry | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        if (entry === null) {
            return;
        }

        router.post(
            retiredRestore(entry.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: (page) => {
                    const flash = (
                        page.flash as {
                            retirement?: { message: string; url: string };
                        }
                    ).retirement;

                    onClose();

                    if (flash) {
                        toast(flash.message, {
                            action: {
                                label: 'Ver en el mapa',
                                onClick: () => router.visit(flash.url),
                            },
                        });
                    }
                },
                onError: (errors) => {
                    onClose();
                    toast(
                        errors.retirement ??
                            'No se pudo devolver. Probá de nuevo.',
                    );
                },
            },
        );
    };

    return (
        <DialogPrimitive.Root
            open={entry !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 overlay-basalt duration-200 data-open:animate-in data-open:fade-in-0" />
                <DialogPrimitive.Content
                    className="mos mos-s22 fixed top-1/2 left-1/2 z-50 w-[min(460px,calc(100%-32px))] -translate-x-1/2 -translate-y-1/2 outline-none"
                    aria-describedby={undefined}
                >
                    {entry && (
                        <div className="pop">
                            <DialogPrimitive.Title asChild>
                                <h3>Devolver “{entry.title}”</h3>
                            </DialogPrimitive.Title>
                            <ul>
                                {entry.restore.summary.map((line) => (
                                    <li key={line}>{line}</li>
                                ))}
                            </ul>
                            <div className="row">
                                <button
                                    className="btn btn-primary"
                                    type="button"
                                    disabled={processing}
                                    onClick={confirm}
                                >
                                    <RestoreIcon />
                                    Devolver al mapa
                                </button>
                                <DialogPrimitive.Close
                                    className="btn btn-outline"
                                    type="button"
                                >
                                    Cancelar
                                </DialogPrimitive.Close>
                            </div>
                        </div>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

RetiredIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
