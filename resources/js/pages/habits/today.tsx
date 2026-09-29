import { Head, Link, router } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import {
    HabitMark,
    HabitStrip,
    MinusIcon,
    RestartIcon,
    VotesRow,
} from '@/components/habits/marks';
import { PlusIcon } from '@/components/marcos/icons';
import { toast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';
import {
    amountLabel,
    longDay,
    scheduleLabel,
    streakCount,
    weekdayAndDay,
} from '@/lib/habits';
import type { DayMark, HabitSummary, Tally } from '@/lib/habits';
import {
    create as habitCreate,
    identity as habitsIdentity,
    index as habitsIndex,
    show as habitShow,
    today as habitsToday,
} from '@/routes/habits';
import {
    decrement as entryDecrement,
    store as entryStore,
} from '@/routes/habits/entries';
import {
    destroy as twoMinuteDestroy,
    store as twoMinuteStore,
} from '@/routes/habits/two-minute';

type TodayRow = HabitSummary & {
    today: {
        accumulated_amount: number;
        completion_percent: number;
        peak_amount: number;
        completed: boolean;
        two_minute_logged: boolean;
        shown_up: boolean;
    };
    week_recorded_days: number | null;
    next_date: string | null;
    strip: DayMark[];
};

type IdentityVotes = {
    statement: string;
    last7: Tally & { row: string[] };
};

type Props = {
    date: string;
    habits: TodayRow[];
    resting: TodayRow[];
    identities: IdentityVotes[];
};

const COUNT_WORDS = [
    'Ninguno',
    'Uno',
    'Dos',
    'Tres',
    'Cuatro',
    'Cinco',
    'Seis',
    'Siete',
    'Ocho',
    'Nueve',
    'Diez',
];

/**
 * Screen 18 (mockup visual/screens/18-habits-today.html): today's habits,
 * each with its 2-minute version first, the last 14 days as marks and the
 * tolerant streak ("nunca dos veces seguidas"). After a miss the row offers
 * "Retomar con 2 minutos" — never a count of missed days, never red.
 */
export default function HabitsToday({
    date,
    habits,
    resting,
    identities,
}: Props) {
    return (
        <div className="mos-s18">
            <Head title="Hábitos de hoy" />

            <nav className="subnav" aria-label="Hábitos">
                <Link href={habitsToday()} aria-current="page">
                    Hoy
                </Link>
                <Link href={habitsIndex()}>Todos</Link>
            </nav>

            <div className="head" style={{ marginBottom: 24 }}>
                <div>
                    <h1 className="h1">Hábitos de hoy</h1>
                    <p className="lede">{lede(date, habits, resting)}</p>
                </div>
            </div>

            <div className="hd-grid">
                <div>
                    {habits.length === 0 ? (
                        <EmptyToday hasAny={resting.length > 0} />
                    ) : (
                        <ul className="hl card">
                            {habits.map((habit) => (
                                <TodayHabitRow
                                    key={habit.id}
                                    habit={habit}
                                    date={date}
                                />
                            ))}
                        </ul>
                    )}

                    <ul className="legend" style={{ margin: '14px 0 0' }}>
                        <li>
                            <span className="mk d" />
                            Hecho
                        </li>
                        <li>
                            <span className="mk t" />
                            Solo la versión de 2 minutos (cuenta como voto)
                        </li>
                        <li>
                            <span className="mk r" />
                            Hueco reparado al volver
                        </li>
                        <li>
                            <span className="mk g" />
                            Hueco
                        </li>
                        <li>
                            <span className="mk p" />
                            Hoy
                        </li>
                        <li>
                            <span className="mk o" />
                            No tocaba
                        </li>
                    </ul>

                    {resting.length > 0 && (
                        <>
                            <h2 className="rest-h">No toca hoy</h2>
                            <ul className="hl card">
                                {resting.map((habit) => (
                                    <RestingHabitRow
                                        key={habit.id}
                                        habit={habit}
                                        date={date}
                                    />
                                ))}
                            </ul>
                        </>
                    )}
                </div>

                <aside className="card side" aria-labelledby="id-h">
                    <h2 id="id-h">Votos de identidad, últimos 7 días</h2>
                    {identities.length === 0 ? (
                        <div className="idn">
                            <p>
                                Todavía no hay frases de identidad. Se escriben
                                en un hábito o en su objetivo.
                            </p>
                        </div>
                    ) : (
                        identities.map((identity) => (
                            <div className="idn" key={identity.statement}>
                                <p>
                                    <b>
                                        {identity.last7.cast} de{' '}
                                        {identity.last7.possible}
                                    </b>{' '}
                                    por “{identity.statement}”
                                </p>
                                <VotesRow row={identity.last7.row} />
                            </div>
                        ))
                    )}
                    <p className="rule">
                        <b>Nunca dos veces seguidas.</b> Un hueco no corta el
                        tramo. Dos huecos seguidos lo cierran y queda guardado;
                        nunca vuelve a cero.{' '}
                        <Link href={habitsIdentity()}>
                            Ver los votos de 30 días
                        </Link>
                    </p>
                </aside>
            </div>
        </div>
    );
}

function lede(date: string, habits: TodayRow[], resting: TodayRow[]): string {
    const day = longDay(date);
    const head = `${day.charAt(0).toUpperCase()}${day.slice(1)}.`;
    const count = habits.length;
    const due =
        count === 0
            ? 'Hoy no toca ningún hábito.'
            : count === 1
              ? 'Uno toca hoy.'
              : `${COUNT_WORDS[count] ?? count} tocan hoy.`;

    if (resting.length === 1) {
        const next = resting[0].next_date;

        return `${head} ${due.replace(/\.$/, '')}; ${resting[0].name} ${next ? `vuelve el ${weekdayAndDay(next).split(' ')[0]}` : 'descansa hoy'}.`;
    }

    if (resting.length > 1) {
        return `${head} ${due} ${COUNT_WORDS[resting.length] ?? resting.length} descansan hoy.`;
    }

    return `${head} ${due}`;
}

function post(
    url: string,
    data: Record<string, number> = {},
    onSuccess?: () => void,
) {
    router.post(url, data, { preserveScroll: true, onSuccess });
}

function logTwoMinute(habit: TodayRow) {
    post(twoMinuteStore(habit.id).url, {}, () =>
        toast('Hecho: 2 minutos.', {
            action: {
                label: 'Deshacer',
                onClick: () =>
                    router.delete(twoMinuteDestroy(habit.id).url, {
                        preserveScroll: true,
                    }),
            },
        }),
    );
}

function bigMark(habit: TodayRow): { mark: DayMark['mark']; label: string } {
    if (habit.today.shown_up) {
        return habit.today.completed
            ? { mark: 'd', label: 'Hecho hoy' }
            : { mark: 't', label: 'Hoy, con la versión de 2 minutos' };
    }

    if (habit.streak.state === 'at_risk') {
        return { mark: 'g', label: 'La última vez quedó en hueco' };
    }

    return { mark: 'p', label: 'Pendiente hoy' };
}

function whenLine(habit: TodayRow): string {
    const parts = [scheduleLabel(habit)];

    if (habit.habit_type === 'quantitative') {
        parts.push(`${habit.target}${habit.unit ? ` ${habit.unit}` : ''}`);
    }

    if (habit.planned_time) {
        parts.push(habit.planned_time);
    }

    if (
        habit.recurrence_type === 'times_per_week' &&
        habit.week_recorded_days !== null
    ) {
        parts.push(
            `esta semana ${habit.week_recorded_days} de ${habit.times_per_week}`,
        );
    }

    return parts.join(', ');
}

function tramoLine(habit: TodayRow): ReactNode {
    const { streak } = habit;
    const run = streakCount(habit.recurrence_type, streak.current);

    if (streak.state === 'restart') {
        return streak.closed_run
            ? `El tramo de ${streakCount(habit.recurrence_type, streak.closed_run)} quedó guardado. Empezás uno nuevo con lo más chico.`
            : 'Empezás un tramo nuevo con lo más chico.';
    }

    if (streak.state === 'at_risk' && !habit.today.shown_up) {
        const last =
            habit.recurrence_type === 'daily'
                ? 'Ayer quedó en hueco'
                : habit.recurrence_type === 'times_per_week'
                  ? 'La semana pasada quedó corta'
                  : 'La última vez quedó en hueco';

        return (
            <>
                {streak.current > 0 && <b>Tramo de {run}.</b>} {last}; hoy toca
                volver.
            </>
        );
    }

    if (streak.current === 0) {
        return 'Todavía no hay días. El primero puede ser de 2 minutos.';
    }

    const repaired = habit.strip
        .filter(
            (day) =>
                day.mark === 'r' &&
                (streak.since === null || day.date >= streak.since),
        )
        .at(-1);

    return (
        <>
            {repaired ? (
                <>
                    <b>Tramo de {run}</b>, con un hueco reparado el{' '}
                    {weekdayAndDay(repaired.date)}.
                </>
            ) : (
                <b>Tramo de {run}.</b>
            )}
            {streak.two_minute_returns > 0 &&
                ` ${streak.two_minute_returns === 1 ? 'Una vez' : `${streak.two_minute_returns} veces`} con la versión de 2 minutos.`}
        </>
    );
}

function TodayHabitRow({ habit, date }: { habit: TodayRow; date: string }) {
    const big = bigMark(habit);
    const needsReturn = !habit.today.shown_up && habit.streak.state !== 'ok';

    return (
        <li className="hb">
            <HabitMark mark={big.mark} className="bigmk" label={big.label} />
            <div>
                <h3>
                    <Link
                        href={habitShow(habit.id)}
                        style={{ color: 'inherit', textDecoration: 'none' }}
                    >
                        {habit.name}
                    </Link>
                </h3>
                <p className="meta when">{whenLine(habit)}</p>
                <p className="two">
                    <span className="mini" aria-hidden="true" />2 min:{' '}
                    {habit.two_minute_version ||
                        'todavía sin versión de 2 minutos'}
                </p>
                <HabitStrip days={habit.strip} today={date} />
                <p className="tramo">{tramoLine(habit)}</p>
            </div>
            <div className="acts">
                {needsReturn && (
                    <button
                        className="chip-attn"
                        type="button"
                        onClick={() => logTwoMinute(habit)}
                    >
                        <RestartIcon />
                        {habit.streak.state === 'restart'
                            ? `Retomar con 2 minutos: ${habit.two_minute_version}`
                            : 'Retomar con 2 minutos'}
                    </button>
                )}

                {habit.habit_type === 'quantitative' ? (
                    <div
                        className="stepper"
                        role="group"
                        aria-label={`${habit.unit ?? 'Cantidad'} de hoy`}
                    >
                        <button
                            type="button"
                            aria-label="Uno menos"
                            disabled={habit.today.accumulated_amount < 1}
                            onClick={() => post(entryDecrement(habit.id).url)}
                        >
                            <MinusIcon />
                        </button>
                        <span>
                            {amountLabel(
                                habit.today.accumulated_amount,
                                habit.target,
                                habit.unit,
                            )}
                        </span>
                        <button
                            type="button"
                            aria-label="Uno más"
                            onClick={() =>
                                post(entryStore(habit.id).url, { amount: 1 })
                            }
                        >
                            <PlusIcon />
                        </button>
                    </div>
                ) : habit.today.completed ? (
                    <p className="meta">Hecho hoy.</p>
                ) : (
                    <button
                        className="btn btn-outline btn-md"
                        type="button"
                        onClick={() => post(entryStore(habit.id).url)}
                    >
                        Marcar hecho
                    </button>
                )}

                {!needsReturn && !habit.today.shown_up && (
                    <button
                        className="btn-text"
                        type="button"
                        style={{ alignSelf: 'flex-start' }}
                        onClick={() => logTwoMinute(habit)}
                    >
                        Solo los 2 minutos
                    </button>
                )}

                {habit.today.two_minute_logged && !habit.today.completed && (
                    <button
                        className="btn-text quiet"
                        type="button"
                        style={{ alignSelf: 'flex-start' }}
                        onClick={() =>
                            router.delete(twoMinuteDestroy(habit.id).url, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Deshacer los 2 minutos
                    </button>
                )}
            </div>
        </li>
    );
}

function RestingHabitRow({ habit, date }: { habit: TodayRow; date: string }) {
    const next = habit.next_date
        ? ` Próxima: ${weekdayAndDay(habit.next_date)}${habit.planned_time ? `, ${habit.planned_time}` : ''}.`
        : '';

    return (
        <li className="hb rest">
            <HabitMark mark="o" className="bigmk" label="No toca hoy" />
            <div>
                <h3>
                    <Link
                        href={habitShow(habit.id)}
                        style={{ color: 'inherit', textDecoration: 'none' }}
                    >
                        {habit.name}
                    </Link>
                </h3>
                <p className="meta when">
                    {scheduleLabel(habit)}.{next}
                </p>
                <HabitStrip days={habit.strip} today={date} />
                <p className="tramo">{tramoLine(habit)}</p>
            </div>
        </li>
    );
}

function EmptyToday({ hasAny }: { hasAny: boolean }) {
    return (
        <div className="card" style={{ padding: '22px 24px' }}>
            <h3 style={{ margin: 0, font: '700 20px/28px Overpass' }}>
                {hasAny
                    ? 'Hoy no toca ningún hábito.'
                    : 'Todavía no hay hábitos.'}
            </h3>
            <p className="why" style={{ margin: '6px 0 14px' }}>
                {hasAny
                    ? 'Los que descansan hoy están abajo, con su próxima vez.'
                    : 'Empezá con uno chico: lo primero que se escribe es su versión de 2 minutos.'}
            </p>
            {!hasAny && (
                <Link className="btn btn-primary btn-md" href={habitCreate()}>
                    <PlusIcon />
                    Nuevo hábito
                </Link>
            )}
        </div>
    );
}

HabitsToday.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
