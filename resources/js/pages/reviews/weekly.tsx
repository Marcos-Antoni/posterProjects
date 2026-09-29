import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactElement } from 'react';

import AppLayout from '@/layouts/app-layout';
import { store as submitWeeklyReview } from '@/routes/reviews/weekly';

type Candidate = { type: string; id: number; label: string };

type Props = {
    progress: {
        items_done: number;
        milestones_done: number;
        habits: {
            name: string;
            streak_state: 'ok' | 'at_risk' | 'restart';
            current_streak: number;
        }[];
        identity: { statement: string; cast: number; possible: number }[];
    };
    main_candidates: Candidate[];
    maintenance_candidates: Candidate[];
};

const STREAK_LABELS: Record<string, string> = {
    ok: 'en marcha',
    at_risk: 'en riesgo',
    restart: 'para retomar',
};

function key(candidate: Candidate): string {
    return `${candidate.type}:${candidate.id}`;
}

/**
 * Screen 14 (mockup visual/screens/14-weekly-review.html): progress FIRST,
 * then the two questions and next week's priority (reviews spec "The Weekly
 * Review Shows Progress Before Asking"). Skipping this screen blocks
 * nothing else.
 */
export default function WeeklyReview({
    progress,
    main_candidates: mainCandidates,
    maintenance_candidates: maintenanceCandidates,
}: Props) {
    const form = useForm({
        what_worked: '',
        what_blocked: '',
        main: mainCandidates[0] ? key(mainCandidates[0]) : '',
        maintenance: [] as string[],
    });

    const toggleMaintenance = (value: string) => {
        const current = form.data.maintenance;

        if (current.includes(value)) {
            form.setData(
                'maintenance',
                current.filter((entry) => entry !== value),
            );

            return;
        }

        if (current.length >= 2) {
            return;
        }

        form.setData('maintenance', [...current, value]);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const [mainType, mainId] = form.data.main.split(':');

        form.transform((data) => ({
            what_worked: data.what_worked,
            what_blocked: data.what_blocked,
            main_type: mainType,
            main_id: Number(mainId),
            maintenance: data.maintenance.map((entry) => {
                const [type, id] = entry.split(':');

                return { type, id: Number(id) };
            }),
        }));
        form.post(submitWeeklyReview().url);
    };

    return (
        <div className="mos-s14">
            <Head title="Revisión semanal" />
            <div className="head">
                <h1 className="h1">Revisión semanal</h1>
            </div>

            <section aria-labelledby="progress-h">
                <h2 id="progress-h" className="sec-h">
                    Cómo te fue
                </h2>
                <div className="progress-grid">
                    <div className="card" style={{ padding: 16 }}>
                        <p className="meta">Tareas hechas</p>
                        <p style={{ fontSize: 28, fontWeight: 700 }}>
                            {progress.items_done}
                        </p>
                    </div>
                    <div className="card" style={{ padding: 16 }}>
                        <p className="meta">Hitos alcanzados</p>
                        <p style={{ fontSize: 28, fontWeight: 700 }}>
                            {progress.milestones_done}
                        </p>
                    </div>
                    {progress.habits.map((habit) => (
                        <div
                            key={habit.name}
                            className="card"
                            style={{ padding: 16 }}
                        >
                            <p className="meta">{habit.name}</p>
                            <p>
                                Racha {habit.current_streak} (
                                {STREAK_LABELS[habit.streak_state]})
                            </p>
                        </div>
                    ))}
                </div>
                {progress.identity.length > 0 && (
                    <ul style={{ listStyle: 'none', padding: 0 }}>
                        {progress.identity.map((group) => (
                            <li key={group.statement} className="meta">
                                {group.statement}: {group.cast} de{' '}
                                {group.possible} en los últimos 7 días.
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <form
                onSubmit={submit}
                className="card"
                style={{ padding: 24, marginTop: 24 }}
            >
                <h2 className="sec-h">Qué funcionó y qué se interpuso</h2>
                <label className="fld">
                    <span className="l">Qué funcionó</span>
                    <textarea
                        className="in"
                        rows={2}
                        value={form.data.what_worked}
                        onChange={(event) =>
                            form.setData('what_worked', event.target.value)
                        }
                    />
                </label>
                <label className="fld">
                    <span className="l">Qué se interpuso</span>
                    <textarea
                        className="in"
                        rows={2}
                        value={form.data.what_blocked}
                        onChange={(event) =>
                            form.setData('what_blocked', event.target.value)
                        }
                    />
                </label>
                {(form.errors.what_worked || form.errors.what_blocked) && (
                    <p className="err" role="alert">
                        {form.errors.what_worked ?? form.errors.what_blocked}
                    </p>
                )}

                <h2 className="sec-h" style={{ marginTop: 24 }}>
                    Prioridad de la semana que viene
                </h2>
                <label className="fld">
                    <span className="l">Prioridad principal</span>
                    <select
                        className="in"
                        value={form.data.main}
                        onChange={(event) =>
                            form.setData('main', event.target.value)
                        }
                    >
                        {mainCandidates.map((candidate) => (
                            <option key={key(candidate)} value={key(candidate)}>
                                {candidate.label}
                            </option>
                        ))}
                    </select>
                </label>
                {(form.errors as Record<string, string>).main_id && (
                    <p className="err" role="alert">
                        {(form.errors as Record<string, string>).main_id}
                    </p>
                )}

                <fieldset style={{ border: 0, padding: 0, margin: '16px 0' }}>
                    <legend className="l">
                        Estándares de mantenimiento (hasta 2)
                    </legend>
                    {maintenanceCandidates.map((candidate) => (
                        <label
                            key={key(candidate)}
                            style={{
                                display: 'flex',
                                gap: 8,
                                alignItems: 'center',
                                marginBottom: 4,
                            }}
                        >
                            <input
                                type="checkbox"
                                checked={form.data.maintenance.includes(
                                    key(candidate),
                                )}
                                onChange={() =>
                                    toggleMaintenance(key(candidate))
                                }
                            />
                            {candidate.label}
                        </label>
                    ))}
                </fieldset>
                {form.errors.maintenance && (
                    <p className="err" role="alert">
                        {form.errors.maintenance}
                    </p>
                )}

                <button
                    className="btn btn-primary"
                    type="submit"
                    disabled={
                        form.processing ||
                        form.data.what_worked.trim() === '' ||
                        form.data.what_blocked.trim() === '' ||
                        form.data.main === ''
                    }
                >
                    Guardar revisión
                </button>
            </form>
        </div>
    );
}

WeeklyReview.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
