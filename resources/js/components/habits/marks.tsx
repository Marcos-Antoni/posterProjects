import { Fragment } from 'react';

import { markTitle } from '@/lib/habits';
import type { DayMark } from '@/lib/habits';

/**
 * The blaze mark of the habit mockups (`.mk`), in every state: shape and
 * fill carry the meaning (never color alone, never red). `x` is the thin
 * rule drawn where two gaps in a row closed a run.
 */
export function HabitMark({
    mark,
    className,
    label,
}: {
    mark: DayMark['mark'] | 'x';
    className?: string;
    label?: string;
}) {
    return (
        <span
            className={`mk ${mark === 'f' ? 'f' : mark}${className ? ` ${className}` : ''}`}
            aria-label={label}
            aria-hidden={label ? undefined : true}
            role={label ? 'img' : undefined}
        />
    );
}

/**
 * A row of day marks with their day numbers (the last one reads "hoy"); a
 * closed run gets its separator right after the miss that closed it.
 */
export function HabitStrip({
    days,
    today,
    showLabels = true,
    className = 'strp',
}: {
    days: DayMark[];
    today?: string;
    showLabels?: boolean;
    className?: string;
}) {
    return (
        <span className={className}>
            {days.map((day) => {
                const isToday = day.date === today;
                const label = isToday
                    ? 'hoy'
                    : String(Number(day.date.slice(8, 10)));

                return (
                    <Fragment key={day.date}>
                        <span
                            className="cell"
                            title={`${label} ${markTitle(day.mark)}`}
                        >
                            <HabitMark mark={day.mark} />
                            {showLabels && <small>{label}</small>}
                        </span>
                        {day.closes && (
                            <span className="cell" title="tramo cerrado">
                                <HabitMark mark="x" />
                            </span>
                        )}
                    </Fragment>
                );
            })}
        </span>
    );
}

/** Small vote marks, one per opportunity: `v` a vote, `n` none. */
export function VotesRow({ row }: { row: string[] }) {
    return (
        <div className="votes-row" aria-hidden="true">
            {row.map((vote, index) => (
                <i key={index} className={vote === 'v' ? 'v' : 'n'} />
            ))}
        </div>
    );
}

export function RestartIcon({ size = 20 }: { size?: 16 | 20 }) {
    return (
        <svg className={`i s${size}`} viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
            <path d="M3 3v5h5" />
        </svg>
    );
}

export function MinusIcon() {
    return (
        <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M5 12h14" />
        </svg>
    );
}

export function ArchiveIcon() {
    return (
        <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="2" y="3" width="20" height="5" rx="1" />
            <path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8" />
            <path d="M10 12h4" />
        </svg>
    );
}

export function ArrowUpIcon({ size = 16 }: { size?: 16 | 20 }) {
    return (
        <svg className={`i s${size}`} viewBox="0 0 24 24" aria-hidden="true">
            <path d="m5 12 7-7 7 7" />
            <path d="M12 19V5" />
        </svg>
    );
}

export function ArrowDownIcon({ size = 16 }: { size?: 16 | 20 }) {
    return (
        <svg className={`i s${size}`} viewBox="0 0 24 24" aria-hidden="true">
            <path d="m19 12-7 7-7-7" />
            <path d="M12 5v14" />
        </svg>
    );
}
