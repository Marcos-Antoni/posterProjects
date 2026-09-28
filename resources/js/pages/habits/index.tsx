import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { ReactElement } from 'react';

import {
    ArchiveIcon,
    ArrowDownIcon,
    ArrowUpIcon,
    RestartIcon,
} from '@/components/habits/marks';
import { PencilIcon, PlusIcon } from '@/components/marcos/icons';
import AppLayout from '@/layouts/app-layout';
import { scheduleLabel, streakCount } from '@/lib/habits';
import type {
    HabitSummary,
    LevelSuggestion,
    RecurrenceType,
} from '@/lib/habits';
import { formatLongDate } from '@/lib/marcos';
import {
    archive as habitArchive,
    create as habitCreate,
    index as habitsIndex,
    show as habitShow,
    today as habitsToday,
    unarchive as habitUnarchive,
} from '@/routes/habits';

type ManagedHabit = HabitSummary & { suggestion: LevelSuggestion | null };

type ArchivedHabit = {
    id: number;
    name: string;
    two_minute_version: string;
    recurrence_type: RecurrenceType;
    archived_at: string | null;
    recorded_days: number;
};

/**
 * Screen 19 (mockup visual/screens/19-habits-manage.html): every habit with
 * its 2-minute version, level, the objective it hangs from and its tolerant
 * streak — never a count of misses. A level suggestion (≥ 80 % over 14 days,
 * or a step down after a break) shows under its row; Marco decides. Nothing
 * is deleted: archived habits keep their history and come back intact.
 */
