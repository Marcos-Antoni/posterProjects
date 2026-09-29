import type { KeyboardEvent, ReactNode } from 'react';

import { tipWidth } from '@/lib/unlock-track';
import type { ItemKind, ItemState } from '@/types/models';

/**
 * Building blocks of the minimal vertical unlock track (styleguide §5.6,
 * mockups 08/09), shared by the per-objective and the global graph. State
 * is carried by shape and stroke weight, never by color alone: done = filled
 * circle with a check, now = thick ring with the blaze inside, available =
 * thin ring, locked ("por venir") = small hollow dot, milestone = the same
 * mark ×1.4, objective = circle with a triangle. Every node is an SVG
 * button (`role="button"`, `tabindex=0`, `aria-label` and `<title>`).
 */

export type GlyphName =
    'done' | 'active' | 'open' | 'locked' | 'goal' | 'retired' | 'stub';

export function glyphFor(state: ItemState): GlyphName {
    switch (state) {
        case 'done':
            return 'done';
        case 'active':
            return 'active';
        case 'available':
            return 'open';
        case 'retired':
            return 'retired';
        default:
            return 'locked';
    }
}

/** Words of a node state, for labels and the side panel. */
export const TRACK_STATE: Record<ItemState, string> = {
    done: 'hecha',
    active: 'activa, ahora',
    available: 'disponible',
    locked: 'bloqueada',
    retired: 'retirada',
};

export const PANEL_STATE: Record<ItemState, string> = {
    done: 'Hecha',
    active: 'Activa, ahora',
    available: 'Disponible',
    locked: 'Bloqueada',
    retired: 'Retirada',
};

export const PANEL_MILESTONE_STATE: Record<ItemState, string> = {
    done: 'Hito hecho',
    active: 'Hito, ahora',
    available: 'Hito disponible',
    locked: 'Hito bloqueado',
    retired: 'Hito retirado',
};

/** "Mesa lista, hecha" / "Hito Semana 1, bloqueada". */
export function nodeLabel(title: string, state: ItemState, kind: ItemKind) {
    return `${kind === 'milestone' ? 'Hito ' : ''}${title}, ${TRACK_STATE[state]}`;
}

/** The small 20–22px glyph of the legend, the panel and its lists. */
export function TrackGlyph({
    name,
    size = 20,
}: {
    name: GlyphName;
    size?: number;
}) {
    return (
        <svg
            className="tr g"
            width={size}
            height={size}
            viewBox="0 0 22 22"
            aria-hidden="true"
        >
            {name === 'done' && (
                <>
                    <circle cx="11" cy="11" r="8" className="n-done" />
                    <path d="M7.3 11.2 l2.6 2.6 l5 -5.4" className="n-ck" />
                </>
            )}
            {name === 'active' && (
                <>
                    <circle cx="11" cy="11" r="8" className="n-act" />
                    <rect
                        x="7.4"
                        y="8.5"
                        width="7.2"
                        height="5"
                        rx="1.5"
                        className="n-mark"
                    />
                </>
            )}
            {name === 'open' && (
                <circle cx="11" cy="11" r="7" className="n-open" />
            )}
            {name === 'locked' && (
                <circle cx="11" cy="11" r="4.5" className="n-lock" />
            )}
            {name === 'goal' && (
                <>
                    <circle cx="11" cy="11" r="9.5" className="n-goal" />
                    <path
                        d="M6.6 14.5 L11 5.4 L15.4 14.5 Z"
                        className="n-goal-t"
                    />
                </>
            )}
            {name === 'retired' && <path d="M5 11 H17" className="e-ret" />}
            {name === 'stub' && (
                <circle cx="11" cy="11" r="6" className="n-stub" />
            )}
        </svg>
    );
}

