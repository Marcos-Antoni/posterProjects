import type { ItemRef, ItemState } from '@/types/models';

type Node = { key: string; title: string; state: ItemState; self: boolean };

const POSITIONS = [22, 110, 220, 304];

/**
 * "En el mapa" (mockup 07): the item on a short horizontal stretch of the
 * unlock track — its nearest prerequisite, itself, and up to two items it
 * unlocks — drawn with the styleguide's track primitives (`.tr`). The full,
 * interactive graph is its own screen.
 */
export function ItemMiniMap({
    self,
    prerequisites,
    unlocks,
}: {
    self: ItemRef;
    prerequisites: ItemRef[];
    unlocks: ItemRef[];
}) {
    const before =
        prerequisites.length > 0
            ? [
                  prerequisites.find((item) => item.state !== 'done') ??
                      prerequisites[prerequisites.length - 1],
              ]
            : [];
    const nodes: Node[] = [
        ...before.map((item) => ({ ...item, self: false })),
        { ...self, self: true },
        ...unlocks
            .slice(0, 4 - before.length - 1)
            .map((item) => ({ ...item, self: false })),
    ];
    const label = nodes
        .map(
            (node) =>
                `${node.self ? 'Esta tarea' : node.title}, ${STATE_WORDS[node.state]}`,
        )
        .join('; ');

    return (
        <svg
            className="nb tr"
            viewBox="0 0 332 96"
            role="img"
            aria-label={label}
        >
            {nodes.slice(1).map((node, index) => {
                const from = nodes[index];
                const edge =
                    from.state === 'done'
                        ? 'e e-done'
                        : node.state === 'locked'
                          ? 'e e-lock'
                          : 'e e-open';

                return (
                    <path
                        key={`edge-${node.key}`}
                        d={`M${POSITIONS[index]} 30 L${POSITIONS[index + 1]} 30`}
                        className={edge}
                    />
                );
            })}
            {nodes.map((node, index) => (
                <g key={node.key} className="nd">
                    <title>{`${node.self ? 'Esta tarea' : node.title}, ${STATE_WORDS[node.state]}`}</title>
                    <NodeMark x={POSITIONS[index]} node={node} />
                </g>
            ))}
            {nodes.map((node, index) =>
                node.self ? (
                    <text
                        key={`label-${node.key}`}
                        x={POSITIONS[index]}
                        y="68"
                        className="lb-row"
                        textAnchor="middle"
                    >
                        Esta
                    </text>
                ) : index > nodes.findIndex((candidate) => candidate.self) ? (
                    <text
                        key={`label-${node.key}`}
                        x={POSITIONS[index]}
                        y="68"
                        className="lb-row mute"
                        textAnchor="middle"
                    >
                        {node.title.length > 18
                            ? `${node.title.slice(0, 17)}…`
                            : node.title}
                    </text>
                ) : null,
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

function NodeMark({ x, node }: { x: number; node: Node }) {
    if (node.state === 'done') {
        return (
            <>
                <circle cx={x} cy="30" r="10" className="n-done" />
                <path
                    d={`M${x - 4.6} 30.2 l3.2 3.2 l6.2 -6.8`}
                    className="n-ck"
                />
            </>
        );
    }

    if (node.state === 'active') {
        return (
            <>
                <circle cx={x} cy="30" r="11.5" className="n-act" />
                <rect
                    x={x - 4.5}
                    y="26.9"
                    width="9"
                    height="6.2"
                    rx="1.5"
                    className="n-mark"
                />
            </>
        );
    }

    if (node.state === 'available') {
        return (
            <circle cx={x} cy="30" r={node.self ? 9 : 7} className="n-open" />
        );
    }

    return <circle cx={x} cy="30" r="5" className="n-lock" />;
}
