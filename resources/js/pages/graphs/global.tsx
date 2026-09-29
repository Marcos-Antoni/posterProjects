import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties, ReactElement } from 'react';

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
    zoomDistance,
    glyphFor,
    markRadius,
    nodeLabel,
} from '@/components/marcos/unlock-track';
import type { GlyphName } from '@/components/marcos/unlock-track';
import AppLayout from '@/layouts/app-layout';
import { activeObjectivesSentence } from '@/lib/marcos';
import { LANE_GAP, routePath, rowOffsets, stubPath } from '@/lib/unlock-track';
import type { TrackRoute } from '@/lib/unlock-track';
import { index as mapIndex, show as mapShow } from '@/routes/map';
import { create as objectiveCreate } from '@/routes/objectives';
import { show as itemShow } from '@/routes/objectives/items';
import type {
    GlobalCluster,
    GlobalGraph,
    GraphNode,
    TrackLayout,
} from '@/types/graph';
import type { ItemState } from '@/types/models';

type Props = {
    graph: GlobalGraph;
    layout: TrackLayout;
    goals: TrackLayout;
    routes: TrackRoute[];
    /** Drawn-graph edges left out by the lane cap (still in the panel). */
    hidden: { from: string; to: string }[];
    collapsed: string[];
    lineKey: string | null;
};

const GOAL_PREFIX = 'objetivo:';
const RETIRED_PREFIX = 'retirado:';

/**
 * Screen 9 (mockup visual/screens/09-global-graph.html): every active
 * objective as its own vertical track, side by side in its line hue, with
 * the same state language as screen 8. A cross-objective unlock is one thin
 * connector from the prerequisite into the other track, whose column starts
 * below it. The Now task keeps its ocre mark and its name; the items it
 * unlocks carry a halo. An objective collapses to a single node from its
 * goal's panel; filtering by objective or plan dims the rest.
 */
