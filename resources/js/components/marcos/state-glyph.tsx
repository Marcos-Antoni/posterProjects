import type { ItemKind, ItemState } from '@/types/models';

type StateGlyphProps = {
    state: ItemState;
    kind?: ItemKind;
    /** Rendered size variant: `row` (tree rows) or `small` (lists, tags). */
    size?: 'row' | 'small';
    className?: string;
};

/**
 * The item-state glyphs of the mockups (shape carries the state, never color
 * alone): done = painted blaze with a check, active = the blaze inside a
 * rounded frame, available = an open ring, locked = a small dashed ring,
 * milestone = a ring with a triangle (the "mojón"). Decorative: the state is
 * always also written in text next to it.
 */
export function StateGlyph({
    state,
    kind = 'task',
    size = 'row',
    className,
}: StateGlyphProps) {
    const small = size === 'small';

    if (kind === 'milestone' && state !== 'done') {
        const box = small ? 18 : 28;

        return (
            <svg
                className={className ?? 'g'}
                width={box}
                height={box}
                viewBox="0 0 28 28"
                aria-hidden="true"
            >
                <circle
                    cx="14"
                    cy="14"
                    r="11"
                    style={{
                        fill: 'var(--surface)',
                        stroke: 'var(--foreground)',
                    }}
                    strokeWidth="3"
                />
                <path
                    d="M9 18 L14 9 L19 18 Z"
                    style={{
                        fill:
                            state === 'locked'
                                ? 'var(--muted-foreground)'
                                : 'var(--foreground)',
                    }}
                />
            </svg>
        );
    }

    if (state === 'done') {
        const box = small ? 18 : 22;

        return (
            <svg
                className={className ?? 'g'}
                width={box}
                height={box}
                viewBox="0 0 22 22"
                aria-hidden="true"
            >
                <circle
                    cx="11"
                    cy="11"
                    r="8"
                    style={{ fill: 'var(--blaze)', stroke: 'var(--blaze-ink)' }}
                    strokeWidth="2.5"
                />
                <path
                    d="M7.5 11 l2.5 2.5 l4.5 -5.5"
                    fill="none"
                    style={{ stroke: 'var(--blaze-foreground)' }}
                    strokeWidth="2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
            </svg>
        );
    }

    if (state === 'active') {
        return small ? (
            <svg
                className={className ?? 'g'}
                width="26"
                height="20"
                viewBox="0 0 26 20"
                aria-hidden="true"
            >
                <rect
                    x="1"
                    y="1"
                    width="24"
                    height="18"
                    rx="5"
                    fill="none"
                    style={{ stroke: 'var(--foreground)' }}
                    strokeWidth="1.2"
                    opacity=".55"
                />
                <rect
                    x="5"
                    y="4"
                    width="16"
                    height="12"
                    rx="2"
                    style={{ fill: 'var(--blaze)', stroke: 'var(--blaze-ink)' }}
                    strokeWidth="2"
                />
            </svg>
        ) : (
            <svg
                className={className ?? 'g'}
                width="30"
                height="24"
                viewBox="0 0 30 24"
                aria-hidden="true"
            >
                <rect
                    x="1"
                    y="1"
                    width="28"
                    height="22"
                    rx="6"
                    fill="none"
                    style={{ stroke: 'var(--foreground)' }}
                    strokeWidth="1.3"
                    opacity=".55"
                />
                <rect
                    x="5"
                    y="5"
                    width="20"
                    height="14"
                    rx="2"
                    style={{ fill: 'var(--blaze)', stroke: 'var(--blaze-ink)' }}
                    strokeWidth="2"
                />
            </svg>
        );
    }

    if (state === 'available') {
        const box = small ? 18 : 22;

        return (
            <svg
                className={className ?? 'g'}
                width={box}
                height={box}
                viewBox="0 0 22 22"
                aria-hidden="true"
            >
                <circle
                    cx="11"
                    cy="11"
                    r="7"
                    style={{ fill: 'var(--surface)', stroke: 'var(--line-1)' }}
                    strokeWidth="3"
                />
            </svg>
        );
    }

    const box = small ? 18 : 22;

    return (
        <svg
            className={className ?? 'g'}
            width={box}
            height={box}
            viewBox="0 0 22 22"
            aria-hidden="true"
        >
            <circle
                cx="11"
                cy="11"
                r="6"
                style={{ fill: 'var(--surface)', stroke: 'var(--node-locked)' }}
                strokeWidth="2"
                strokeDasharray="2.5 2.5"
            />
        </svg>
    );
}
