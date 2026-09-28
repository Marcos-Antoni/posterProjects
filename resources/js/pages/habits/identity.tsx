import { Head, Link } from '@inertiajs/react';
import type { ReactElement } from 'react';

import AppLayout from '@/layouts/app-layout';
import { scheduleLabel, shortDay, WEEKDAY_INITIALS } from '@/lib/habits';
import type { RecurrenceType, Tally } from '@/lib/habits';
import { formatLongDateCapitalized } from '@/lib/marcos';
import { create as habitCreate, show as habitShow } from '@/routes/habits';
import { show as objectiveShow } from '@/routes/objectives';

type VoteMark = 'v' | 'v2' | 'm' | 'n' | 'o';

type Identity = {
    statement: string;
    source: 'habit' | 'objective';
    objective: { key: string; title: string } | null;
    habits: {
        id: number;
        name: string;
        recurrence_type: RecurrenceType;
        weekdays: number[] | null;
        times_per_week: number | null;
    }[];
    last7: Tally & {
        /** One item per opportunity (date null: a weekly-quota miss). */
        items: { date: string | null; mark: VoteMark; habit_id: number }[];
    };
    last30: Tally & {
        weeks: {
            week_start: string;
            days: { date: string; mark: VoteMark }[];
        }[];
    };
};

type Props = {
    date: string;
    identities: Identity[];
    first_habit: { id: number; name: string } | null;
};

const MARK_TITLES: Record<VoteMark, string> = {
    v: 'voto',
    v2: 'voto con la versión de 2 minutos',
    m: 'oportunidad sin voto',
    n: 'sin oportunidad',
    o: '',
};

function weekdayInitial(iso: string): string {
    const day = new Date(`${iso}T12:00:00`).getDay();

    return WEEKDAY_INITIALS[day === 0 ? 7 : day].charAt(0);
}

/**
 * Screen 21 (mockup visual/screens/21-identity-votes.html): each identity
 * statement on its own, in the order it was written — its 7- and 30-day
 * votes as a proportion ("6 de 7 votos"), never a percentage, score or
 * ranking. A 2-minute vote looks different but counts the same.
 */
export default function IdentityVotes({
    date,
    identities,
    first_habit,
}: Props) {
    return (
        <div className="mos-s21">
            <Head title="Votos de identidad" />

            <div className="page-head">
                <div>
                    <h1>Votos de identidad</h1>
                    <p className="lede">
                        Cada vez que aparecés, aunque sea con la versión de 2
                        minutos, votás por la persona que querés ser. Cada frase
                        se mira sola: no se suman ni se comparan.
                    </p>
                </div>
                <p className="sm muted num">
                    {formatLongDateCapitalized(date)}, hora de Guatemala
                </p>
            </div>

            {identities.length === 0 ? (
                <div className="panel empty">
                    <div className="ghost" aria-hidden="true">
                        <i className="mk m" />
                        <i className="mk m" />
                        <i className="mk m" />
                    </div>
                    <div>
                        <h2>Todavía no escribiste ninguna frase</h2>
                        <p className="muted" style={{ marginTop: 8 }}>
                            Una frase de identidad dice quién querés ser, no qué
                            querés lograr. Se escribe en un hábito o en un
                            objetivo, y desde ahí cada día que aparecés cuenta
                            como voto.
                        </p>
                        <Link
                            className="btn btn-primary"
                            href={
                                first_habit
                                    ? habitShow(first_habit.id)
                                    : habitCreate()
                            }
                        >
                            {first_habit
                                ? `Escribir una frase en “${first_habit.name}”`
                                : 'Crear un hábito con su frase'}
                        </Link>
                    </div>
                </div>
            ) : (
                <>
                    <div className="legend" aria-label="Leyenda">
                        <span>
                            <i className="mk v" aria-hidden="true" />
                            Voto: apareciste
                        </span>
                        <span>
                            <i className="mk v2" aria-hidden="true" />
                            Voto con la versión de 2 minutos
                        </span>
                        <span>
                            <i className="mk m" aria-hidden="true" />
                            Oportunidad sin voto
                        </span>
                        <span>
                            <i className="mk n" aria-hidden="true" />
                            Ese día no tocaba
                        </span>
                    </div>
                    <div className="panel ledger">
                        {identities.map((identity, index) => (
                            <IdentitySection
                                key={identity.statement}
                                identity={identity}
                                index={index}
                            />
                        ))}
                    </div>
                </>
            )}

            <p className="aside-note">
                <svg
                    className="ic"
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.75"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 16v-4" />
                    <path d="M12 8h.01" />
                </svg>
                <span>
                    Las frases van en el orden en que las escribiste. Un voto es
                    una oportunidad programada en la que apareciste; los días
                    que no tocaba no cuentan para nada. La frase se edita desde
                    su hábito o su objetivo.
                </span>
            </p>
        </div>
    );
}

