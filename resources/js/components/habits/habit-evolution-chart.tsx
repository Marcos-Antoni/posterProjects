import {
    CartesianGrid,
    Line,
    LineChart,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { parseIsoDate } from '@/lib/dates';

/** One day of a habit's history as serialized by `HabitController::show()`. */
export type HabitSeriesPoint = {
    date: string;
    scheduled: boolean;
    completion_percent: number;
    completed: boolean;
    planned_delta_minutes: number | null;
};

function shortDate(value: string): string {
    return parseIsoDate(value).toLocaleDateString('es-AR', {
        day: 'numeric',
        month: 'short',
    });
}

type TooltipPayload = {
    active?: boolean;
    payload?: Array<{ payload: HabitSeriesPoint }>;
};

function ChartTooltip({ active, payload }: TooltipPayload) {
    const point = payload?.[0]?.payload;

    if (!active || !point) {
        return null;
    }

    return (
        <div className="rounded-lg bg-card px-3 py-2 text-sm shadow-md ring-1 ring-foreground/10">
            <p className="text-muted-foreground">{shortDate(point.date)}</p>
            <p className="font-medium">{point.completion_percent}%</p>
        </div>
    );
}

/**
 * Daily completion-percent evolution for one habit. Only scheduled days
 * are plotted (an unscheduled day is not a 0% failure); the dashed
 * reference line marks the 100% target, which the series may exceed.
 * Colors come from the theme tokens, so dark mode is automatic.
 */
export function HabitEvolutionChart({
    series,
}: {
    series: HabitSeriesPoint[];
}) {
    const points = series.filter((point) => point.scheduled);

    return (
        <div className="h-60 w-full">
            <ResponsiveContainer width="100%" height="100%">
                <LineChart
                    data={points}
                    margin={{ top: 8, right: 8, bottom: 0, left: 0 }}
                >
                    <CartesianGrid stroke="var(--border)" vertical={false} />
                    <XAxis
                        dataKey="date"
                        tickFormatter={shortDate}
                        stroke="var(--muted-foreground)"
                        fontSize={12}
                        tickLine={false}
                        axisLine={false}
                        minTickGap={24}
                    />
                    <YAxis
                        width={40}
                        stroke="var(--muted-foreground)"
                        fontSize={12}
                        tickLine={false}
                        axisLine={false}
                        tickFormatter={(value: number) => `${value}%`}
                        domain={[
                            0,
                            (dataMax: number) => Math.max(110, dataMax),
                        ]}
                    />
                    <Tooltip
                        content={<ChartTooltip />}
                        cursor={{
                            stroke: 'var(--muted-foreground)',
                            strokeDasharray: '4 4',
                        }}
                    />
                    <ReferenceLine
                        y={100}
                        stroke="var(--muted-foreground)"
                        strokeDasharray="4 4"
                    />
                    <Line
                        type="monotone"
                        dataKey="completion_percent"
                        stroke="var(--primary)"
                        strokeWidth={2}
                        dot={false}
                        activeDot={{ r: 4 }}
                        isAnimationActive={false}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