export default function GlobalGraphPage({
    graph,
    layout,
    goals,
    routes,
    hidden,
    collapsed,
    lineKey,
}: Props) {
    const { url } = usePage();
    const [selected, setSelected] = useState<string | null>(() => {
        const query = url.split('?')[1];

        return query ? new URLSearchParams(query).get('sel') : null;
    });
    const [objectiveFilter, setObjectiveFilter] = useState<string>('all');
    const [planFilter, setPlanFilter] = useState<string>('all');
    const [showRetired, setShowRetired] = useState(false);
    const [zoom, setZoom] = useState(1);
    const [help, setHelp] = useState(false);
    const scroller = useRef<HTMLDivElement>(null);

    const geometry = useMemo(
        () => buildGeometry(graph, layout, goals, routes, hidden, collapsed),
        [graph, layout, goals, routes, hidden, collapsed],
    );

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

    // A collapsed line is laid out again by the server: one node, its
    // connectors routed like any other edge.
    const toggleCollapse = (key: string) => {
        const next = collapsed.includes(key)
            ? collapsed.filter((other) => other !== key)
            : [...collapsed, key];

        router.get(
            mapIndex().url,
            next.length > 0 ? { collapsed: next.join(',') } : {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['layout', 'goals', 'routes', 'hidden', 'collapsed'],
            },
        );
    };

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

    const dimCluster = (key: string) =>
        objectiveFilter !== 'all' && objectiveFilter !== key;
    const dimNode = (clusterKey: string, planId: number) =>
        planFilter !== 'all' && planFilter !== `${clusterKey}:${planId}`;

    return (
        <div className="mos-s09">
            <Head title="Mapa global" />
            <div className={selected ? 'gpage has-sel' : 'gpage'}>
                <main className="gmain">
                    <div className="ghead">
                        <div>
                            <h1>Mapa global</h1>
                            <p>
                                {graph.objectives.length === 0
                                    ? 'Todavía no hay objetivos activos.'
                                    : activeObjectivesSentence(
                                          graph.objectives.length,
                                      )}{' '}
                                {graph.now
                                    ? `Ahora estás en ${graph.now.title}.`
                                    : graph.objectives.length > 0 &&
                                      'No hay una tarea activa ahora.'}
                            </p>
                        </div>
                        <div
                            className="seg"
                            role="navigation"
                            aria-label="Vista del mapa"
                        >
                            {lineKey && (
                                <Link href={mapShow(lineKey)}>Esta línea</Link>
                            )}
                            <Link href={mapIndex()} aria-current="page">
                                Global
                            </Link>
                        </div>
                    </div>

                    {graph.objectives.length > 0 && (
                        <div className="gtools">
                            <span className="lab">Objetivo</span>
                            <div
                                className="seg"
                                role="group"
                                aria-label="Filtrar por objetivo"
                            >
                                <button
                                    type="button"
                                    aria-pressed={objectiveFilter === 'all'}
                                    onClick={() => setObjectiveFilter('all')}
                                >
                                    Todos
                                </button>
                                {graph.objectives.map((cluster) => (
                                    <button
                                        key={cluster.key}
                                        type="button"
                                        aria-pressed={
                                            objectiveFilter === cluster.key
                                        }
                                        onClick={() =>
                                            setObjectiveFilter(cluster.key)
                                        }
                                    >
                                        <span
                                            className="sw"
                                            style={{
                                                background: `var(--line-${cluster.line})`,
                                            }}
                                            aria-hidden="true"
                                        />
                                        {cluster.key}
                                    </button>
                                ))}
                            </div>
                            <label className="lab" htmlFor="planSel">
                                Plan
                            </label>
                            <select
                                className="sel"
                                id="planSel"
                                value={planFilter}
                                onChange={(event) =>
                                    setPlanFilter(event.target.value)
                                }
                            >
                                <option value="all">Todos los planes</option>
                                {graph.objectives.flatMap((cluster) =>
                                    cluster.plans.map((plan) => (
                                        <option
                                            key={`${cluster.key}:${plan.id}`}
                                            value={`${cluster.key}:${plan.id}`}
                                        >
                                            {cluster.key}: {plan.title}
                                        </option>
                                    )),
                                )}
                            </select>
                            <button
                                className="switch"
                                type="button"
                                aria-pressed={showRetired}
                                onClick={() => setShowRetired(!showRetired)}
                            >
                                <i aria-hidden="true" />
                                Mostrar retirados
                            </button>
                        </div>
                    )}

                    <div className="canvas">
                        <div className="scroller" ref={scroller}>
                            {graph.objectives.length === 0 ? (
                                <div className="empty">
                                    <p>
                                        Todavía no hay un mapa: cada objetivo
                                        activo es una vía. Creá el primero con
                                        su plan de 5 puntos.
                                    </p>
                                    <Link
                                        className="btn btn-outline"
                                        href={objectiveCreate()}
                                    >
                                        Crear un objetivo
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
                                    aria-labelledby="gmap-t"
                                >
                                    <title id="gmap-t">
                                        Mapa global:{' '}
                                        {activeObjectivesSentence(
                                            graph.objectives.length,
                                        ).toLowerCase()}
                                    </title>
                                    <desc>{describe(graph)}</desc>
                                    {geometry.clusters.map((column) => (
                                        <g
                                            key={column.cluster.key}
                                            data-obj={column.cluster.key}
                                            className={[
                                                column.collapsed
                                                    ? 'collapsed-line'
                                                    : '',
                                                dimCluster(column.cluster.key)
                                                    ? 'dim'
                                                    : '',
                                            ]
                                                .filter(Boolean)
                                                .join(' ')}
                                            style={
                                                {
                                                    '--tr-hue': `var(--line-${column.cluster.line})`,
                                                    '--tr-fill': `var(--line-${column.cluster.line})`,
                                                    '--tr-ck': 'var(--surface)',
                                                } as CSSProperties
                                            }
                                        >
                                            {column.edges.map((edge) => (
                                                <path
                                                    key={edge.id}
                                                    d={edge.d}
                                                    className={[
                                                        'e',
                                                        edge.className,
                                                        edge.cross
                                                            ? 'e-cross'
                                                            : '',
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' ')}
                                                />
                                            ))}
                                            {column.more.map((mark) => (
                                                <path
                                                    key={`line:${mark.id}`}
                                                    d={mark.d}
                                                    className="e e-lock e-stub"
                                                />
                                            ))}
                                            {column.more.map((mark) => (
                                                <TrackNode
                                                    key={`mas:${mark.id}`}
                                                    id={`mas:${mark.id}`}
                                                    selects={mark.id}
                                                    x={mark.x}
                                                    y={mark.y}
                                                    r={9}
                                                    label={`${mark.count} conexiones más sin dibujar; se listan en el panel`}
                                                    tip={`${mark.count} más: ver en el panel`}
                                                    selected={false}
                                                    className="more"
                                                    onSelect={setSelected}
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
                                            {column.retired.map(
                                                ({ retired, x, y }) => (
                                                    <TrackNode
                                                        key={retired.key}
                                                        id={
                                                            RETIRED_PREFIX +
                                                            retired.key
                                                        }
                                                        x={x}
                                                        y={y}
                                                        r={6}
                                                        label={`Retirado: ${retired.title}`}
                                                        tip={`Retirado: ${retired.title}`}
                                                        selected={
                                                            selected ===
                                                            RETIRED_PREFIX +
                                                                retired.key
                                                        }
                                                        className="ret"
                                                        onSelect={setSelected}
                                                    >
                                                        <path
                                                            d={`M${x - 6} ${y} H${x + 6}`}
                                                            className="e-ret"
                                                        />
                                                    </TrackNode>
                                                ),
                                            )}
                                            {column.nodes.map(
                                                ({ node, x, y }) => {
                                                    const r = markRadius(
                                                        node.state,
                                                        node.kind,
                                                        0.93,
                                                    );
                                                    const isNow =
                                                        graph.now?.key ===
                                                        node.key;

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
                                                            tip={
                                                                isNow
                                                                    ? null
                                                                    : node.title
                                                            }
                                                            selected={
                                                                selected ===
                                                                node.key
                                                            }
                                                            className={[
                                                                dimNode(
                                                                    column
                                                                        .cluster
                                                                        .key,
                                                                    node.plan_id,
                                                                )
                                                                    ? 'dim'
                                                                    : '',
                                                                graph.now_unlocks.includes(
                                                                    node.key,
                                                                )
                                                                    ? 'opens'
                                                                    : '',
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' ')}
                                                            dataPlan={String(
                                                                node.plan_id,
                                                            )}
                                                            onSelect={
                                                                setSelected
                                                            }
                                                        >
                                                            {graph.now_unlocks.includes(
                                                                node.key,
                                                            ) && (
                                                                <circle
                                                                    cx={x}
                                                                    cy={y}
                                                                    r={r + 6}
                                                                    className="opens-halo"
                                                                />
                                                            )}
                                                            <TrackMark
                                                                x={x}
                                                                y={y}
                                                                state={
                                                                    node.state
                                                                }
                                                                kind={node.kind}
                                                                scale={0.93}
                                                            />
                                                            {isNow && (
                                                                <>
                                                                    <text
                                                                        x={
                                                                            x +
                                                                            r +
                                                                            14
                                                                        }
                                                                        y={
                                                                            y -
                                                                            2
                                                                        }
                                                                        className="lb-act"
                                                                    >
                                                                        {
                                                                            node.title
                                                                        }
                                                                    </text>
                                                                    <text
                                                                        x={
                                                                            x +
                                                                            r +
                                                                            14
                                                                        }
                                                                        y={
                                                                            y +
                                                                            15
                                                                        }
                                                                        className="lb-sub"
                                                                    >
                                                                        ahora
                                                                    </text>
                                                                </>
                                                            )}
                                                        </TrackNode>
                                                    );
                                                },
                                            )}
                                            <TrackNode
                                                id={
                                                    GOAL_PREFIX +
                                                    column.cluster.key
                                                }
                                                x={column.goal.x}
                                                y={column.goal.y}
                                                r={21}
                                                label={`Objetivo ${column.cluster.title}${column.collapsed ? `, contraído: ${progressText(column.cluster)}` : ''}`}
                                                tip={null}
                                                selected={
                                                    selected ===
                                                    GOAL_PREFIX +
                                                        column.cluster.key
                                                }
                                                onSelect={setSelected}
                                            >
                                                <GoalMark
                                                    x={column.goal.x}
                                                    y={column.goal.y}
                                                    r={21}
                                                    done={column.goal.done}
                                                />
                                                <text
                                                    x={column.goal.x}
                                                    y={column.goal.y + 45}
                                                    className="lb-goal"
                                                    textAnchor="middle"
                                                >
                                                    {column.cluster.title}
                                                </text>
                                                {column.collapsed && (
                                                    <text
                                                        x={column.goal.x}
                                                        y={column.goal.y + 64}
                                                        className="lb-sub"
                                                        textAnchor="middle"
                                                    >
                                                        {progressText(
                                                            column.cluster,
                                                        )}
                                                    </text>
                                                )}
                                            </TrackNode>
                                        </g>
                                    ))}
                                </svg>
                            )}
                        </div>
                        <HowToRead
                            open={help}
                            onToggle={() => setHelp(!help)}
                        />
                        {graph.objectives.length > 0 && (
                            <ZoomControls
                                zoom={zoom}
                                onZoom={setZoom}
                                onFit={fit}
                                label={zoomDistance}
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
                            collapsed={collapsed}
                            onToggleCollapse={toggleCollapse}
                            onClose={() => setSelected(null)}
                        />
                    )}
                </aside>
            </div>
        </div>
    );
}

GlobalGraphPage.layout = (page: ReactElement) => (
    <AppLayout bleed>{page}</AppLayout>
);

/** "1 de 5 hechas, 2 disponibles". */
function progressText(cluster: GlobalCluster): string {
    const { done, total, available } = cluster.progress;

    return `${done} de ${total} ${total === 1 ? 'hecha' : 'hechas'}, ${available} ${available === 1 ? 'disponible' : 'disponibles'}`;
}

function edgeClass(dependentState: ItemState): string {
    if (dependentState === 'done' || dependentState === 'active') {
        return 'e-done';
    }

    return dependentState === 'available' ? 'e-open' : 'e-lock';
}

type Column = {
    cluster: GlobalCluster;
    collapsed: boolean;
    nodes: { node: GraphNode; x: number; y: number }[];
    edges: {
        id: string;
        d: string;
        className: string;
        cross: boolean;
    }[];
    retired: {
        retired: GlobalCluster['retired'][number];
        x: number;
        y: number;
    }[];
    goal: { x: number; y: number; done: boolean };
    more: { id: string; count: number; x: number; y: number; d: string }[];
};

/** Height of the first connector's horizontal stretch under its row. */
const ACROSS = 30;
/** Lead band under a row with a "+N" mark (mark + margin inside). */
const BADGE_BAND = 38;
/** Distance between connectors crossing the same gap. */
const ACROSS_STEP = 5;

function buildGeometry(
    graph: GlobalGraph,
    layout: TrackLayout,
    goals: TrackLayout,
    routes: TrackRoute[],
    hidden: { from: string; to: string }[],
    collapsed: string[],
) {
    const clusterOf = new Map<string, GlobalCluster>();
    const nodeByKey = new Map<string, GraphNode>();

    for (const cluster of graph.objectives) {
        for (const node of cluster.nodes) {
            clusterOf.set(node.key, cluster);
            nodeByKey.set(node.key, node);
        }
    }

    const groupOfEnd = (key: string) =>
        key.startsWith('#goal:')
            ? key.slice(6)
            : (clusterOf.get(key)?.key ?? '');

    // Connectors between tracks run across inside the lead band of the gap
    // under their source row, each at its own height, bundled per pair of
    // tracks (connectors between the same two tracks share one height).
    const acrossIndex = new Map<string, number>();
    const perRow = new Map<number, string[]>();

    for (const route of routes) {
        const change = route.points.findIndex(
            (point, at) => at > 0 && point.group !== route.points[at - 1].group,
        );

        if (change < 1) {
            continue;
        }

        const row = route.points[change - 1].row;
        const pair = `${row}|${route.points[change - 1].group}>${route.points[change].group}`;
        const list = perRow.get(row) ?? [];

        if (!list.includes(pair)) {
            list.push(pair);
            perRow.set(row, list);
        }

        acrossIndex.set(`${route.from}>${route.to}`, list.indexOf(pair));
    }

    // Far unlocks the lane cap left out of the drawing: a "+N" mark below
    // the prerequisite, in the gap right of it; its row keeps the band.
    const far = new Map<string, number>();

    for (const edge of hidden) {
        if (layout[edge.from]) {
            far.set(edge.from, (far.get(edge.from) ?? 0) + 1);
        }
    }

    const badgeRows = new Set([...far.keys()].map((key) => layout[key].row));
    const acrossBase = (row: number) =>
        badgeRows.has(row) ? ACROSS + 16 : ACROSS;
    const lead = new Map<number, number>();

    for (const row of badgeRows) {
        lead.set(row, BADGE_BAND);
    }

    for (const [row, list] of perRow) {
        lead.set(row, acrossBase(row) + ACROSS_STEP * (list.length - 1) + 14);
    }

    const lastRow = Math.max(
        0,
        ...Object.values(goals).map((spot) => spot.row),
    );
    const top = 48;
    const rows = rowOffsets(lastRow, routes, top, lead);
    const rowAt = (row: number) => rows.y.get(row) ?? top;

    // One column per objective, as wide as the lanes its stations and
    // waypoints use.
    const widest = new Map<string, number>();

    for (const route of routes) {
        for (const point of route.points) {
            widest.set(
                point.group,
                Math.max(widest.get(point.group) ?? 0, point.lane),
            );
        }
    }

    const columnX = new Map<string, number>();
    let x = 150;

    for (const cluster of graph.objectives) {
        columnX.set(cluster.key, x);
        x += (widest.get(cluster.key) ?? 0) * LANE_GAP + 260;
    }

    const at = (point: { row: number; lane: number; group: string }) => ({
        x: (columnX.get(point.group) ?? 0) + point.lane * LANE_GAP,
        y: rowAt(point.row),
        group: point.group,
        row: point.row,
    });

    const columns: Column[] = graph.objectives.map((cluster) => {
        const spot = goals[cluster.key] ?? { row: 0, lane: 0 };

        return {
            cluster,
            collapsed: collapsed.includes(cluster.key),
            nodes: [],
            edges: [],
            retired: [],
            more: [],
            goal: {
                ...at({ ...spot, group: cluster.key }),
                done:
                    cluster.nodes.length > 0 &&
                    cluster.progress.done === cluster.progress.total,
            },
        };
    });
    const columnOf = new Map(
        columns.map((column) => [column.cluster.key, column]),
    );

    for (const route of routes) {
        const targetGroup = groupOfEnd(route.to);
        const column = columnOf.get(targetGroup);

        if (!column) {
            continue;
        }

        const toGoal = route.to.startsWith('#goal:');
        const cross = groupOfEnd(route.from) !== targetGroup;
        const dependent = nodeByKey.get(route.to);
        const className = toGoal
            ? column.goal.done
                ? 'e-done'
                : 'e-lock'
            : edgeClass(dependent?.state ?? 'locked');
        const nth = acrossIndex.get(`${route.from}>${route.to}`) ?? 0;

        column.edges.push({
            id: `${route.from}>${route.to}`,
            d: routePath(
                route.points.map(at),
                (row) => rows.lead.get(row) ?? 12,
                (from) => from.y + acrossBase(from.row) + ACROSS_STEP * nth,
            ),
            className,
            cross,
        });
    }

    for (const column of columns) {
        if (column.collapsed) {
            continue;
        }

        const cluster = column.cluster;

        for (const node of cluster.nodes) {
            const count = far.get(node.key);
            const spot = layout[node.key];

            if (!count || !spot) {
                continue;
            }

            const origin = at({ ...spot, group: cluster.key });
            const mark = { x: origin.x + LANE_GAP / 2, y: origin.y + 24 };

            column.more.push({
                id: node.key,
                count,
                ...mark,
                d: stubPath(origin, mark),
            });
        }

        column.nodes = cluster.nodes.flatMap((node) => {
            const spot = layout[node.key];

            return spot
                ? [{ node, ...at({ ...spot, group: cluster.key }) }]
                : [];
        });

        const firstRowOfPlan = new Map<number, number>();
        cluster.nodes.forEach((node) => {
            const row = layout[node.key]?.row ?? 0;
            firstRowOfPlan.set(
                node.plan_id,
                Math.min(firstRowOfPlan.get(node.plan_id) ?? row, row),
            );
        });
        let lowest = -Infinity;
        column.retired = cluster.retired.map((item) => {
            const y = Math.max(
                rowAt(firstRowOfPlan.get(item.plan_id) ?? 0) + 25,
                lowest + 18,
            );
            lowest = y;

            return {
                retired: item,
                x: (columnX.get(cluster.key) ?? 0) - 19,
                y,
            };
        });
    }

    const deepest = Math.max(top, ...columns.map((column) => column.goal.y));

    return {
        width: Math.max(x + 60, 600),
        height: deepest + 110,
        clusters: columns,
    };
}

function describe(graph: GlobalGraph): string {
    const tracks = graph.objectives.map((cluster) => {
        const nodes = cluster.nodes.map((node) =>
            nodeLabel(node.title, node.state, node.kind),
        );

        return `${cluster.title}: ${nodes.length > 0 ? nodes.join('; ') : 'sin estaciones todavía'}.`;
    });
    const titleOf = new Map(
        graph.objectives.flatMap((cluster) =>
            cluster.nodes.map((node) => [node.key, node.title] as const),
        ),
    );
    const cross = graph.edges
        .filter((edge) => edge.cross)
        .map(
            (edge) =>
                `al terminar ${titleOf.get(edge.from)} se abre ${titleOf.get(edge.to)}`,
        );

    return [
        `Objetivos en vías verticales lado a lado. ${tracks.join(' ')}`,
        cross.length > 0 ? `Entre objetivos: ${cross.join('; ')}.` : '',
    ]
        .filter(Boolean)
        .join(' ');
}

type Neighbour = {
    key: string;
    title: string;
    state: ItemState;
    objectiveKey: string;
    cross: boolean;
};

function Panel({
    graph,
    selected,
    collapsed,
    onToggleCollapse,
    onClose,
}: {
    graph: GlobalGraph;
    selected: string;
    collapsed: string[];
    onToggleCollapse: (key: string) => void;
    onClose: () => void;
}) {
    if (selected.startsWith(GOAL_PREFIX)) {
        const cluster = graph.objectives.find(
            (candidate) => GOAL_PREFIX + candidate.key === selected,
        );

        if (!cluster) {
            return null;
        }

        const isCollapsed = collapsed.includes(cluster.key);

        return (
            <>
                <Kicker
                    glyph="goal"
                    state="Objetivo"
                    itemKey={cluster.key}
                    onClose={onClose}
                />
                <h2>{cluster.title}</h2>
                <p className="dist">{progressText(cluster)}.</p>
                <div className="acts">
                    <button
                        className="btn btn-outline"
                        type="button"
                        onClick={() => onToggleCollapse(cluster.key)}
                    >
                        {isCollapsed
                            ? 'Expandir esta línea'
                            : 'Contraer esta línea'}
                    </button>
                    <Link
                        className="btn btn-outline"
                        href={mapShow(cluster.key)}
                    >
                        Ver su línea
                    </Link>
                </div>
            </>
        );
    }

    for (const cluster of graph.objectives) {
        const retired = cluster.retired.find(
            (candidate) => RETIRED_PREFIX + candidate.key === selected,
        );

        if (retired) {
            return (
                <>
                    <Kicker
                        glyph="retired"
                        state="Retirada"
                        itemKey={retired.key}
                        onClose={onClose}
                    />
                    <h2>{retired.title}</h2>
                    <p className="plan">
                        {cluster.title}, retirado del plan{' '}
                        {cluster.plans.find(
                            (plan) => plan.id === retired.plan_id,
                        )?.title ?? ''}
                    </p>
                    <p className="note">
                        {retired.reason
                            ? `Razón: ${retired.reason}.`
                            : 'Retirado: no ocupa la vía ni bloquea a nadie.'}
                    </p>
                </>
            );
        }

        const node = cluster.nodes.find(
            (candidate) => candidate.key === selected,
        );

        if (node) {
            return (
                <NodePanel
                    graph={graph}
                    cluster={cluster}
                    node={node}
                    onClose={onClose}
                />
            );
        }
    }

    return null;
}

function Kicker({
    glyph,
    state,
    itemKey,
    onClose,
}: {
    glyph: GlyphName;
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

function NodePanel({
    graph,
    cluster,
    node,
    onClose,
}: {
    graph: GlobalGraph;
    cluster: GlobalCluster;
    node: GraphNode;
    onClose: () => void;
}) {
    const lookup = (key: string, cross: boolean): Neighbour | null => {
        for (const other of graph.objectives) {
            const found = other.nodes.find(
                (candidate) => candidate.key === key,
            );

            if (found) {
                return {
                    key,
                    title: found.title,
                    state: found.state,
                    objectiveKey: other.key,
                    cross,
                };
            }
        }

        return null;
    };
    const before = graph.edges
        .filter((edge) => edge.to === node.key)
        .flatMap((edge) => lookup(edge.from, edge.cross) ?? []);
    const after = graph.edges
        .filter((edge) => edge.from === node.key)
        .flatMap((edge) => lookup(edge.to, edge.cross) ?? []);
    const planTitle = cluster.plans.find(
        (plan) => plan.id === node.plan_id,
    )?.title;
    const crossAfter = after.filter((item) => item.cross);

    return (
        <>
            <Kicker
                glyph={glyphFor(node.state)}
                state={
                    node.kind === 'milestone'
                        ? PANEL_MILESTONE_STATE[node.state]
                        : PANEL_STATE[node.state]
                }
                itemKey={node.key}
                onClose={onClose}
            />
            <h2>{node.title}</h2>
            <p className="plan">
                {cluster.title}
                {planTitle ? `, plan ${planTitle}` : ''}
            </p>
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
            {before.length > 0 && (
                <div>
                    <h3>
                        {node.state === 'locked'
                            ? 'Se abre al terminar'
                            : 'Viene de'}
                    </h3>
                    <NeighbourItems items={before} />
                </div>
            )}
            {after.length > 0 && (
                <div>
                    <h3>Esto abre</h3>
                    <NeighbourItems items={after} />
                </div>
            )}
            {crossAfter.length > 0 && (
                <div className="xfer">
                    <b>Abre otro objetivo</b>
                    {crossAfter
                        .map((item) => {
                            const target = graph.objectives.find(
                                (candidate) =>
                                    candidate.key === item.objectiveKey,
                            );

                            return `${target?.title ?? item.objectiveKey}: ${item.title}`;
                        })
                        .join('; ')}
                </div>
            )}
            <div className="acts">
                <Link
                    className="btn btn-outline"
                    href={mapShow(cluster.key, { query: { sel: node.key } })}
                >
                    Ver en su línea
                </Link>
                <Link
                    className="btn btn-outline"
                    href={itemShow([cluster.key, node.key])}
                >
                    Ver detalle
                </Link>
            </div>
        </>
    );
}

function NeighbourItems({ items }: { items: Neighbour[] }) {
    return (
        <ul>
            {items.map((item) => (
                <li key={item.key}>
                    <TrackGlyph name={glyphFor(item.state)} />
                    <span>
                        {item.title}
                        {item.state === 'active' && ', activa ahora'}
                        {item.cross && (
                            <span className="ext"> · {item.objectiveKey}</span>
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}
