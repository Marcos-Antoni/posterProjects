import type { ItemRef, ItemState } from '@/types/models';

type Station = ItemRef & { role: 'before' | 'self' | 'after' };

const Y = 26;

/**
 * The Now card's mini-map (mockup 02, "Esto abre"): a short horizontal
 * stretch of the unlock track — the last done station before the task, the
 * task itself and the first station it opens — drawn with the styleguide's
 * track primitives (`.tr`). Further items it opens in parallel are counted
 * in text, never drawn as a false chain.
 *
 * After a completion (`unlockedKeys`), the completed mark and every station
 * that just became available carry `.unlock`, which plays the unlock
 * animation or, with reduced motion, a static ring (unlock-graph spec).
 */
export function NowMiniMap({
    self,
    selfLabel,
    prerequisite,
    unlocks,
    unlockedKeys = [],
    milestone = null,
}: {
    /** The open milestone the task leads to, drawn as the end of the stretch. */
    milestone?: { key: string; title: string } | null;
    self: ItemRef;
    /** Label under the task's own station; null draws none. */
    selfLabel: string | null;
    prerequisite: ItemRef | null;
    unlocks: ItemRef[];
    unlockedKeys?: string[];
}) {
    const opened = [...unlocks].sort(
        (a, b) =>
            Number(unlockedKeys.includes(b.key)) -
            Number(unlockedKeys.includes(a.key)),
    );
    const stations: Station[] = [
        ...(prerequisite ? [{ ...prerequisite, role: 'before' as const }] : []),
        { ...self, role: 'self' },
        ...opened
            .slice(0, 1)
            .map((item) => ({ ...item, role: 'after' as const })),
    ];

    if (
        milestone &&
        !stations.some((station) => station.key === milestone.key)
    ) {
        stations.push({ ...milestone, state: 'locked', role: 'after' });
    }

    const positions = stations.map((station, index) => {
        if (milestone && station.key === milestone.key) {
            return 560;
        }

        return prerequisite ? [22, 96, 250][index] : [22, 176][index];
    });
    const isMilestone = (key: string) => milestone?.key === key;
    const parallel = Math.max(0, unlocks.length - 1);
    const completing = unlockedKeys.length > 0 || self.state === 'done';
    const label = [
        `${self.title}, ${STATE_WORDS[self.state]}`,
        unlocks.length > 0
            ? `al terminarla se abre ${unlocks.map((item) => `${item.title} (${STATE_WORDS[item.state]})`).join(', ')}`
            : 'no abre otra tarea por ahora',
    ].join('; ');

    return (
        <svg
            className="minimap tr"
            viewBox="0 0 592 76"
            role="img"
            aria-label={label}
        >
            {stations.slice(1).map((station, index) => {
                const from = stations[index];

                return (
                    <path
                        key={`edge-${station.key}`}
                        d={`M${positions[index]} ${Y} L${positions[index + 1]} ${Y}`}
                        className={[
                            'e',
                            edgeClass(station.state),
                            completing &&
                            (from.role === 'self' ||
                                unlockedKeys.includes(station.key))
                                ? 'draw'
                                : '',
                        ]
                            .filter(Boolean)
                            .join(' ')}
                        data-from={from.key}
                    />
                );
            })}
            {stations.map((station, index) => {
                const unlocking =
                    unlockedKeys.includes(station.key) ||
                    (completing && station.role === 'self');

                return (
                    <g
                        key={station.key}
                        className={unlocking ? 'nd unlock' : 'nd'}
                        data-key={station.key}
                        data-state={station.state}
                    >
                        <title>{`${station.title}, ${STATE_WORDS[station.state]}`}</title>
                        <circle
                            cx={positions[index]}
                            cy={Y}
                            r="17"
                            className="halo"
                        />
                        <Mark
                            x={positions[index]}
                            state={station.state}
                            milestone={isMilestone(station.key)}
                        />
                    </g>
                );
            })}
            {stations.map((station, index) =>
                station.role === 'before' ||
                (station.role === 'self' && selfLabel === null) ||
                isMilestone(station.key) ? null : (
                    <text
                        key={`label-${station.key}`}
                        x={positions[index]}
                        y="64"
                        className={
                            station.role === 'self' ? 'lb-row' : 'lb-row mute'
                        }
                        textAnchor="middle"
                    >
                        {station.role === 'self'
                            ? selfLabel
                            : shorten(station.title)}
                    </text>
                ),
            )}
            {parallel > 0 && (
                <text
                    x={(prerequisite ? 250 : 176) + 28}
                    y={Y + 5}
                    className="par"
                >
                    {`+${parallel} en paralelo`}
                </text>
            )}
        </svg>
    );
}

const STATE_WORDS: Record<ItemState, string> = {
    done: 'hecha',
    active: 'activa, ahora',
    available: 'disponible',
    locked: 'bloqueada',
    retired: 'retirada',
};

function edgeClass(to: ItemState): string {
    if (to === 'locked') {
        return 'e-lock';
    }

    return to === 'available' ? 'e-open' : 'e-done';
}

function shorten(title: string): string {
    return title.length > 30 ? `${title.slice(0, 29)}…` : title;
}

function Mark({
    x,
    state,
    milestone = false,
}: {
    x: number;
    state: ItemState;
    milestone?: boolean;
}) {
    if (milestone && state === 'locked') {
        return (
            <circle
                cx={x}
                cy={Y}
                r="8.7"
                className="n-lock"
                style={{ strokeWidth: 2.5 }}
            />
        );
    }

    if (state === 'done') {
        return (
            <>
                <circle cx={x} cy={Y} r="10" className="n-done" />
                <path
                    d={`M${x - 4.6} ${Y + 0.2} l3.2 3.2 l6.2 -6.8`}
                    className="n-ck"
                />
            </>
        );
    }

    if (state === 'active') {
        return (
            <>
                <circle cx={x} cy={Y} r="11.5" className="n-act" />
                <rect
                    x={x - 4.5}
                    y={Y - 3.1}
                    width="9"
                    height="6.2"
                    rx="1.5"
                    className="n-mark"
                />
            </>
        );
    }

    if (state === 'available') {
        return <circle cx={x} cy={Y} r="9" className="n-open" />;
    }

    return <circle cx={x} cy={Y} r="5" className="n-lock" />;
}
