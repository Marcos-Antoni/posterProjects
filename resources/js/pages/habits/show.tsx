import { Head, router, useForm } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import {
    ArchiveIcon,
    ArrowDownIcon,
    ArrowUpIcon,
    HabitMark,
    VotesRow,
} from '@/components/habits/marks';
import { Crumbs } from '@/components/marcos/crumbs';
import { AttentionIcon, PlusIcon } from '@/components/marcos/icons';
import { RetireDialog } from '@/components/marcos/retire-dialog';
import { toast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';
import {
    longDay,
    markTitle,
    scheduleLabel,
    shortDay,
    streakCount,
    WEEKDAY_INITIALS,
    weekdayAndDay,
} from '@/lib/habits';
import type {
    DayMark,
    HabitSummary,
    HabitType,
    LevelSuggestion,
    RecurrenceType,
    Tally,
} from '@/lib/habits';
import {
    index as habitsIndex,
    retire as habitRetire,
    retireContext as habitRetireContext,
    store as habitStore,
    update as habitUpdate,
} from '@/routes/habits';
import { update as levelUpdate } from '@/routes/habits/level';
import {
    destroy as twoMinuteDestroy,
    store as twoMinuteStore,
} from '@/routes/habits/two-minute';

type LadderStep = {
    label: string;
    target: number | null;
    two_minute_version: string;
};

type HabitDetail = HabitSummary & {
    objective_id: number | null;
    plan_id: number | null;
    level_number: number | null;
    level_ladder: LadderStep[];
    started_on: string;
    has_history: boolean;
    suggestion: LevelSuggestion | null;
    next_date: string | null;
    calendar: { week_start: string; days: DayMark[] }[];
    votes: {
        statement: string;
        source: 'habit' | 'objective';
        last7: Tally & { row: string[] };
        last30: Tally & { row: string[] };
    } | null;
};

type ObjectiveOption = {
    id: number;
    key: string;
    title: string;
    plans: { id: number; title: string }[];
};

type Props = { habit: HabitDetail | null; objectives: ObjectiveOption[] };

/**
 * Screen 20 (mockup visual/screens/20-habit-detail.html): one habit — its
 * 2-minute version first, the tolerant streak with its rule drawn
 * ("nunca dos veces seguidas"), the last 8 weeks of marks, its identity
 * votes as a proportion, the level ladder with any suggestion (Marco
 * decides) — beside the form that edits it. With no habit, the same form
 * creates one; without a 2-minute version it does not save.
 */
export default function HabitShow({ habit, objectives }: Props) {
    return (
        <div className="mos-s20">
            <Head title={habit ? habit.name : 'Nuevo hábito'} />

            <Crumbs
                items={[
                    { label: 'Hábitos', href: habitsIndex().url },
                    { label: habit ? habit.name : 'Nuevo hábito' },
                ]}
            />

            <div className="hdl">
                {habit ? <HabitDetailView habit={habit} /> : <NewHabitIntro />}
                <HabitForm habit={habit} objectives={objectives} />
            </div>
        </div>
    );
}

function NewHabitIntro() {
    return (
        <div>
            <h1 className="hname">Nuevo hábito</h1>
            <p className="hsub">
                Lo primero es su versión de 2 minutos: el movimiento físico más
                chico, lo que vas a hacer los días difíciles. Cuenta como día y
                como voto.
            </p>
            <div className="rulebox">
                <strong>Nunca dos veces seguidas</strong>
                <span />
                <StripExample marks={['d', 'd', 'g', 'd', 'd']} />
                <span>
                    Un hueco no corta el tramo: la vez siguiente lo repara.
                </span>
            </div>
        </div>
    );
}

function logTwoMinute(habitId: number) {
    router.post(
        twoMinuteStore(habitId).url,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                toast('Hecho: 2 minutos.', {
                    action: {
                        label: 'Deshacer',
                        onClick: () =>
                            router.delete(twoMinuteDestroy(habitId).url, {
                                preserveScroll: true,
                            }),
                    },
                }),
        },
    );
}