/** The five-symbol legend under the canvas, never the protagonist. */
export function TrackLegend() {
    return (
        <div className="tr-legend">
            <span>
                <TrackGlyph name="done" size={22} />
                Hecha
            </span>
            <span>
                <TrackGlyph name="active" size={22} />
                Ahora
            </span>
            <span>
                <TrackGlyph name="open" size={22} />
                Disponible
            </span>
            <span>
                <TrackGlyph name="locked" size={22} />
                Por venir
            </span>
            <span>
                <TrackGlyph name="goal" size={22} />
                Objetivo
            </span>
        </div>
    );
}

/** Radius of a node mark by state and kind (mockup 08 values). */
export function markRadius(state: ItemState, kind: ItemKind, scale = 1) {
    const milestone = kind === 'milestone';
    let radius: number;

    if (state === 'done') {
        radius = milestone ? 18.2 : 13;
    } else if (state === 'active') {
        radius = milestone ? 18.2 : 14.5;
    } else if (state === 'available') {
        radius = milestone ? 12.6 : 9;
    } else {
        radius = milestone ? 11.3 : 6.5;
    }

    return radius * scale;
}

/** The mark of one item on the track. */
export function TrackMark({
    x,
    y,
    state,
    kind,
    scale = 1,
}: {
    x: number;
    y: number;
    state: ItemState;
    kind: ItemKind;
    scale?: number;
}) {
    const r = markRadius(state, kind, scale);

    if (state === 'done') {
        const k = r / 13;

        return (
            <>
                <circle cx={x} cy={y} r={r} className="n-done" />
                <path
                    d={`M${x - 6 * k} ${y + 0.3 * k} l${4.2 * k} ${4.2 * k} l${8.1 * k} ${-8.8 * k}`}
                    className="n-ck"
                />
            </>
        );
    }

    if (state === 'active') {
        const k = r / 14.5;

        return (
            <>
                <circle cx={x} cy={y} r={r} className="n-act" />
                <rect
                    x={x - 5.8 * k}
                    y={y - 4 * k}
                    width={11.7 * k}
                    height={8.1 * k}
                    rx="1.5"
                    className="n-mark"
                />
            </>
        );
    }

    if (state === 'available') {
        return <circle cx={x} cy={y} r={r} className="n-open" />;
    }

    return (
        <circle
            cx={x}
            cy={y}
            r={r}
            className="n-lock"
            style={kind === 'milestone' ? { strokeWidth: 2.5 } : undefined}
        />
    );
}

/** The objective at the bottom of its track: a circle with a triangle. */
export function GoalMark({
    x,
    y,
    r,
    done,
}: {
    x: number;
    y: number;
    r: number;
    done: boolean;
}) {
    const k = r / 22.8;

    return (
        <>
            <circle
                cx={x}
                cy={y}
                r={r}
                className={done ? 'n-goal done' : 'n-goal'}
            />
            <path
                d={`M${x - 12.5 * k} ${y + 9 * k} L${x} ${y - 11.9 * k} L${x + 12.5 * k} ${y + 9 * k} Z`}
                className="n-goal-t"
            />
        </>
    );
}

/**
 * One focusable station: selection ring, the mark, the hover/focus tooltip
 * (only when the node has no visible label) and a generous hit area.
 * Enter or Space selects it, like a click.
 */
export function TrackNode({
    id,
    x,
    y,
    r,
    label,
    tip,
    selected,
    className,
    onSelect,
    selects,
    dataPlan,
    children,
}: {
    id: string;
    x: number;
    y: number;
    r: number;
    label: string;
    tip: string | null;
    selected: boolean;
    className?: string;
    onSelect: (id: string) => void;
    /** What selecting this mark opens, when it is not the mark itself. */
    selects?: string;
    dataPlan?: string;
    children: ReactNode;
}) {
    const onKeyDown = (event: KeyboardEvent<SVGGElement>) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onSelect(selects ?? id);
        }
    };

    return (
        <g
            className={['nd', selected ? 'sel' : '', className ?? '']
                .filter(Boolean)
                .join(' ')}
            data-id={id}
            data-plan={dataPlan}
            tabIndex={0}
            role="button"
            aria-label={label}
            aria-pressed={selected}
            onClick={() => onSelect(selects ?? id)}
            onKeyDown={onKeyDown}
        >
            <title>{label}</title>
            <circle cx={x} cy={y} r={r + 7} className="selr" />
            {children}
            {tip && (
                <g className="tip" aria-hidden="true">
                    <rect
                        x={x + r + 10}
                        y={y - 13}
                        width={tipWidth(tip)}
                        height="26"
                        rx="6"
                    />
                    <text x={x + r + 19} y={y + 4.5}>
                        {tip}
                    </text>
                </g>
            )}
            <circle cx={x} cy={y} r={Math.max(r + 8, 17)} className="hit" />
        </g>
    );
}

