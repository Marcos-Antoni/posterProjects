/**
 * Pixel geometry of the minimal vertical unlock track (styleguide §5.6,
 * mockups 08/09). The server lays the graph out
 * (`App\Http\Resources\UnlockTrackLayout`): stations get a row and a lane,
 * and every drawn edge comes as its waypoints, one per row (edges into the
 * same station share them and merge into one trunk). This file turns that
 * into coordinates and SVG paths with segments only at 0°, 45° and 90° and
 * small rounded corners.
 *
 * Every gap between two rows has three bands: a lead band under the upper
 * row where only the vertical lines leaving its stations and waypoints run,
 * a diagonal band, and a tail band above the lower row where only the lines
 * arriving at its stations and waypoints run. A hop keeps its source lane
 * through the lead band, turns 45° right where the diagonal band starts and
 * reaches its target lane before the tail band — so two hops never run on
 * top of each other (same source or same target share an end) and nothing
 * but vertical lines ever passes next to a station.
 */

/** Horizontal distance between two lanes. */
export const LANE_GAP = 38;
/** Minimum vertical distance between two rows. */
export const ROW_GAP = 56;
/** Default lead and tail bands. */
export const BAND = 12;
/** Corner radius of the rounded turns. */
const CORNER = 8;

export type Point = { x: number; y: number };

export type RoutePoint = { row: number; lane: number; group: string };

export type TrackRoute = { from: string; to: string; points: RoutePoint[] };

/** Row positions plus the lead band of every gap (keyed by upper row). */
export type Rows = { y: Map<number, number>; lead: Map<number, number> };

/**
 * The y of every row from 0 to `last`: each gap is its lead band, the
 * widest 45° run of a hop crossing it, and its tail band (at least
 * {@link ROW_GAP}). `lead`/`tail` raise a gap's bands (stub ticks, the
 * global connectors' horizontal stretch).
 */
export function rowOffsets(
    last: number,
    routes: TrackRoute[],
    top: number,
    lead: Map<number, number> = new Map(),
    tail: Map<number, number> = new Map(),
    minimum: Map<number, number> = new Map(),
): Rows {
    const widest = new Map<number, number>();

    for (const route of routes) {
        route.points.forEach((point, index) => {
            const next = route.points[index + 1];

            if (next && next.group === point.group) {
                const run = Math.abs(next.lane - point.lane) * LANE_GAP;

                widest.set(
                    point.row,
                    Math.max(widest.get(point.row) ?? 0, run),
                );
            }
        });
    }

    const y = new Map<number, number>();
    const leads = new Map<number, number>();
    let at = top;

    for (let row = 0; row <= last; row++) {
        y.set(row, at);
        const leadBand = Math.max(BAND, lead.get(row) ?? 0);
        leads.set(row, leadBand);
        at += Math.max(
            ROW_GAP,
            minimum.get(row) ?? 0,
            leadBand +
                (widest.get(row) ?? 0) +
                Math.max(BAND, tail.get(row) ?? 0),
        );
    }

    return { y, lead: leads };
}

const round = (value: number) => Math.round(value * 10) / 10;

/** One hop between two consecutive rows (see the bands above). */
function hopSegments(from: Point, to: Point, lead: number): string[] {
    const dx = to.x - from.x;

    if (dx === 0) {
        return [`L${round(to.x)} ${round(to.y)}`];
    }

    const run = Math.abs(dx);
    const sign = Math.sign(dx);
    const top = from.y + lead;
    const bottom = top + run;
    const corner = Math.min(CORNER, lead, to.y - bottom);
    const slant = corner * Math.SQRT1_2;

    return [
        `L${round(from.x)} ${round(top - corner)}`,
        `Q${round(from.x)} ${round(top)} ${round(from.x + sign * slant)} ${round(top + slant)}`,
        `L${round(to.x - sign * slant)} ${round(bottom - slant)}`,
        `Q${round(to.x)} ${round(bottom)} ${round(to.x)} ${round(bottom + corner)}`,
        `L${round(to.x)} ${round(to.y)}`,
    ];
}

/**
 * The global graph's connector between two tracks: down the source lane
 * into the lead band, across at `across` (inside the lead band, where only
 * vertical lines run and no station sits), and down the target lane.
 */
function connectorSegments(from: Point, to: Point, across: number): string[] {
    const sign = Math.sign(to.x - from.x) || 1;
    const corner = Math.min(
        CORNER,
        Math.abs(to.x - from.x) / 2,
        across - from.y,
        to.y - across,
    );

    return [
        `L${round(from.x)} ${round(across - corner)}`,
        `Q${round(from.x)} ${round(across)} ${round(from.x + sign * corner)} ${round(across)}`,
        `L${round(to.x - sign * corner)} ${round(across)}`,
        `Q${round(to.x)} ${round(across)} ${round(to.x)} ${round(across + corner)}`,
        `L${round(to.x)} ${round(to.y)}`,
    ];
}

/**
 * The whole path of one routed edge, one continuous subpath. `leadOf(row)`
 * is the lead band of the gap under `row`; `across(point)` the height of a
 * connector leaving `point` towards another group.
 */
export function routePath(
    points: (Point & { group: string; row: number })[],
    leadOf: (row: number) => number,
    across: (from: Point & { row: number }) => number = (from) =>
        from.y + leadOf(from.row) / 2,
): string {
    const [first, ...rest] = points;
    const parts = [`M${round(first.x)} ${round(first.y)}`];
    let previous = first;

    for (const point of rest) {
        parts.push(
            ...(point.group === previous.group
                ? hopSegments(previous, point, leadOf(previous.row))
                : connectorSegments(previous, point, across(previous))),
        );
        previous = point;
    }

    return parts.join(' ');
}

/**
 * A stub's tick: from its station 45° out into the free space between two
 * lanes (only the station's own vertical lines run that close to it), then
 * straight up or down to the stub mark.
 */
export function stubPath(station: Point, mark: Point): string {
    const side = Math.sign(mark.x - station.x);
    const vertical = Math.sign(mark.y - station.y);
    const run = Math.abs(mark.x - station.x);
    const elbow = { x: station.x + side * run, y: station.y + vertical * run };

    return [
        `M${round(station.x)} ${round(station.y)}`,
        `L${round(elbow.x)} ${round(elbow.y)}`,
        `L${round(mark.x)} ${round(mark.y)}`,
    ].join(' ');
}

/** Width of a hover/focus tooltip for a 13px/600 Overpass label. */
export function tipWidth(text: string): number {
    return round(text.length * 7.28 + 18);
}