export default function HabitsManage({
    habits,
    archived,
}: {
    habits: ManagedHabit[];
    archived: ArchivedHabit[];
}) {
    const [showArchived, setShowArchived] = useState(true);

    return (
        <div className="mos-s19">
            <Head title="Todos los hábitos" />

            <nav className="subnav" aria-label="Hábitos">
                <Link href={habitsToday()}>Hoy</Link>
                <Link href={habitsIndex()} aria-current="page">
                    Todos
                </Link>
            </nav>

            <div className="head" style={{ marginBottom: 24 }}>
                <div>
                    <h1 className="h1">Todos los hábitos</h1>
                    <p className="lede">
                        Cada hábito con su versión de 2 minutos, su nivel y de
                        qué objetivo cuelga. Los hábitos no se borran: se
                        archivan con su historia y se pueden reactivar.
                    </p>
                </div>
                <Link className="btn btn-primary" href={habitCreate()}>
                    <PlusIcon />
                    Nuevo hábito
                </Link>
            </div>

            <div className="card">
                <table className="tbl">
                    <thead>
                        <tr>
                            <th scope="col">Hábito</th>
                            <th scope="col">Cuándo</th>
                            <th scope="col">Nivel</th>
                            <th scope="col">Cuelga de</th>
                            <th scope="col">Tramo</th>
                            <th scope="col">
                                <span className="sr">Acciones</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {habits.length === 0 && (
                            <tr>
                                <td colSpan={6}>
                                    Todavía no hay hábitos. Empezá con uno
                                    chico: lo primero es su versión de 2
                                    minutos.
                                </td>
                            </tr>
                        )}
                        {habits.map((habit) => (
                            <Fragment key={habit.id}>
                                <HabitRow habit={habit} />
                                {habit.suggestion && (
                                    <SuggestionRow
                                        habit={habit}
                                        suggestion={habit.suggestion}
                                    />
                                )}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="toolbar">
                <h2>Archivados</h2>
                <button
                    type="button"
                    className="switch"
                    role="switch"
                    aria-checked={showArchived}
                    onClick={() => setShowArchived(!showArchived)}
                    style={{
                        background: 'none',
                        border: 0,
                        color: 'inherit',
                        padding: 0,
                    }}
                >
                    <i
                        aria-hidden="true"
                        style={
                            showArchived
                                ? undefined
                                : { background: 'var(--input)' }
                        }
                    />
                    Mostrar archivados
                </button>
            </div>

            {showArchived && (
                <div className="card">
                    <table className="tbl retired">
                        <thead>
                            <tr>
                                <th scope="col">Hábito</th>
                                <th scope="col">Archivado</th>
                                <th scope="col">Historia</th>
                                <th scope="col">
                                    <span className="sr">Acciones</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {archived.length === 0 && (
                                <tr>
                                    <td colSpan={4}>
                                        No hay hábitos archivados.
                                    </td>
                                </tr>
                            )}
                            {archived.map((habit) => (
                                <tr key={habit.id}>
                                    <td className="nm">
                                        <span
                                            className="bar hatch"
                                            aria-hidden="true"
                                        />
                                        <span>
                                            <b>
                                                <Link
                                                    href={habitShow(habit.id)}
                                                    style={{
                                                        color: 'inherit',
                                                        textDecoration: 'none',
                                                    }}
                                                >
                                                    {habit.name}
                                                </Link>
                                            </b>
                                            <span className="meta">
                                                Archivado tal cual, con su
                                                historia
                                            </span>
                                        </span>
                                    </td>
                                    <td>
                                        {habit.archived_at
                                            ? formatLongDate(
                                                  habit.archived_at.slice(
                                                      0,
                                                      10,
                                                  ),
                                              )
                                            : ''}
                                    </td>
                                    <td>
                                        {habit.recorded_days}{' '}
                                        {habit.recorded_days === 1
                                            ? 'día registrado'
                                            : 'días registrados'}
                                    </td>
                                    <td>
                                        <div className="rowacts">
                                            <button
                                                className="btn-sm btn-outline"
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        habitUnarchive(habit.id)
                                                            .url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <RestartIcon size={16} />
                                                Reactivar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function HabitRow({ habit }: { habit: ManagedHabit }) {
    const whenMeta = [
        habit.planned_time,
        habit.habit_type === 'quantitative'
            ? `${habit.target}${habit.unit ? ` ${habit.unit}` : ''}`
            : null,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <tr>
            <td className="nm">
                <b>
                    <Link
                        href={habitShow(habit.id)}
                        style={{ color: 'inherit', textDecoration: 'none' }}
                    >
                        {habit.name}
                    </Link>
                </b>
                <span className="two">
                    <span className="mini" aria-hidden="true" />2 min:{' '}
                    {habit.two_minute_version ||
                        'todavía sin versión de 2 minutos'}
                </span>
            </td>
            <td>
                {scheduleLabel(habit)}
                {whenMeta !== '' && (
                    <>
                        <br />
                        <span className="meta">{whenMeta}</span>
                    </>
                )}
            </td>
            <td>
                {habit.level ? (
                    <>
                        <span className="ladder" aria-hidden="true">
                            {Array.from(
                                { length: habit.level.total },
                                (_, index) => (
                                    <i
                                        key={index}
                                        className={
                                            index < habit.level!.current
                                                ? 'on'
                                                : undefined
                                        }
                                    />
                                ),
                            )}
                        </span>
                        {habit.level.label}
                        <br />
                        <span className="meta">
                            nivel {habit.level.current} de {habit.level.total}
                        </span>
                    </>
                ) : (
                    <>
                        <span className="ladder single" aria-hidden="true">
                            <i className="on" />
                        </span>
                        Un solo nivel
                    </>
                )}
            </td>
            <td>
                {habit.objective ? (
                    <>
                        {habit.objective.title}
                        <br />
                        <span className="meta">
                            {habit.plan
                                ? `plan ${habit.plan.title}`
                                : 'sin plan'}
                        </span>
                    </>
                ) : (
                    <>
                        Suelto
                        <br />
                        <span className="meta">sin objetivo</span>
                    </>
                )}
            </td>
            <td>
                <StreakCell habit={habit} />
            </td>
            <td>
                <div className="rowacts">
                    <Link
                        className="btn-sm btn-ghost"
                        href={habitShow(habit.id)}
                    >
                        <PencilIcon />
                        Editar
                    </Link>
                    <button
                        className="btn-sm btn-ghost"
                        type="button"
                        onClick={() =>
                            router.post(
                                habitArchive(habit.id).url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <ArchiveIcon />
                        Archivar
                    </button>
                </div>
            </td>
        </tr>
    );
}

/**
 * The Tramo column: the current run, or — after two misses in a row — the
 * run that closed and stays saved (never a zero), plus the best as memory.
 */
function StreakCell({ habit }: { habit: ManagedHabit }) {
    const { streak, recurrence_type: recurrence } = habit;

    if (streak.state === 'restart') {
        const saved = streak.closed_run ?? streak.best;

        return saved > 0 ? (
            <>
                {streakCount(recurrence, saved)} guardado
                <br />
                <span className="meta">mejor: {streak.best} · hoy retomás</span>
            </>
        ) : (
            <>
                Empieza con 2 minutos
                <br />
                <span className="meta">hoy retomás</span>
            </>
        );
    }

    if (streak.current === 0) {
        return (
            <>
                Todavía sin días
                <br />
                <span className="meta">el primero puede ser de 2 minutos</span>
            </>
        );
    }

    return (
        <>
            {streakCount(recurrence, streak.current)}
            <br />
            <span className="meta">
                {streak.state === 'at_risk'
                    ? `hoy toca volver · mejor: ${streak.best}`
                    : `mejor: ${streak.best}`}
            </span>
        </>
    );
}

function SuggestionRow({
    habit,
    suggestion,
}: {
    habit: ManagedHabit;
    suggestion: LevelSuggestion;
}) {
    return (
        <tr className="sugg-row">
            <td colSpan={6}>
                <div className="sugg">
                    {suggestion.direction === 'up' ? (
                        <ArrowUpIcon />
                    ) : (
                        <ArrowDownIcon />
                    )}
                    {suggestion.direction === 'up' ? (
                        <span>
                            <b>
                                {suggestion.cast} de {suggestion.possible} en
                                las últimas dos semanas.
                            </b>{' '}
                            Estás en el borde de lo que podés: ¿probás{' '}
                            {suggestion.label}? El tramo no se toca.
                        </span>
                    ) : (
                        <span>
                            <b>El tramo se cerró.</b> ¿Volvés un escalón, a{' '}
                            {suggestion.label}? Lo que hiciste queda guardado.
                        </span>
                    )}
                    <Link
                        className="btn-text"
                        style={{ color: 'var(--attention)' }}
                        href={habitShow(habit.id)}
                    >
                        Ver sugerencia
                    </Link>
                </div>
            </td>
        </tr>
    );
}

HabitsManage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
