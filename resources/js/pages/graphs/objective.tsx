import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import { Crumbs } from '@/components/marcos/crumbs';
import { PlusIcon } from '@/components/marcos/icons';
import {
    GoalMark,
    HowToRead,
    PANEL_MILESTONE_STATE,
    PANEL_STATE,
    TrackGlyph,
    TrackLegend,
    TrackMark,
    TrackNode,
    ZoomControls,
    clampZoom,
    glyphFor,
    markRadius,
    nodeLabel,
} from '@/components/marcos/unlock-track';
import AppLayout from '@/layouts/app-layout';
import {
    formatLongDate,
    formatMoment,
    formatNumber,
    lowerFirst,
} from '@/lib/marcos';
import { LANE_GAP, routePath, rowOffsets, stubPath } from '@/lib/unlock-track';
import type { TrackRoute } from '@/lib/unlock-track';
import { now } from '@/routes';
import { index as mapIndex, show as mapShow } from '@/routes/map';
import {
    index as objectivesIndex,
    show as objectiveShow,
} from '@/routes/objectives';
import {
    check as itemCheck,
    show as itemShow,
    uncheck as itemUncheck,
} from '@/routes/objectives/items';
import prerequisites from '@/routes/objectives/items/prerequisites';
import unlocks from '@/routes/objectives/items/unlocks';
import { index as retiredIndex } from '@/routes/retired';
import type {
    GraphNode,
    GraphRetired,
    GraphStub,
    ObjectiveGraph,
    TrackLayout,
} from '@/types/graph';
import type { ItemKind, ItemState } from '@/types/models';

type Props = {
    graph: ObjectiveGraph;
    layout: TrackLayout;
    goals: TrackLayout;
    routes: TrackRoute[];
    /** Drawn-graph edges left out by the lane cap (still in the panel). */
    hidden: { from: string; to: string }[];
};

/** One end of an edge as the side panel lists it. */
type Neighbour = {
    key: string;
    title: string;
    state: ItemState;
    kind: ItemKind;
    objectiveKey: string | null;
};

const GOAL = 'objetivo';
const RETIRED_PREFIX = 'retirado:';

/**
 * Screen 8 (mockup visual/screens/08-objective-graph.html): one objective's
 * unlock graph as the minimal vertical track — done on top, the objective at
 * the bottom, parallel branches that split and rejoin, only the Now task and
 * the objective labelled, every other station named on hover, focus or
 * selection. Selecting a station opens the side panel, where dependencies
 * are added or removed (a cycle comes back as the Spanish path message) and
 * a task can be checked, which plays the unlock animation (a static
 * highlight with reduced motion). Cross-objective neighbours are dashed
 * stubs left of the track, linked to their own line.
 */
