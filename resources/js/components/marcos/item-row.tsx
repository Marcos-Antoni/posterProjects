import { Link, router } from '@inertiajs/react';

import { ChevronDownIcon, ChevronUpIcon } from '@/components/marcos/icons';
import { StateGlyph } from '@/components/marcos/state-glyph';
import { formatMoment } from '@/lib/marcos';
import { move as itemMove, show as itemShow } from '@/routes/objectives/items';
import type { TreeItem } from '@/types/models';

/**
 * One row of a plan (mockups 04 and 06 `.irow`): glyph, key, title with its
 * 2-minute version, and on the right either its state in words (tree) or the
 * up/down reorder buttons (plan detail). A locked row says what opens it.
 */
export function ItemRow({
    objectiveKey,
    item,
    reorder,
    parallel,
}: {
    objectiveKey: string;
    item: TreeItem;
    /** Plan detail: show the manual-order buttons instead of the state. */
    reorder?: { first: boolean; last: boolean };
    /**
     * Tree: the item is one of a parallel branch (same prerequisites as its
     * siblings, mockup 04 `.irow.par`); `others` are the siblings' numbers.
     */
    parallel?: { first: boolean; others: number[] };
}) {
    const href = itemShow([objectiveKey, item.key]);
    const className = [
        'irow',
        item.state === 'active' && 'active',
        item.state === 'locked' && 'locked',
        parallel && 'par',
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <li className={className}>
            <StateGlyph state={item.state} kind={item.kind} />
            <span className="k">{item.key}</span>
            <span className="t">
                {item.kind === 'milestone' && (
                    <span className="mile">Hito</span>
                )}
                <Link href={href}>{item.title}</Link>
                <span className="two">
                    {item.state === 'done' && item.completed_at ? (
                        `Hecho ${formatMoment(item.completed_at)}`
                    ) : item.state === 'active' ? (
                        <>
                            <b>2 min:</b> {item.two_minute_version}
                        </>
                    ) : (
                        `2 min: ${item.two_minute_version}`
                    )}
                </span>
            </span>
            {reorder ? (
                <span className="mv">
                    <button
                        type="button"
                        aria-label={`Subir ${item.title}`}
                        disabled={reorder.first}
                        onClick={() =>
                            router.post(
                                itemMove([objectiveKey, item.key]).url,
                                { direction: 'up' },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <ChevronUpIcon />
                    </button>
                    <button
                        type="button"
                        aria-label={`Bajar ${item.title}`}
                        disabled={reorder.last}
                        onClick={() =>
                            router.post(
                                itemMove([objectiveKey, item.key]).url,
                                { direction: 'down' },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <ChevronDownIcon />
                    </button>
                </span>
            ) : (
                <span className="r">
                    <span className={`st ${stateClass(item)}`}>
                        {stateText(item, parallel)}
                    </span>
                </span>
            )}
        </li>
    );
}

function stateClass(item: TreeItem): string {
    return {
        done: 'done',
        active: 'active',
        available: 'avail',
        locked: '',
        retired: '',
    }[item.state];
}

function stateText(
    item: TreeItem,
    parallel?: { first: boolean; others: number[] },
): string {
    if (parallel && !parallel.first && item.state === 'locked') {
        return `En paralelo con ${parallel.others.join(', ').replace(/, ([^,]*)$/, ' y $1')}`;
    }

    switch (item.state) {
        case 'done':
            return 'Hecho';
        case 'active':
            return 'Activa ahora';
        case 'available':
            return 'Disponible';
        case 'locked': {
            const open = item.waiting_on;

            if (open.length === 0) {
                return 'Bloqueada';
            }

            if (open.length === 1) {
                return `Se abre al terminar ${open[0].title}`;
            }

            return `Se abre al terminar ${open
                .map((prerequisite) => prerequisite.key.split('-').pop())
                .join(', ')
                .replace(/, ([^,]*)$/, ' y $1')}`;
        }
        default:
            return 'Retirada';
    }
}
