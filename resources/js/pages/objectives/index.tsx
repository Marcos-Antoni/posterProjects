import { Head, Link } from '@inertiajs/react';
import type { ReactElement } from 'react';

import { StateGlyph } from '@/components/marcos/state-glyph';
import AppLayout from '@/layouts/app-layout';
import {
    activeObjectivesSentence,
    formatLongDate,
    formatNumber,
    lowerFirst,
} from '@/lib/marcos';
import {
    create as objectiveCreate,
    show as objectiveShow,
} from '@/routes/objectives';
import { show as itemShow } from '@/routes/objectives/items';
import type { ControlPlan, TreeItem } from '@/types/models';

type ObjectiveRow = {
    key: string;
    title: string;
    identity_statement: string | null;
    /** 1–3: the objective's line color on the map. */
    line: number;
    control_plan: ControlPlan | null;
    progress: { done: number; total: number };
    last_done: string | null;
    next_milestone: { title: string; remaining: number } | null;
    next: TreeItem | null;
};

/**
 * Screen 3 (mockup visual/screens/03-objectives-index.html): the ACTIVE
 * objectives as a short list — each row its map line, its single metric and
 * its next concrete step. Closed and retired objectives are elsewhere.
 */
export default function ObjectivesIndex({
    objectives,
}: {
    objectives: ObjectiveRow[];
}) {
    return (
        <div className="mos-s03">
            <Head title="Objetivos" />

            <div className="head">
                <div>
                    <h1>Objetivos</h1>
                    <p>
                        {objectives.length === 0
                            ? 'Todavía no hay objetivos activos.'
                            : `${activeObjectivesSentence(objectives.length)} Cada uno es una línea del mapa.`}
                    </p>
                </div>
                <div className="acts">
                    <Link
                        className="btn btn-primary"
                        href={objectiveCreate()}
                        style={{ textDecoration: 'none' }}
                    >
                        Crear con el plan de 5 puntos
                    </Link>
                </div>
            </div>

            {objectives.length === 0 ? (
                <div className="empty">
                    <h3>Todavía no hay un mapa.</h3>
                    <p>
                        Escribí qué querés lograr con sus cinco puntos: el
                        resultado, la fecha, una sola métrica, qué puede salir
                        mal y qué hacés si pasa. Se activa cuando los cinco
                        están.
                    </p>
                    <Link
                        className="btn btn-outline"
                        href={objectiveCreate()}
                        style={{ textDecoration: 'none' }}
                    >
                        Crear a mano
                    </Link>
                </div>
            ) : (
                <ol className="olist">
                    {objectives.map((objective) => (
                        <ObjectiveRowView
                            key={objective.key}
                            objective={objective}
                        />
                    ))}
                </ol>
            )}

            <p className="foot">
                Los objetivos cerrados están en Revisiones; los retirados, en
                Retirados.
            </p>
        </div>
    );
}

function ObjectiveRowView({ objective }: { objective: ObjectiveRow }) {
    const plan = objective.control_plan;
    const metric = plan?.metric;
    const target = metric?.target ?? null;
    const current = metric?.current ?? 0;
    const showStrip =
        target !== null &&
        Number.isInteger(target) &&
        target >= 7 &&
        target <= 31;

    return (
        <li className="orow">
            <span
                className="rule"
                style={{ background: `var(--line-${objective.line})` }}
                aria-hidden="true"
            />
            <div>
                <h2>
                    <Link href={objectiveShow(objective.key)}>
                        {objective.title}
                    </Link>
                </h2>
                <span className="key">{objective.key}</span>
                {plan?.deadline && (
                    <span className="small muted">
                        Hasta el {formatLongDate(plan.deadline)}
                    </span>
                )}
                {(objective.identity_statement ?? plan?.outcome) && (
                    <p className="ident">
                        {objective.identity_statement ?? plan?.outcome}
                    </p>
                )}
            </div>
            <div className="metcol">
                <span className="lbl">Métrica</span>
                {metric?.name ? (
                    <p className="metric">
                        <b className="tnum">
                            {formatNumber(current)} de {formatNumber(target)}
                        </b>{' '}
                        {lowerFirst(metric.name)}
                    </p>
                ) : (
                    <p className="metric muted">Sin métrica todavía.</p>
                )}
                {showStrip && (
                    <div className="strip14" aria-hidden="true">
                        {Array.from({ length: target }, (_, index) => (
                            <span
                                key={index}
                                className={index < current ? 'd' : undefined}
                            />
                        ))}
                    </div>
                )}
                <p className="near">{nearText(objective)}</p>
            </div>
            <div className="nextcol">
                <span className="lbl">Siguiente</span>
                <NextStep objectiveKey={objective.key} next={objective.next} />
            </div>
        </li>
    );
}

function nearText(objective: ObjectiveRow): string {
    if (objective.next_milestone) {
        const { remaining, title } = objective.next_milestone;

        if (remaining === 0) {
            return `El próximo paso es el hito ${title}.`;
        }

        return `Faltan ${remaining} ${remaining === 1 ? 'estación' : 'estaciones'} para el hito ${title}.`;
    }

    if (objective.last_done) {
        return `Hecha: ${objective.last_done}.`;
    }

    return objective.progress.total === 0
        ? 'Todavía no tiene tareas.'
        : 'Todavía no empezó.';
}

function NextStep({
    objectiveKey,
    next,
}: {
    objectiveKey: string;
    next: TreeItem | null;
}) {
    if (next === null) {
        return (
            <>
                <div className="next">
                    <p>
                        <span className="t">Sin un siguiente paso</span>
                        <br />
                        <span className="s">
                            Agregá la primera tarea en uno de sus planes.
                        </span>
                    </p>
                </div>
                <div className="rowlinks">
                    <Link href={objectiveShow(objectiveKey)}>
                        Abrir el objetivo
                    </Link>
                </div>
            </>
        );
    }

    if (next.state === 'locked') {
        const blocker = next.waiting_on[0];

        return (
            <>
                <div className="next">
                    <StateGlyph state="locked" kind={next.kind} size="small" />
                    <p>
                        <span className="t">
                            Se abre al terminar{' '}
                            {blocker?.kind === 'milestone' ? 'el hito ' : ''}
                            {blocker?.title ?? 'su dependencia'}
                        </span>
                        <br />
                        <span className="s">
                            {blocker?.external
                                ? `Transbordo desde ${blocker.objective_title}`
                                : `Siguiente: ${next.title}`}
                        </span>
                    </p>
                </div>
                <div className="rowlinks">
                    <Link href={itemShow([objectiveKey, next.key])}>
                        Ver la tarea
                    </Link>
                </div>
            </>
        );
    }

    return (
        <>
            <div className="next">
                <StateGlyph state={next.state} kind={next.kind} size="small" />
                <p>
                    <span className="t">{next.title}</span>
                    <br />
                    <span className="s">
                        {next.state === 'active'
                            ? 'Activa ahora'
                            : `Disponible. 2 min: ${next.two_minute_version}`}
                    </span>
                </p>
            </div>
            {next.state === 'active' ? (
                <Link
                    className="btn btn-outline btn-row"
                    href={itemShow([objectiveKey, next.key])}
                    style={{ textDecoration: 'none', display: 'inline-flex' }}
                >
                    Seguir con la tarea
                </Link>
            ) : (
                <div className="rowlinks">
                    <Link href={itemShow([objectiveKey, next.key])}>
                        Ver la tarea
                    </Link>
                </div>
            )}
        </>
    );
}

ObjectivesIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