/** Zoom steps of the canvas: 60 % to 200 %, 20 % at a time. */
export function clampZoom(value: number) {
    return Math.round(Math.max(0.6, Math.min(2, value)) * 10) / 10;
}

/** Screen 9 names the zoom levels instead of a percentage ("Lejos"). */
export function zoomDistance(zoom: number): string {
    if (zoom <= 0.8) {
        return 'Muy lejos';
    }

    if (zoom <= 1) {
        return 'Lejos';
    }

    return zoom <= 1.4 ? 'Cerca' : 'Muy cerca';
}

export function ZoomControls({
    zoom,
    onZoom,
    onFit,
    label = (value) => `${Math.round(value * 100)} %`,
}: {
    zoom: number;
    onZoom: (value: number) => void;
    onFit: () => void;
    /** How the level reads: "100 %" (screen 8) or a distance (screen 9). */
    label?: (zoom: number) => string;
}) {
    return (
        <div className="zoom" role="group" aria-label="Zoom">
            <button
                type="button"
                aria-label="Alejar (tecla −)"
                onClick={() => onZoom(clampZoom(zoom - 0.2))}
            >
                <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M5 12h14" />
                </svg>
            </button>
            <span className="pct" aria-live="polite">
                {label(zoom)}
            </span>
            <button
                type="button"
                aria-label="Acercar (tecla +)"
                onClick={() => onZoom(clampZoom(zoom + 0.2))}
            >
                <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </button>
            <span className="sepv" aria-hidden="true" />
            <button type="button" className="fit" onClick={onFit}>
                Ajustar
            </button>
        </div>
    );
}

/** "Cómo leer el mapa": the five symbols in words, on demand. */
export function HowToRead({
    open,
    onToggle,
}: {
    open: boolean;
    onToggle: () => void;
}) {
    return (
        <>
            <button
                className="helpbtn"
                type="button"
                aria-expanded={open}
                aria-controls="how-to-read"
                onClick={onToggle}
            >
                <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.3M12 17h.01" />
                </svg>
                Cómo leer el mapa
            </button>
            {open && (
                <div className="howto" id="how-to-read">
                    <p>
                        La vía baja: lo hecho arriba, el objetivo abajo. Un
                        tramo grueso es camino recorrido; uno fino gris, camino
                        por venir.
                    </p>
                    <ul>
                        <li>
                            <TrackGlyph name="done" /> Hecha: círculo lleno con
                            check.
                        </li>
                        <li>
                            <TrackGlyph name="active" /> Ahora: aro grueso con
                            la marca adentro.
                        </li>
                        <li>
                            <TrackGlyph name="open" /> Disponible: aro fino.
                        </li>
                        <li>
                            <TrackGlyph name="locked" /> Por venir: punto chico;
                            se abre al terminar lo de arriba.
                        </li>
                        <li>
                            <TrackGlyph name="goal" /> Objetivo: círculo con
                            triángulo. Un hito es el mismo punto, más grande.
                        </li>
                    </ul>
                    <p>
                        Pasá el cursor, tabulá o tocá un punto para ver su
                        nombre; Enter lo abre en el panel y Esc lo cierra.
                    </p>
                </div>
            )}
        </>
    );
}