function IdentitySection({
    identity,
    index,
}: {
    identity: Identity;
    index: number;
}) {
    const twoMinute7 = identity.last7.two_minute;

    return (
        <section className="idn" aria-labelledby={`idn${index}`}>
            <div className="idn-who">
                <h2 id={`idn${index}`} className="stmt">
                    “{identity.statement}”
                </h2>
                <p className="sm muted">
                    {identity.source === 'objective' && identity.objective ? (
                        <>
                            Heredada del objetivo{' '}
                            <Link href={objectiveShow(identity.objective.key)}>
                                {identity.objective.title}
                            </Link>
                        </>
                    ) : (
                        'Escrita en el hábito'
                    )}
                </p>
                <ul
                    className="feeds"
                    aria-label="Hábitos que votan por esta identidad"
                >
                    {identity.habits.map((habit) => (
                        <li key={habit.id}>
                            <svg
                                className="ic"
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="1.75"
                                aria-hidden="true"
                            >
                                <path d="m17 2 4 4-4 4" />
                                <path d="M3 11v-1a4 4 0 0 1 4-4h14" />
                                <path d="m7 22-4-4 4-4" />
                                <path d="M21 13v1a4 4 0 0 1-4 4H3" />
                            </svg>
                            <Link href={habitShow(habit.id)}>
                                {habit.name}
                                {habit.recurrence_type !== 'daily'
                                    ? ` (${scheduleLabel(habit).toLowerCase()})`
                                    : ''}
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>

            <div
                className="idn-7"
                role="group"
                aria-label={`Últimos 7 días: ${identity.last7.cast} de ${identity.last7.possible} votos`}
            >
                <p className="win">Últimos 7 días</p>
                <p className="prop num">
                    <b>{identity.last7.cast}</b> de {identity.last7.possible}{' '}
                    votos
                </p>
                <div className="wk" aria-hidden="true">
                    {identity.last7.items.map((item, index) => (
                        <span
                            key={index}
                            className={`mk ${item.mark}`}
                            title={`${item.date ? shortDay(item.date) : 'esa semana'}: ${MARK_TITLES[item.mark]}`}
                        />
                    ))}
                </div>
                <div className="wk-l" aria-hidden="true">
                    {identity.last7.items.map((item, index) => (
                        <span key={index}>
                            {item.date ? weekdayInitial(item.date) : 'sem'}
                            <br />
                            <b className="num">
                                {item.date
                                    ? Number(item.date.slice(8, 10))
                                    : '·'}
                            </b>
                        </span>
                    ))}
                </div>
                {twoMinute7 > 0 && (
                    <p className="two-note">
                        {twoMinute7 === 1
                            ? '1 de esos votos fue'
                            : `${twoMinute7} de esos votos fueron`}{' '}
                        con la versión de 2 minutos. Cuenta igual.
                    </p>
                )}
            </div>

            <div
                className="idn-30"
                role="group"
                aria-label={`Últimos 30 días: ${identity.last30.cast} de ${identity.last30.possible} votos`}
            >
                <p className="win">Últimos 30 días</p>
                <p className="prop num">
                    <b>{identity.last30.cast}</b> de {identity.last30.possible}{' '}
                    votos
                </p>
                <div className="mgrid" aria-hidden="true">
                    <div className="mrow mhead">
                        <span className="mrow-l" />
                        {[1, 2, 3, 4, 5, 6, 7].map((day) => (
                            <span className="mh" key={day}>
                                {WEEKDAY_INITIALS[day].charAt(0)}
                            </span>
                        ))}
                    </div>
                    {identity.last30.weeks.map((week) => (
                        <div className="mrow" key={week.week_start}>
                            <span className="mrow-l num">
                                {shortDay(week.week_start)}
                            </span>
                            {week.days.map((day) => (
                                <span
                                    key={day.date}
                                    className={`mk sm ${day.mark}`}
                                    title={
                                        day.mark === 'o'
                                            ? undefined
                                            : `${shortDay(day.date)}: ${MARK_TITLES[day.mark]}`
                                    }
                                />
                            ))}
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

IdentityVotes.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