function HabitDetailView({ habit }: { habit: HabitDetail }) {
    const today = habit.calendar.at(-1)?.days.at(-1);
    const shownToday = today?.mark === 'd' || today?.mark === 't';
    const sub = [
        `${scheduleLabel(habit)}${habit.planned_time ? ` a las ${habit.planned_time}` : ''}.`,
        habit.objective
            ? `Cuelga de ${habit.objective.title}${habit.plan ? `, plan ${habit.plan.title}` : ''}.`
            : 'Suelto, sin objetivo.',
        habit.level
            ? `Nivel ${habit.level.current}: ${habit.level.label}.`
            : '',
        habit.retired_at
            ? 'Retirado: su historia queda, no recibe registros.'
            : '',
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <div>
            <h1 className="hname">{habit.name}</h1>
            <p className="hsub">{sub}</p>
            <button
                className="chip2"
                type="button"
                disabled={habit.retired_at !== null || shownToday}
                onClick={() => logTwoMinute(habit.id)}
                title={
                    shownToday
                        ? 'Hoy ya apareciste'
                        : 'Registrar la versión de 2 minutos de hoy'
                }
            >
                <span className="mini" aria-hidden="true" />2 min:{' '}
                {habit.two_minute_version || 'todavía sin versión de 2 minutos'}
            </button>
            {!habit.has_history && (
                <p className="iint">
                    Todavía no hay días. El primero puede ser de 2 minutos.
                </p>
            )}

            <StreakSection habit={habit} />
            <VotesSection habit={habit} />
            <LadderSection habit={habit} />
        </div>
    );
}

function StreakSection({ habit }: { habit: HabitDetail }) {
    const { streak } = habit;
    const unit = (count: number) => streakCount(habit.recurrence_type, count);

    return (
        <section className="sec" aria-labelledby="t-h">
            <h2 id="t-h">Tramo</h2>
            <div className="stats">
                {streak.state === 'restart' && (streak.closed_run ?? 0) > 0 ? (
                    <div className="card stat">
                        <b>{unit(streak.closed_run ?? 0)}</b>
                        <span>
                            Último tramo, cerrado y guardado. El próximo empieza
                            con 2 minutos.
                        </span>
                    </div>
                ) : (
                    <div className="card stat">
                        <b>{unit(streak.current)}</b>
                        <span>
                            {streak.current > 0 && streak.since
                                ? `Tramo actual, desde el ${longDay(streak.since)}`
                                : 'Tramo actual: el primero puede ser de 2 minutos'}
                        </span>
                    </div>
                )}
                <div className="card stat">
                    <b>{unit(streak.best)}</b>
                    <span>Mejor tramo. Queda guardado.</span>
                </div>
                <div className="card stat">
                    <b>
                        {streak.two_minute_returns}{' '}
                        {streak.two_minute_returns === 1 ? 'vuelta' : 'vueltas'}
                    </b>
                    <span>con la versión de 2 minutos en este tramo</span>
                </div>
            </div>
            <div className="rulebox">
                <strong>Nunca dos veces seguidas</strong>
                <span />
                <StripExample marks={['d', 'd', 'g', 'd', 'd']} />
                <span>
                    Un hueco no corta el tramo: la vez siguiente lo repara.
                </span>
                <StripExample marks={['d', 'd', 'g', 'g', 'x']} />
                <span>
                    Dos huecos seguidos cierran el tramo. Queda guardado; el
                    próximo empieza con 2 minutos.
                </span>
            </div>

            <div
                className="cal"
                role="table"
                aria-label="Historial de las últimas 8 semanas"
            >
                <span />
                {[1, 2, 3, 4, 5, 6, 7].map((day) => (
                    <span className="dh" key={day}>
                        {WEEKDAY_INITIALS[day]}
                    </span>
                ))}
                <span />
                {habit.calendar.map((week, index) => {
                    const closing = week.days.find((day) => day.closes);
                    const last = index === habit.calendar.length - 1;

                    return (
                        <Fragment key={week.week_start}>
                            <span className="wk">
                                {shortDay(week.week_start)}
                            </span>
                            {week.days.map((day) => (
                                <span
                                    className="c"
                                    key={day.date}
                                    title={`${shortDay(day.date)}: ${markTitle(day.mark)}`}
                                >
                                    <HabitMark mark={day.mark} />
                                </span>
                            ))}
                            <span className="note">
                                {closing?.closed_run ? (
                                    <>
                                        <b>
                                            Tramo de {unit(closing.closed_run)}
                                        </b>{' '}
                                        cerrado y guardado.
                                    </>
                                ) : last ? (
                                    <>
                                        {streak.current > 0 && (
                                            <b>
                                                Tramo actual:{' '}
                                                {unit(streak.current)}.
                                            </b>
                                        )}
                                        {habit.next_date
                                            ? ` Próxima: ${weekdayAndDay(habit.next_date)}.`
                                            : ''}
                                    </>
                                ) : week.days.some(
                                      (day) => day.mark === 't',
                                  ) ? (
                                    'Volviste con 2 minutos.'
                                ) : null}
                            </span>
                            {closing?.closed_run && !last && (
                                <span className="brk" aria-hidden="true" />
                            )}
                        </Fragment>
                    );
                })}
            </div>
            <ul className="legend" style={{ marginTop: 16 }}>
                <li>
                    <span className="mk d" />
                    Hecho
                </li>
                <li>
                    <span className="mk t" />
                    Versión de 2 minutos
                </li>
                <li>
                    <span className="mk r" />
                    Hueco reparado
                </li>
                <li>
                    <span className="mk g" />
                    Hueco
                </li>
                <li>
                    <span className="mk o" />
                    No toca
                </li>
            </ul>
        </section>
    );
}