export default function ObjectiveGraphPage({
    graph,
    layout,
    goals,
    routes,
    hidden,
}: Props) {
    const { url } = usePage();
    const [selected, setSelected] = useState<string | null>(() =>
        initialSelection(url),
    );
    const [plan, setPlan] = useState<number | 'all'>('all');
    const [showRetired, setShowRetired] = useState(true);
    const [zoom, setZoom] = useState(1);
    const [help, setHelp] = useState(false);
    const [celebrating, setCelebrating] = useState<string[]>([]);
    const scroller = useRef<HTMLDivElement>(null);
    const objectiveKey = graph.objective.key;
    const writable = graph.objective.is_writable;

    const geometry = useMemo(
        () => buildGeometry(graph, layout, goals, routes, hidden),
        [graph, layout, goals, routes, hidden],
    );

    const select = (id: string | null) => {
        setSelected(id);
        setCelebrating([]);
    };

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                target &&
                ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName)
            ) {
                return;
            }

            if (event.key === 'Escape') {
                setSelected(null);
            } else if (event.key === '+') {
                setZoom((value) => clampZoom(value + 0.2));
            } else if (event.key === '-') {
                setZoom((value) => clampZoom(value - 0.2));
            } else if (event.key === '0') {
                setZoom(1);
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    // A narrow canvas starts with the whole width of the track in view.
    useEffect(() => {
        const width = scroller.current?.clientWidth ?? 0;

        if (width > 0 && width < geometry.width) {
            setZoom(clampZoom(width / geometry.width));
        }
        // Only on the first render: afterwards the owner drives the zoom.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const fit = () => {
        const height = scroller.current?.clientHeight ?? geometry.height;

        setZoom(clampZoom(height / geometry.height));
        scroller.current?.scrollTo(0, 0);
    };

    const dimmed = (planId: number | undefined) =>
        plan !== 'all' && planId !== undefined && planId !== plan;

    const metric = graph.goal.metric;

    return (
        <div className="mos-s08">
            <Head title="Mapa del objetivo" />
            <div className={selected ? 'gpage has-sel' : 'gpage'}>
                <main className="gmain">
                    <div className="ghead">
                        <div>
                            <Crumbs
                                items={[
                                    {
                                        label: 'Objetivos',
                                        href: objectivesIndex().url,
                                    },
                                    {
                                        label: graph.objective.title,
                                        href: objectiveShow(objectiveKey).url,
                                    },
                                    { label: 'Mapa' },
                                ]}
                            />
                            <div className="t">
                                <h1 className="obj-title sm">
                                    {graph.objective.title}
                                </h1>
                                <span className="key">{objectiveKey}</span>
                            </div>
                            {metric?.name && (
                                <p className="metricline">
                                    Métrica:{' '}
                                    <b className="tnum">
                                        {formatNumber(metric.current ?? 0)} de{' '}
                                        {formatNumber(metric.target)}
                                    </b>{' '}
                                    {lowerFirst(metric.name)}
                                </p>
                            )}
                        </div>
                        <div
                            className="seg"
                            role="navigation"
                            aria-label="Vista del mapa"
                        >
                            <Link
                                href={mapShow(objectiveKey)}
                                aria-current="page"
                            >
                                Esta línea
                            </Link>
                            <Link href={mapIndex()}>Global</Link>
                        </div>
                    </div>

                    {!writable && (
                        <p className="closed-note">
                            Este objetivo está cerrado: su mapa se lee, no se
                            cambia.
                        </p>
                    )}

                    <div className="gtools">
                        <div
                            className="grp"
                            role="group"
                            aria-label="Filtrar por plan"
                        >
                            {graph.plans.length > 0 && (
                                <>
                                    <span className="lab">Plan</span>
                                    <div className="seg">
                                        <button
                                            type="button"
                                            aria-pressed={plan === 'all'}
                                            onClick={() => setPlan('all')}
                                        >
                                            Todos
                                        </button>
                                        {graph.plans.map((option) => (
                                            <button
                                                key={option.id}
                                                type="button"
                                                aria-pressed={
                                                    plan === option.id
                                                }
                                                onClick={() =>
                                                    setPlan(option.id)
                                                }
                                            >
                                                {option.title}
                                            </button>
                                        ))}
                                    </div>
                                </>
                            )}
                            {graph.retired.length > 0 && (
                                <button
                                    className="switch"
                                    type="button"
                                    aria-pressed={showRetired}
                                    onClick={() => setShowRetired(!showRetired)}
                                >
                                    <i aria-hidden="true" />
                                    Mostrar retirados
                                </button>
                            )}
                        </div>
                        {graph.next_milestone && (
                            <p className="near">
                                {graph.next_milestone.remaining > 0 ? (
                                    <>
                                        Faltan{' '}
                                        <b>
                                            {graph.next_milestone.remaining}{' '}
                                            {graph.next_milestone.remaining ===
                                            1
                                                ? 'estación'
                                                : 'estaciones'}
                                        </b>{' '}
                                        para el mojón{' '}
                                        {graph.next_milestone.title}.
                                    </>
                                ) : (
                                    <>
                                        El próximo paso es el mojón{' '}
                                        <b>{graph.next_milestone.title}</b>.
                                    </>
                                )}
                            </p>
                        )}
                    </div>

                    <div className="canvas">
                        <div className="scroller" ref={scroller}>
                            {graph.nodes.length === 0 ? (
                                <div className="empty">
                                    <p>
                                        Este objetivo todavía no tiene tareas ni
                                        hitos. Agregalos desde sus planes y
                                        aparecen acá, en su vía.
                                    </p>
                                    <Link
                                        className="btn btn-outline"
                                        href={objectiveShow(objectiveKey)}
                                    >
                                        Abrir el objetivo
                                    </Link>
                                </div>
                            ) : (
                                <svg
                                    className={showRetired ? 'tr' : 'tr no-ret'}
                                    viewBox={`0 0 ${geometry.width} ${geometry.height}`}
                                    style={{
                                        width: geometry.width * zoom,
                                        height: geometry.height * zoom,
                                    }}
                                    role="group"
                                    aria-labelledby="map-t"
                                >
                                    <title id="map-t">
                                        Línea del objetivo{' '}
                                        {graph.objective.title}
                                    </title>
                                    <desc>{describe(graph)}</desc>
                                    {geometry.retired.map(
                                        ({ retired, x, y }) => (
                                            <TrackNode
                                                key={retired.key}
                                                id={
                                                    RETIRED_PREFIX + retired.key
                                                }
                                                x={x}
                                                y={y}
                                                r={6}
                                                label={`Retirado: ${retired.title}${retired.reason ? `. Motivo: ${retired.reason}` : ''}`}
                                                tip={`Retirado: ${retired.title}`}
                                                selected={
                                                    selected ===
                                                    RETIRED_PREFIX + retired.key
                                                }
                                                className={
                                                    dimmed(retired.plan_id)
                                                        ? 'ret dim'
                                                        : 'ret'
                                                }
                                                dataPlan={String(
                                                    retired.plan_id,
                                                )}
                                                onSelect={select}
                                            >
                                                <path
                                                    d={`M${x - 6} ${y} H${x + 6}`}
                                                    className="e-ret"
                                                />
                                            </TrackNode>
                                        ),
                                    )}
                                    {geometry.edges.map((edge) => (
                                        <path
                                            key={edge.id}
                                            d={edge.d}
                                            className={[
                                                'e',
                                                edge.className,
                                                dimmed(edge.planId)
                                                    ? 'dim'
                                                    : '',
                                                celebrating.includes(edge.to)
                                                    ? 'trace'
                                                    : '',
                                            ]
                                                .filter(Boolean)
                                                .join(' ')}
                                            pathLength={
                                                celebrating.includes(edge.to)
                                                    ? 1
                                                    : undefined
                                            }
                                            data-plan={edge.planId}
                                        />
                                    ))}
                                    {geometry.stubs.map((mark) => (
                                        <path
                                            key={`line:${mark.id}@${mark.anchor}`}
                                            d={mark.d}
                                            className={`e ${edgeClass(mark.state)} e-stub`}
                                        />
                                    ))}
                                    {geometry.more.map((mark) => (
                                        <path
                                            key={`line:${mark.id}`}
                                            d={mark.d}
                                            className="e e-lock e-stub"
                                        />
                                    ))}
                                    {geometry.stubs.map((mark) => (
                                        <TrackNode
                                            key={`${mark.id}@${mark.anchor}`}
                                            id={mark.id}
                                            x={mark.x}
                                            y={mark.y}
                                            r={7}
                                            label={`De otro objetivo, ${mark.stub.objective_title}: ${nodeLabel(mark.stub.title, mark.stub.state, mark.stub.kind)}`}
                                            tip={`${mark.stub.objective_key} · ${mark.stub.title}`}
                                            selected={selected === mark.id}
                                            className="stub"
                                            onSelect={select}
                                        >
                                            <circle
                                                cx={mark.x}
                                                cy={mark.y}
                                                r={7}
                                                className="n-stub"
                                            />
                                        </TrackNode>
                                    ))}
                                    {geometry.more.map((mark) => (
                                        <TrackNode
                                            key={mark.id}
                                            id={`mas:${mark.id}`}
                                            selects={mark.anchor}
                                            x={mark.x}
                                            y={mark.y}
                                            r={9}
                                            label={`${mark.count} conexiones más sin dibujar; se listan en el panel`}
                                            tip={`${mark.count} más: ver en el panel`}
                                            selected={false}
                                            className="more"
                                            onSelect={select}
                                        >
                                            <circle
                                                cx={mark.x}
                                                cy={mark.y}
                                                r={9}
                                                className="n-stub"
                                            />
                                            <text
                                                x={mark.x}
                                                y={mark.y + 4}
                                                className="lb-more"
                                                textAnchor="middle"
                                            >
                                                +{mark.count}
                                            </text>
                                        </TrackNode>
                                    ))}
                                    {geometry.nodes.map(({ node, x, y }) => {
                                        const r = markRadius(
                                            node.state,
                                            node.kind,
                                        );
                                        const isNow = node.state === 'active';
                                        const glowing = celebrating.includes(
                                            node.key,
                                        );

                                        return (
                                            <TrackNode
                                                key={node.key}
                                                id={node.key}
                                                x={x}
                                                y={y}
                                                r={r}
                                                label={nodeLabel(
                                                    node.title,
                                                    node.state,
                                                    node.kind,
                                                )}
                                                tip={isNow ? null : node.title}
                                                selected={selected === node.key}
                                                className={[
                                                    dimmed(node.plan_id)
                                                        ? 'dim'
                                                        : '',
                                                    glowing ? 'unlk' : '',
                                                ]
                                                    .filter(Boolean)
                                                    .join(' ')}
                                                dataPlan={String(node.plan_id)}
                                                onSelect={select}
                                            >
                                                {glowing && (
                                                    <circle
                                                        cx={x}
                                                        cy={y}
                                                        r={r + 6}
                                                        className="hl"
                                                    />
                                                )}
                                                <TrackMark
                                                    x={x}
                                                    y={y}
                                                    state={node.state}
                                                    kind={node.kind}
                                                />
                                                {isNow && (
                                                    <>
                                                        <text
                                                            x={x + r + 14}
                                                            y={y - 2}
                                                            className="lb-act"
                                                        >
                                                            {node.title}
                                                        </text>
                                                        <text
                                                            x={x + r + 14}
                                                            y={y + 15}
                                                            className="lb-sub"
                                                        >
                                                            ahora
                                                        </text>
                                                    </>
                                                )}
                                            </TrackNode>
                                        );
                                    })}
                                    <TrackNode
                                        id={GOAL}
                                        x={geometry.goal.x}
                                        y={geometry.goal.y}
                                        r={22.8}
                                        label={`Objetivo ${geometry.goal.label}`}
                                        tip={null}
                                        selected={selected === GOAL}
                                        onSelect={select}
                                    >
                                        <GoalMark
                                            x={geometry.goal.x}
                                            y={geometry.goal.y}
                                            r={22.8}
                                            done={geometry.goal.done}
                                        />
                                        <text
                                            x={geometry.goal.x + 36.8}
                                            y={geometry.goal.y - 2}
                                            className="lb-goal"
                                        >
                                            {geometry.goal.label}
                                        </text>
                                        {geometry.goal.sub && (
                                            <text
                                                x={geometry.goal.x + 36.8}
                                                y={geometry.goal.y + 15}
                                                className="lb-sub"
                                            >
                                                {geometry.goal.sub}
                                            </text>
                                        )}
                                    </TrackNode>
                                </svg>
                            )}
                        </div>
                        <HowToRead
                            open={help}
                            onToggle={() => setHelp(!help)}
                        />
                        {graph.nodes.length > 0 && (
                            <ZoomControls
                                zoom={zoom}
                                onZoom={setZoom}
                                onFit={fit}
                            />
                        )}
                    </div>
                    <TrackLegend />
                </main>

                <aside
                    className="gside"
                    aria-label="Estación seleccionada"
                    aria-live="polite"
                >
                    {selected && (
                        <Panel
                            key={selected}
                            graph={graph}
                            selected={selected}
                            onClose={() => setSelected(null)}
                            onCelebrate={setCelebrating}
                        />
                    )}
                </aside>
            </div>
        </div>
    );
}

ObjectiveGraphPage.layout = (page: ReactElement) => (
    <AppLayout bleed>{page}</AppLayout>
);

function initialSelection(url: string): string | null {
    const query = url.split('?')[1];

    return query ? new URLSearchParams(query).get('sel') : null;
}

/** "Revisión 11-oct" when the 5-point plan has a deadline. */
function goalLabel(graph: ObjectiveGraph): {
    label: string;
    sub: string | null;
} {
    const deadline = graph.goal.deadline;

    if (!deadline) {
        return { label: graph.goal.title, sub: null };
    }

    const [year, month, day] = deadline.split('-').map(Number);
    const monthName = new Date(Date.UTC(year, month - 1, day))
        .toLocaleDateString('es', { month: 'short', timeZone: 'UTC' })
        .replace('.', '');

    return { label: `Revisión ${day}-${monthName}`, sub: graph.goal.title };
}

/** Vertical distance of a stub mark from its station's row. */
const STUB_RISE = 24;

/** Lead/tail band next to a row with stub ticks (mark + margin inside). */
const STUB_BAND = 38;

type StubMark = {
    id: string;
    stub: GraphStub;
    anchor: string;
    x: number;
    y: number;
    d: string;
    state: ItemState;
};

type MoreMark = {
    id: string;
    anchor: string;
    count: number;
    x: number;
    y: number;
    d: string;
};

function buildGeometry(
    graph: ObjectiveGraph,
    layout: TrackLayout,
    goals: TrackLayout,
    routes: TrackRoute[],
    hidden: { from: string; to: string }[],
) {
    const goalSpot = goals[graph.objective.key] ?? { row: 0, lane: 0 };
    const nodeByKey = new Map(graph.nodes.map((node) => [node.key, node]));
    const stubByKey = new Map(graph.stubs.map((stub) => [stub.key, stub]));

    // Stubs per station and side (above: prerequisites; below: dependents).
    const sides = new Map<string, { pre: GraphStub[]; dep: GraphStub[] }>();

    for (const edge of graph.edges) {
        const [stationKey, stubKey, side] = nodeByKey.has(edge.to)
            ? [edge.to, edge.from, 'pre' as const]
            : [edge.from, edge.to, 'dep' as const];
        const stub = stubByKey.get(stubKey);

        if (!stub || !nodeByKey.has(stationKey) || !layout[stationKey]) {
            continue;
        }

        const entry = sides.get(stationKey) ?? { pre: [], dep: [] };
        entry[side].push(stub);
        sides.set(stationKey, entry);
    }

    // Far unlocks the lane cap left out of the drawing: counted in the
    // station's "+N" below it.
    const far = new Map<string, number>();

    for (const edge of hidden) {
        if (nodeByKey.has(edge.from) && layout[edge.from]) {
            far.set(edge.from, (far.get(edge.from) ?? 0) + 1);

            if (!sides.has(edge.from)) {
                sides.set(edge.from, { pre: [], dep: [] });
            }
        }
    }

    const countOf = (station: string, side: 'pre' | 'dep') =>
        sides.get(station)![side].length +
        (side === 'dep' ? (far.get(station) ?? 0) : 0);

    // Stub marks sit in the free space between two lanes, just above
    // (prerequisites) or below (dependents) their station: the gap right of
    // the station first, then the one on its left if the neighbour left it
    // free. One gap per mark, so ticks never meet.
    type Slot = { station: string; side: 'pre' | 'dep'; gap: number };
    const slots: Slot[] = [];
    const taken = new Set<string>();
    const stations = [...sides.keys()].sort(
        (a, b) =>
            layout[a].row - layout[b].row || layout[a].lane - layout[b].lane,
    );

    for (const pass of [0, 1]) {
        for (const station of stations) {
            const { row, lane } = layout[station];

            for (const side of ['pre', 'dep'] as const) {
                const count = countOf(station, side);
                const gap = pass === 0 ? lane : lane - 1;
                const id = `${side}:${row}:${gap}`;

                if (count > pass && !taken.has(id)) {
                    taken.add(id);
                    slots.push({ station, side, gap });
                }
            }
        }
    }

    const lead = new Map<number, number>();
    const tail = new Map<number, number>();
    const minimum = new Map<number, number>();

    for (const slot of slots) {
        const row = layout[slot.station].row;

        if (slot.side === 'dep') {
            lead.set(row, STUB_BAND);
        } else if (row > 0) {
            tail.set(row - 1, STUB_BAND);
        }
    }

    if (goalSpot.row > 0) {
        minimum.set(goalSpot.row - 1, 70);
    }

    const retiredRoom = graph.retired.length > 0 ? 32 : 0;
    const x0 = 60 + LANE_GAP / 2 + retiredRoom;
    const top =
        44 +
        (slots.some(
            (slot) => slot.side === 'pre' && layout[slot.station].row === 0,
        )
            ? STUB_BAND
            : 0);
    const rows = rowOffsets(goalSpot.row, routes, top, lead, tail, minimum);
    const rowAt = (row: number) => rows.y.get(row) ?? top;
    const at = (spot: { row: number; lane: number }) => ({
        x: x0 + spot.lane * LANE_GAP,
        y: rowAt(spot.row),
    });
    const planOf = new Map(graph.nodes.map((node) => [node.key, node.plan_id]));
    const goalDone =
        graph.nodes.length > 0 &&
        graph.nodes.every((node) => node.state === 'done');
    const goal = { ...at(goalSpot), done: goalDone, ...goalLabel(graph) };

    const edges = routes.map((route) => {
        const toGoal = route.to.startsWith('#goal:');
        const dependent = nodeByKey.get(route.to);

        return {
            id: `${route.from}>${route.to}`,
            d: routePath(
                route.points.map((point) => ({
                    ...at(point),
                    group: point.group,
                    row: point.row,
                })),
                (row) => rows.lead.get(row) ?? 12,
            ),
            className: toGoal
                ? goalDone
                    ? 'e-done'
                    : 'e-lock'
                : edgeClass(dependent?.state ?? 'locked'),
            to: toGoal ? GOAL : route.to,
            planId: planOf.get(route.to) ?? planOf.get(route.from),
        };
    });

    // Fill the slots: every stub while they fit, else all but the last
    // slot and a "+N" mark there (the panel lists them all).
    const stubs: StubMark[] = [];
    const more: MoreMark[] = [];

    for (const station of stations) {
        for (const side of ['pre', 'dep'] as const) {
            const mine = slots.filter(
                (slot) => slot.station === station && slot.side === side,
            );
            const list = sides.get(station)![side];
            const farCount = side === 'dep' ? (far.get(station) ?? 0) : 0;
            const badge = farCount > 0 || list.length > mine.length;
            const shown = badge
                ? Math.min(list.length, mine.length - 1)
                : list.length;
            const origin = at(layout[station]);

            mine.forEach((slot, index) => {
                const mark = {
                    x: x0 + slot.gap * LANE_GAP + LANE_GAP / 2,
                    y:
                        side === 'pre'
                            ? origin.y - STUB_RISE
                            : origin.y + STUB_RISE,
                };
                const d = stubPath(origin, mark);

                if (index < shown) {
                    const stub = list[index];

                    stubs.push({
                        id: stub.key,
                        stub,
                        anchor: station,
                        ...mark,
                        d,
                        state:
                            side === 'pre'
                                ? (nodeByKey.get(station)?.state ?? 'locked')
                                : stub.state,
                    });
                } else {
                    more.push({
                        id: `${station}:${side}`,
                        anchor: station,
                        count: list.length - shown + farCount,
                        ...mark,
                        d,
                    });
                }
            });
        }
    }

    // Retired marks: a column left of the leftmost stub gap, never on a line.
    const retiredX = x0 - LANE_GAP / 2 - 30;
    const firstRowOfPlan = new Map<number, number>();
    graph.nodes.forEach((node) => {
        const row = layout[node.key]?.row ?? 0;
        firstRowOfPlan.set(
            node.plan_id,
            Math.min(firstRowOfPlan.get(node.plan_id) ?? row, row),
        );
    });
    let lowest = -Infinity;
    const retired = graph.retired.map((item: GraphRetired) => {
        const wanted = rowAt(firstRowOfPlan.get(item.plan_id) ?? 0) + 28;
        const y = Math.max(wanted, lowest + 18);
        lowest = y;

        return { retired: item, x: retiredX, y };
    });

    const maxLane = Math.max(
        0,
        ...routes.flatMap((route) => route.points.map((point) => point.lane)),
        ...slots.map((slot) => slot.gap + 1),
    );

    return {
        width: x0 + maxLane * LANE_GAP + 280,
        height: Math.max(goal.y, lowest) + 50,
        nodes: graph.nodes.flatMap((node) => {
            const spot = layout[node.key];

            return spot ? [{ node, ...at(spot) }] : [];
        }),
        stubs,
        more,
        edges,
        retired,
        goal,
    };
}

/** Segment weight follows what it leads to: walked, open, or still to come. */
function edgeClass(dependentState: ItemState): string {
    if (dependentState === 'done' || dependentState === 'active') {
        return 'e-done';
    }

    return dependentState === 'available' ? 'e-open' : 'e-lock';
}

/** The whole route in words for screen readers (`<desc>`). */
function describe(graph: ObjectiveGraph): string {
    const parts = graph.nodes.map((node) =>
        nodeLabel(node.title, node.state, node.kind),
    );
    const retired = graph.retired.map((item) => item.title);

    return [
        `${graph.nodes.length} estaciones en una vía vertical: ${parts.join('; ')}.`,
        `Objetivo: ${graph.goal.title}.`,
        retired.length > 0
            ? `Retirados a un costado: ${retired.join(', ')}.`
            : '',
    ]
        .filter(Boolean)
        .join(' ');
}

function Panel({
    graph,
    selected,
    onClose,
    onCelebrate,
}: {
    graph: ObjectiveGraph;
    selected: string;
    onClose: () => void;
    onCelebrate: (keys: string[]) => void;
}) {
    const objectiveKey = graph.objective.key;
    const node = graph.nodes.find((candidate) => candidate.key === selected);
    const stub = graph.stubs.find((candidate) => candidate.key === selected);
    const retired = graph.retired.find(
        (candidate) => RETIRED_PREFIX + candidate.key === selected,
    );

    if (selected === GOAL) {
        return <GoalPanel graph={graph} onClose={onClose} />;
    }

    if (retired) {
        const planTitle = graph.plans.find(
            (plan) => plan.id === retired.plan_id,
        )?.title;

        return (
            <>
                <Kicker
                    glyph="retired"
                    state="Retirada"
                    itemKey={retired.key}
                    onClose={onClose}
                />
                <h2>{retired.title}</h2>
                {planTitle && (
                    <p className="plan">Retirado del plan {planTitle}</p>
                )}
                <p className="note">
                    {retired.reason
                        ? `Razón: ${retired.reason}.`
                        : 'Retirado: no ocupa la vía ni bloquea a nadie.'}
                </p>
                {/* integration (P5 x P6): it goes back to the map from Retirados */}
                <div className="acts">
                    <Link
                        className="btn btn-outline"
                        href={retiredIndex({
                            query: { objective: objectiveKey },
                        })}
                    >
                        Ver en Retirados
                    </Link>
                </div>
            </>
        );
    }

    if (stub) {
        return (
            <>
                <Kicker
                    glyph={glyphFor(stub.state)}
                    state={`${stub.kind === 'milestone' ? PANEL_MILESTONE_STATE[stub.state] : PANEL_STATE[stub.state]}, en otro objetivo`}
                    itemKey={stub.key}
                    onClose={onClose}
                />
                <h2>{stub.title}</h2>
                <p className="plan">Objetivo: {stub.objective_title}</p>
                <p className="note">
                    Es de otra línea: se dibuja acá porque se conecta con esta.
                </p>
                <div className="acts">
                    <Link
                        className="btn btn-outline"
                        href={mapShow(stub.objective_key, {
                            query: { sel: stub.key },
                        })}
                    >
                        Ver en su línea
                    </Link>
                    <Link
                        className="btn btn-outline"
                        href={itemShow([stub.objective_key, stub.key])}
                    >
                        Ver detalle
                    </Link>
                </div>
            </>
        );
    }

    if (!node) {
        return (
            <>
                <Kicker
                    glyph="locked"
                    state=""
                    itemKey={selected}
                    onClose={onClose}
                />
                <p className="note">Esa estación ya no está en este mapa.</p>
            </>
        );
    }

    return (
        <ItemPanel
            graph={graph}
            node={node}
            objectiveKey={objectiveKey}
            onClose={onClose}
            onCelebrate={onCelebrate}
        />
    );
}

function Kicker({
    glyph,
    state,
    itemKey,
    onClose,
}: {
    glyph: Parameters<typeof TrackGlyph>[0]['name'];
    state: string;
    itemKey: string;
    onClose: () => void;
}) {
    return (
        <div className="kicker">
            <span className="st">
                <TrackGlyph name={glyph} />
                {state}
            </span>
            <span className="kk">
                <span className="key">{itemKey}</span>
                <button
                    className="x"
                    type="button"
                    aria-label="Cerrar panel"
                    onClick={onClose}
                >
                    ×
                </button>
            </span>
        </div>
    );
}

function neighbours(
    graph: ObjectiveGraph,
    key: string,
    side: 'prerequisites' | 'dependents',
): Neighbour[] {
    const keys = graph.edges
        .filter((edge) =>
            side === 'prerequisites' ? edge.to === key : edge.from === key,
        )
        .map((edge) => (side === 'prerequisites' ? edge.from : edge.to));

    return keys.flatMap((other): Neighbour[] => {
        const node = graph.nodes.find((candidate) => candidate.key === other);

        if (node) {
            return [
                {
                    key: node.key,
                    title: node.title,
                    state: node.state,
                    kind: node.kind,
                    objectiveKey: null,
                },
            ];
        }

        const stub = graph.stubs.find((candidate) => candidate.key === other);

        return stub
            ? [
                  {
                      key: stub.key,
                      title: stub.title,
                      state: stub.state,
                      kind: stub.kind,
                      objectiveKey: stub.objective_key,
                  },
              ]
            : [];
    });
}

function titles(list: Neighbour[]): string {
    const names = list.map((item) => item.title);

    return names.length <= 1
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} y ${names[names.length - 1]}`;
}

function ItemPanel({
    graph,
    node,
    objectiveKey,
    onClose,
    onCelebrate,
}: {
    graph: ObjectiveGraph;
    node: GraphNode;
    objectiveKey: string;
    onClose: () => void;
    onCelebrate: (keys: string[]) => void;
}) {
    const writable = graph.objective.is_writable;
    const before = neighbours(graph, node.key, 'prerequisites');
    const after = neighbours(graph, node.key, 'dependents');
    const planTitle = graph.plans.find(
        (plan) => plan.id === node.plan_id,
    )?.title;
    const milestone = node.kind === 'milestone';
    const [busy, setBusy] = useState(false);

    const check = () => {
        setBusy(true);
        router.post(
            itemCheck([objectiveKey, node.key]).url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: (page) => {
                    const unlocked = (page.props.flash?.unlocked ?? []).map(
                        (item) => item.key,
                    );
                    onCelebrate([node.key, ...unlocked]);
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const uncheck = () => {
        setBusy(true);
        router.post(
            itemUncheck([objectiveKey, node.key]).url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                onFinish: () => setBusy(false),
            },
        );
    };

    const remove = (other: Neighbour, side: 'prerequisite' | 'unlock') => {
        const target =
            side === 'prerequisite'
                ? prerequisites.destroy([objectiveKey, node.key, other.key])
                : unlocks.destroy([objectiveKey, node.key, other.key]);

        router.delete(target.url, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <>
            <Kicker
                glyph={glyphFor(node.state)}
                state={
                    milestone
                        ? PANEL_MILESTONE_STATE[node.state]
                        : PANEL_STATE[node.state]
                }
                itemKey={node.key}
                onClose={onClose}
            />
            <h2>{node.title}</h2>
            {planTitle && <p className="plan">Plan: {planTitle}</p>}
            {node.state !== 'done' && (
                <div
                    className="chip2"
                    style={
                        node.state === 'active' ? undefined : { opacity: 0.75 }
                    }
                >
                    <span className="mini" aria-hidden="true" />2 min:{' '}
                    {node.two_minute_version}
                </div>
            )}
            <NeighbourList
                heading={
                    node.state === 'locked' ? 'Se abre al terminar' : 'Viene de'
                }
                items={before}
                onRemove={
                    writable
                        ? (other) => remove(other, 'prerequisite')
                        : undefined
                }
            />
            <NeighbourList
                heading="Esto abre"
                items={after}
                onRemove={
                    writable ? (other) => remove(other, 'unlock') : undefined
                }
            />
            <p className="note">{noteFor(node, before, after)}</p>
            {writable && (
                <AddDependency objectiveKey={objectiveKey} itemKey={node.key} />
            )}
            <div className="acts">
                {node.state === 'active' && (
                    /* integration (P3 x P5, mockup 08): the active station's primary action */
                    <Link className="btn btn-primary" href={now()}>
                        Seguir en Ahora
                    </Link>
                )}
                {writable &&
                    !milestone &&
                    (node.state === 'active' || node.state === 'available') && (
                        <button
                            className="btn btn-outline"
                            type="button"
                            disabled={busy}
                            onClick={check}
                        >
                            Marcar hecha
                        </button>
                    )}
                {writable && node.state === 'done' && (
                    <button
                        className="btn btn-outline"
                        type="button"
                        disabled={busy}
                        onClick={uncheck}
                    >
                        Desmarcar (sin penalidad)
                    </button>
                )}
                <Link
                    className="btn btn-outline"
                    href={itemShow([objectiveKey, node.key])}
                >
                    Ver detalle
                </Link>
            </div>
        </>
    );
}

function noteFor(
    node: GraphNode,
    before: Neighbour[],
    after: Neighbour[],
): string {
    const external = after.filter((item) => item.objectiveKey !== null);
    const opens =
        after.length > 0 ? ` Al terminarla se abre ${titles(after)}.` : '';

    if (node.state === 'done') {
        const when = node.completed_at
            ? ` ${formatMoment(node.completed_at)}`
            : '';

        return `Hecha${when}.${after.length > 0 ? ` Abrió ${titles(after)}.` : ''}`;
    }

    if (node.state === 'locked') {
        const open = before.filter((item) => item.state !== 'done');

        return `Cuando termines ${titles(open.length > 0 ? open : before)}, esta estación se abre.`;
    }

    if (node.kind === 'milestone') {
        return `Es un mojón: al marcarlo te pide una línea de evidencia, desde su detalle.${opens}`;
    }

    if (external.length > 0) {
        return `Al terminarla se abre otro objetivo: ${external.map((item) => item.objectiveKey).join(', ')}.`;
    }

    return node.state === 'active'
        ? `Es la tarea de ahora.${opens}`
        : `Disponible: podés empezarla cuando quieras.${opens}`;
}

function NeighbourList({
    heading,
    items,
    onRemove,
}: {
    heading: string;
    items: Neighbour[];
    onRemove?: (item: Neighbour) => void;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div>
            <h3>{heading}</h3>
            <ul>
                {items.map((item) => (
                    <li key={item.key}>
                        <TrackGlyph name={glyphFor(item.state)} />
                        <span className="nm">
                            {item.title}
                            {item.state === 'active' && ', activa ahora'}
                            {item.objectiveKey && (
                                <span className="ext">
                                    {' '}
                                    · {item.objectiveKey}
                                </span>
                            )}
                        </span>
                        {onRemove && (
                            <button
                                className="linkbtn quiet"
                                type="button"
                                aria-label={`Quitar la dependencia con ${item.title}`}
                                onClick={() => onRemove(item)}
                            >
                                Quitar
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function AddDependency({
    objectiveKey,
    itemKey,
}: {
    objectiveKey: string;
    itemKey: string;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        direction: 'prerequisite' as 'prerequisite' | 'unlock',
        key: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const problem = errors.prerequisite ?? errors.key;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ key: data.key }));
        const target =
            form.data.direction === 'prerequisite'
                ? prerequisites.store([objectiveKey, itemKey])
                : unlocks.store([objectiveKey, itemKey]);
        form.post(target.url, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    if (!open) {
        return (
            <button
                className="addl"
                type="button"
                onClick={() => setOpen(true)}
            >
                <PlusIcon />
                Agregar una dependencia
            </button>
        );
    }

    return (
        <form className="depform" onSubmit={submit}>
            <label className="fld">
                <span className="l">Relación</span>
                <select
                    className="in"
                    value={form.data.direction}
                    onChange={(event) =>
                        form.setData(
                            'direction',
                            event.target.value as 'prerequisite' | 'unlock',
                        )
                    }
                >
                    <option value="prerequisite">
                        Esta estación depende de
                    </option>
                    <option value="unlock">
                        Al terminar esta estación se abre
                    </option>
                </select>
            </label>
            <label className="fld">
                <span className="l">Clave de la otra tarea o hito</span>
                <input
                    className="in"
                    placeholder="Ej.: DIARIO-3"
                    value={form.data.key}
                    onChange={(event) =>
                        form.setData('key', event.target.value.toUpperCase())
                    }
                />
            </label>
            {problem && (
                <div className="attn" role="alert">
                    {problem.startsWith('No se puede: armaría un círculo.') ? (
                        <>
                            <b>No se puede: armaría un círculo.</b>{' '}
                            {problem.replace(
                                'No se puede: armaría un círculo. ',
                                '',
                            )}
                        </>
                    ) : (
                        problem
                    )}
                </div>
            )}
            <span className="row">
                <button
                    className="btn-sm btn-primary"
                    type="submit"
                    disabled={form.data.key.trim() === '' || form.processing}
                >
                    Agregar
                </button>
                <button
                    className="btn-sm btn-ghost"
                    type="button"
                    onClick={() => {
                        form.reset();
                        form.clearErrors();
                        setOpen(false);
                    }}
                >
                    Cancelar
                </button>
            </span>
        </form>
    );
}

function GoalPanel({
    graph,
    onClose,
}: {
    graph: ObjectiveGraph;
    onClose: () => void;
}) {
    const done = graph.nodes.filter((node) => node.state === 'done').length;
    const sinks = graph.nodes.filter(
        (node) =>
            !graph.edges.some(
                (edge) =>
                    edge.from === node.key &&
                    graph.nodes.some((other) => other.key === edge.to),
            ),
    );
    const { label } = goalLabel(graph);

    return (
        <>
            <Kicker
                glyph="goal"
                state="Objetivo"
                itemKey={graph.objective.key}
                onClose={onClose}
            />
            <h2>{label}</h2>
            <p className="plan">
                {done} de {graph.nodes.length}{' '}
                {graph.nodes.length === 1
                    ? 'estación hecha'
                    : 'estaciones hechas'}
            </p>
            <NeighbourList
                heading="Se abre al terminar"
                items={sinks.map((node) => ({
                    key: node.key,
                    title: node.title,
                    state: node.state,
                    kind: node.kind,
                    objectiveKey: null,
                }))}
            />
            <p className="note">
                {graph.goal.deadline
                    ? `La cumbre de este objetivo: ${graph.goal.title}, con su revisión el ${formatLongDate(graph.goal.deadline)}.`
                    : `La cumbre de este objetivo: ${graph.goal.title}.`}
            </p>
            <div className="acts">
                <Link
                    className="btn btn-outline"
                    href={objectiveShow(graph.objective.key)}
                >
                    Ver objetivo
                </Link>
            </div>
        </>
    );
}