function StripExample({ marks }: { marks: (DayMark['mark'] | 'x')[] }) {
    return (
        <span className="strp" aria-hidden="true">
            {marks.map((mark, index) => (
                <span className="cell" key={index}>
                    <HabitMark mark={mark} />
                </span>
            ))}
        </span>
    );
}

function VotesSection({ habit }: { habit: HabitDetail }) {
    if (habit.votes === null) {
        return (
            <section className="sec" aria-labelledby="v-h">
                <h2 id="v-h">Votos de identidad</h2>
                <p className="sec-sub">
                    Este hábito todavía no vota por ninguna frase. Escribí una
                    identidad en el formulario, o en su objetivo, y cada vez que
                    aparezcas cuenta como voto.
                </p>
            </section>
        );
    }

    const { votes } = habit;

    return (
        <section className="sec" aria-labelledby="v-h">
            <h2 id="v-h">Votos por “{votes.statement}”</h2>
            <p className="sec-sub">
                Cada vez que tocaba y fuiste, aunque sea con la versión de 2
                minutos, es un voto. No se suma a ningún puntaje.
                {votes.source === 'objective' && habit.objective
                    ? ` Frase heredada del objetivo ${habit.objective.title}.`
                    : ''}
            </p>
            <div className="ids">
                <div>
                    <p>
                        <b>
                            {votes.last7.cast} de {votes.last7.possible}
                        </b>{' '}
                        en los últimos 7 días
                    </p>
                    <VotesRow row={votes.last7.row} />
                </div>
                <div>
                    <p>
                        <b>
                            {votes.last30.cast} de {votes.last30.possible}
                        </b>{' '}
                        en los últimos 30 días
                    </p>
                    <VotesRow row={votes.last30.row} />
                </div>
            </div>
        </section>
    );
}

function LadderSection({ habit }: { habit: HabitDetail }) {
    const [dismissed, setDismissed] = useState(false);
    const ladder = habit.level_ladder;
    const current = habit.level_number ?? 0;
    const suggestion = habit.suggestion;

    return (
        <section className="sec" aria-labelledby="l-h">
            <h2 id="l-h">Escalera de niveles</h2>
            {ladder.length === 0 ? (
                <p className="sec-sub">
                    Un solo nivel. Para escalar de a poco, agregá niveles en el
                    formulario: cada uno con su meta y su versión de 2 minutos.
                </p>
            ) : (
                <div className="lad">
                    {ladder.map((step, index) => {
                        const number = index + 1;
                        const state =
                            number < current
                                ? ' done'
                                : number === current
                                  ? ' cur'
                                  : '';

                        return (
                            <div
                                className={`step s${Math.min(number, 3)}${state}`}
                                key={number}
                            >
                                <b>
                                    Nivel {number}
                                    {number === current ? ', ahora' : ''}
                                </b>
                                <span>{step.label}</span>
                            </div>
                        );
                    })}
                </div>
            )}
            {suggestion && !dismissed && (
                <div className="lvlup" role="status">
                    {suggestion.direction === 'up' ? (
                        <ArrowUpIcon size={20} />
                    ) : (
                        <ArrowDownIcon size={20} />
                    )}
                    {suggestion.direction === 'up' ? (
                        <span>
                            <b>
                                {suggestion.cast} de {suggestion.possible} en
                                las últimas dos semanas.
                            </b>{' '}
                            Estás en el borde de lo que podés. Subir no toca el
                            tramo.
                        </span>
                    ) : (
                        <span>
                            <b>El tramo se cerró.</b> Volver un escalón hace más
                            fácil empezar; lo que hiciste queda guardado.
                        </span>
                    )}
                    <span
                        style={{ display: 'flex', gap: 8, marginLeft: 'auto' }}
                    >
                        <button
                            className="btn btn-outline btn-md"
                            type="button"
                            onClick={() =>
                                router.post(
                                    levelUpdate(habit.id).url,
                                    { level: suggestion.to_level },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {suggestion.direction === 'up' ? 'Subir' : 'Bajar'}{' '}
                            a {suggestion.label}
                        </button>
                        <button
                            className="btn-text"
                            style={{ color: 'var(--attention)' }}
                            type="button"
                            onClick={() => setDismissed(true)}
                        >
                            Todavía no
                        </button>
                    </span>
                </div>
            )}
        </section>
    );
}

type FormData = {
    name: string;
    two_minute_version: string;
    habit_type: HabitType;
    unit: string;
    daily_target: string;
    recurrence_type: RecurrenceType;
    weekdays: number[];
    times_per_week: string;
    planned_time: string;
    identity_statement: string;
    objective_id: string;
    plan_id: string;
    level: number | null;
    level_ladder: {
        label: string;
        target: string;
        two_minute_version: string;
    }[];
};

function initialData(habit: HabitDetail | null): FormData {
    return {
        name: habit?.name ?? '',
        two_minute_version: habit?.two_minute_version ?? '',
        habit_type: habit?.habit_type ?? 'yes_no',
        unit: habit?.unit ?? '',
        daily_target: habit?.daily_target?.toString() ?? '',
        recurrence_type: habit?.recurrence_type ?? 'daily',
        weekdays: habit?.weekdays ?? [],
        times_per_week: habit?.times_per_week?.toString() ?? '',
        planned_time: habit?.planned_time ?? '',
        identity_statement: habit?.identity_statement ?? '',
        objective_id: habit?.objective_id?.toString() ?? '',
        plan_id: habit?.plan_id?.toString() ?? '',
        level: habit?.level_number ?? null,
        level_ladder: (habit?.level_ladder ?? []).map((step) => ({
            label: step.label,
            target: step.target?.toString() ?? '',
            two_minute_version: step.two_minute_version,
        })),
    };
}

function HabitForm({ habit, objectives }: Props) {
    const form = useForm<FormData>(initialData(habit));
    const { data, setData, errors, processing } = form;
    const [touched, setTouched] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const creating = habit === null;
    const missingTwoMinute = data.two_minute_version.trim() === '';
    const objective = objectives.find(
        (option) => option.id.toString() === data.objective_id,
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setTouched(true);

        if (missingTwoMinute) {
            return;
        }

        form.transform((current) => ({
            ...current,
            objective_id:
                current.objective_id === ''
                    ? null
                    : Number(current.objective_id),
            plan_id: current.plan_id === '' ? null : Number(current.plan_id),
            planned_time:
                current.planned_time === '' ? null : current.planned_time,
            level:
                current.level_ladder.length === 0 ? null : (current.level ?? 1),
            level_ladder: current.level_ladder.map((step) => ({
                label: step.label,
                target: step.target === '' ? null : Number(step.target),
                two_minute_version: step.two_minute_version,
            })),
        }));

        if (creating) {
            form.post(habitStore().url, { preserveScroll: true });
        } else {
            form.patch(habitUpdate(habit.id).url, { preserveScroll: true });
        }
    };

    const toggleWeekday = (weekday: number) =>
        setData(
            'weekdays',
            data.weekdays.includes(weekday)
                ? data.weekdays.filter((day) => day !== weekday)
                : [...data.weekdays, weekday].sort((a, b) => a - b),
        );

    const setStep = (
        index: number,
        field: keyof FormData['level_ladder'][number],
        value: string,
    ) =>
        setData(
            'level_ladder',
            data.level_ladder.map((step, position) =>
                position === index ? { ...step, [field]: value } : step,
            ),
        );

    const addLevel = () => {
        const ladder = [
            ...data.level_ladder,
            {
                label: '',
                target: '',
                two_minute_version:
                    data.level_ladder.length === 0
                        ? data.two_minute_version
                        : '',
            },
        ];

        setData((current) => ({
            ...current,
            level_ladder: ladder,
            level: current.level ?? 1,
        }));
        setEditing(ladder.length - 1);
    };

    const errorFor = (field: string) =>
        (errors as Record<string, string | undefined>)[field];

    return (
        <form
            className="card form"
            aria-labelledby="f-h"
            onSubmit={submit}
            noValidate
        >
            <h2 id="f-h">{creating ? 'Nuevo hábito' : 'Editar hábito'}</h2>

            <label className="fld">
                <span className="l">Nombre</span>
                <input
                    className="in"
                    name="name"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                />
                <FieldMessage message={errorFor('name')} />
            </label>

            <label className="fld">
                <span className="l">Versión de 2 minutos</span>
                <span className="ex">
                    El movimiento físico más chico. Cuenta como día y como voto.
                </span>
                <input
                    className={`in${(touched || errorFor('two_minute_version')) && missingTwoMinute ? 'need' : ''}`}
                    name="two_minute_version"
                    value={data.two_minute_version}
                    placeholder="Ej.: tocarme la punta de los pies una vez"
                    onChange={(event) =>
                        setData('two_minute_version', event.target.value)
                    }
                />
                <FieldMessage
                    message={
                        (touched && missingTwoMinute) ||
                        errorFor('two_minute_version')
                            ? (errorFor('two_minute_version') ??
                              'Falta la versión de 2 minutos. Es lo que vas a hacer los días difíciles.')
                            : undefined
                    }
                />
            </label>

            <div className="fld">
                <span className="l">Qué se mide</span>
                <div className="seg" role="group" aria-label="Qué se mide">
                    <button
                        type="button"
                        aria-pressed={data.habit_type === 'yes_no'}
                        onClick={() => setData('habit_type', 'yes_no')}
                    >
                        Hecho o no
                    </button>
                    <button
                        type="button"
                        aria-pressed={data.habit_type === 'quantitative'}
                        onClick={() => setData('habit_type', 'quantitative')}
                    >
                        Una cantidad
                    </button>
                </div>
            </div>

            {data.habit_type === 'quantitative' && (
                <div className="row2">
                    <label className="fld">
                        <span className="l">Meta diaria</span>
                        <input
                            className="in"
                            type="number"
                            min={1}
                            inputMode="numeric"
                            name="daily_target"
                            value={data.daily_target}
                            onChange={(event) =>
                                setData('daily_target', event.target.value)
                            }
                        />
                        <FieldMessage message={errorFor('daily_target')} />
                    </label>
                    <label className="fld">
                        <span className="l">Unidad</span>
                        <input
                            className="in"
                            placeholder="páginas"
                            name="unit"
                            value={data.unit}
                            onChange={(event) =>
                                setData('unit', event.target.value)
                            }
                        />
                        <FieldMessage message={errorFor('unit')} />
                    </label>
                </div>
            )}

            <div className="fld">
                <span className="l">Cuándo</span>
                <div
                    className="seg"
                    role="group"
                    aria-label="Recurrencia"
                    style={{ marginBottom: 10 }}
                >
                    <button
                        type="button"
                        aria-pressed={data.recurrence_type === 'daily'}
                        onClick={() => setData('recurrence_type', 'daily')}
                    >
                        Todos los días
                    </button>
                    <button
                        type="button"
                        aria-pressed={
                            data.recurrence_type === 'specific_weekdays'
                        }
                        onClick={() =>
                            setData('recurrence_type', 'specific_weekdays')
                        }
                    >
                        Días fijos
                    </button>
                    <button
                        type="button"
                        aria-pressed={data.recurrence_type === 'times_per_week'}
                        onClick={() =>
                            setData('recurrence_type', 'times_per_week')
                        }
                    >
                        Veces por semana
                    </button>
                </div>
                {data.recurrence_type === 'specific_weekdays' && (
                    <div className="days" role="group" aria-label="Días">
                        {[1, 2, 3, 4, 5, 6, 7].map((weekday) => (
                            <button
                                type="button"
                                key={weekday}
                                aria-pressed={data.weekdays.includes(weekday)}
                                onClick={() => toggleWeekday(weekday)}
                            >
                                {WEEKDAY_INITIALS[weekday]}
                            </button>
                        ))}
                    </div>
                )}
                {data.recurrence_type === 'times_per_week' && (
                    <input
                        className="in"
                        type="number"
                        min={1}
                        max={7}
                        aria-label="Veces por semana"
                        name="times_per_week"
                        value={data.times_per_week}
                        onChange={(event) =>
                            setData('times_per_week', event.target.value)
                        }
                    />
                )}
                <FieldMessage
                    message={
                        errorFor('weekdays') ??
                        errorFor('times_per_week') ??
                        errorFor('recurrence_type')
                    }
                />
            </div>

            <label className="fld">
                <span className="l">
                    Hora prevista <em>(opcional)</em>
                </span>
                <input
                    className="in"
                    type="time"
                    name="planned_time"
                    value={data.planned_time}
                    onChange={(event) =>
                        setData('planned_time', event.target.value)
                    }
                />
                <FieldMessage message={errorFor('planned_time')} />
            </label>

            <label className="fld">
                <span className="l">
                    Identidad <em>(opcional)</em>
                </span>
                <input
                    className="in"
                    placeholder="Soy alguien que…"
                    name="identity_statement"
                    value={data.identity_statement}
                    onChange={(event) =>
                        setData('identity_statement', event.target.value)
                    }
                />
                <span className="after">
                    Si la dejás vacía, hereda la del objetivo.
                </span>
                <FieldMessage message={errorFor('identity_statement')} />
            </label>

            <div className="row2">
                <label className="fld">
                    <span className="l">Objetivo</span>
                    <select
                        className="in"
                        name="objective_id"
                        value={data.objective_id}
                        onChange={(event) =>
                            setData((current) => ({
                                ...current,
                                objective_id: event.target.value,
                                plan_id: '',
                            }))
                        }
                    >
                        <option value="">Ninguno</option>
                        {objectives.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.title}
                            </option>
                        ))}
                    </select>
                    <FieldMessage message={errorFor('objective_id')} />
                </label>
                <label className="fld">
                    <span className="l">Plan</span>
                    <select
                        className="in"
                        name="plan_id"
                        value={data.plan_id}
                        disabled={!objective || objective.plans.length === 0}
                        onChange={(event) =>
                            setData('plan_id', event.target.value)
                        }
                    >
                        <option value="">{objective ? 'Sin plan' : '—'}</option>
                        {objective?.plans.map((plan) => (
                            <option key={plan.id} value={plan.id}>
                                {plan.title}
                            </option>
                        ))}
                    </select>
                    <FieldMessage message={errorFor('plan_id')} />
                </label>
            </div>

            <div className="fld">
                <span className="l">Niveles</span>
                {data.level_ladder.length > 0 && (
                    <ol className="lvl-list">
                        {data.level_ladder.map((step, index) => {
                            const number = index + 1;
                            const isCurrent = (data.level ?? 1) === number;

                            return (
                                <li key={index}>
                                    <span className="n">{number}</span>
                                    {editing === index ? (
                                        <span
                                            style={{ display: 'grid', gap: 6 }}
                                        >
                                            <input
                                                className="in"
                                                aria-label={`Nivel ${number}: descripción`}
                                                placeholder="Ej.: 30 min de rutina"
                                                value={step.label}
                                                onChange={(event) =>
                                                    setStep(
                                                        index,
                                                        'label',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            {data.habit_type ===
                                                'quantitative' && (
                                                <input
                                                    className="in"
                                                    type="number"
                                                    min={1}
                                                    aria-label={`Nivel ${number}: meta`}
                                                    placeholder="Meta"
                                                    value={step.target}
                                                    onChange={(event) =>
                                                        setStep(
                                                            index,
                                                            'target',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                            <input
                                                className="in"
                                                aria-label={`Nivel ${number}: versión de 2 minutos`}
                                                placeholder="Versión de 2 minutos de este nivel"
                                                value={step.two_minute_version}
                                                onChange={(event) =>
                                                    setStep(
                                                        index,
                                                        'two_minute_version',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </span>
                                    ) : (
                                        <span>
                                            {isCurrent ? (
                                                <b>
                                                    {step.label ||
                                                        'Sin descripción'}
                                                </b>
                                            ) : (
                                                step.label || 'Sin descripción'
                                            )}
                                            {isCurrent ? ' (actual)' : ''}
                                        </span>
                                    )}
                                    <span style={{ display: 'flex', gap: 4 }}>
                                        {!isCurrent && (
                                            <button
                                                className="btn-text quiet"
                                                type="button"
                                                onClick={() =>
                                                    setData('level', number)
                                                }
                                            >
                                                Usar
                                            </button>
                                        )}
                                        <button
                                            className="btn-text quiet"
                                            type="button"
                                            onClick={() =>
                                                setEditing(
                                                    editing === index
                                                        ? null
                                                        : index,
                                                )
                                            }
                                        >
                                            {editing === index
                                                ? 'Listo'
                                                : 'Editar'}
                                        </button>
                                    </span>
                                </li>
                            );
                        })}
                    </ol>
                )}
                <button className="btn-text" type="button" onClick={addLevel}>
                    <PlusIcon />
                    Agregar nivel
                </button>
                <FieldMessage
                    message={
                        errorFor('level') ??
                        Object.entries(errors as Record<string, string>).find(
                            ([field]) => field.startsWith('level_ladder'),
                        )?.[1]
                    }
                />
            </div>

            <div className="form-actions">
                <button
                    className="btn btn-primary btn-md"
                    type="submit"
                    disabled={processing || (touched && missingTwoMinute)}
                >
                    {creating ? 'Crear hábito' : 'Guardar cambios'}
                </button>
                {habit && habit.retired_at === null && (
                    <RetireHabitButton habitId={habit.id} />
                )}
            </div>
        </form>
    );
}

function FieldMessage({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p className="msg-attn">
            <AttentionIcon />
            <span>{message}</span>
        </p>
    );
}

HabitShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

/**
 * "Retirar hábito" (mockup 20): opens the shared retire dialog (reason +
 * decision, screen 23) — it replaces any delete. Restoring happens from
 * Retirados or "Todos los hábitos".
 */
function RetireHabitButton({ habitId }: { habitId: number }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button
                className="btn-text quiet"
                type="button"
                onClick={() => setOpen(true)}
            >
                <ArchiveIcon />
                Retirar hábito
            </button>
            <RetireDialog
                open={open}
                onOpenChange={setOpen}
                contextUrl={habitRetireContext(habitId).url}
                actionUrl={habitRetire(habitId).url}
            />
        </>
    );
}
