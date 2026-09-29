import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactElement } from 'react';

import { toast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';
import { index as objectivesIndex } from '@/routes/objectives';
import { store as closeObjective } from '@/routes/objectives/close';

type Habit = {
    id: number;
    name: string;
    two_minute_version: string;
    current_streak: number;
};

type Props = {
    objective: {
        key: string;
        title: string;
        state: string;
        is_writable: boolean;
    };
    control_plan: {
        outcome: string;
        metric_target: number | null;
        metric_current: number | null;
    } | null;
    progress: { done: number; total: number; milestones_done: number };
    habits: Habit[];
};

type Decision = 'keep' | 'retire';

/**
 * Screen 13 (mockup visual/screens/13-objective-close.html): the learning
 * review — three answers plus one keep/retire decision per linked habit,
 * saved atomically (reviews spec). Habits are never cut off from their
 * history: "Retirar" here opens the same retirement protocol, with its own
 * reason, right in this form.
 */
export default function ObjectiveClose({ objective, progress, habits }: Props) {
    const form = useForm({
        what_learned: '',
        what_repeat: '',
        what_change: '',
        habits: habits.map((habit) => ({
            habit_id: habit.id,
            decision: 'keep' as Decision,
            reason: '',
        })),
    });

    const setHabitDecision = (habitId: number, decision: Decision) => {
        form.setData(
            'habits',
            form.data.habits.map((entry) =>
                entry.habit_id === habitId ? { ...entry, decision } : entry,
            ),
        );
    };

    const setHabitReason = (habitId: number, reason: string) => {
        form.setData(
            'habits',
            form.data.habits.map((entry) =>
                entry.habit_id === habitId ? { ...entry, reason } : entry,
            ),
        );
    };

    const missingReason = form.data.habits.some(
        (entry) =>
            entry.decision === 'retire' && entry.reason.trim().length < 10,
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(closeObjective(objective.key).url, {
            onSuccess: () => toast(`${objective.title} cerrado.`),
        });
    };

    return (
        <div className="mos-s13">
            <Head title={`Cerrar ${objective.title}`} />
            <div className="head">
                <div>
                    <h1 className="h1">Cerrar {objective.title}</h1>
                    <p className="lr-lede">
                        {progress.done} de {progress.total} tareas hechas
                        {progress.milestones_done > 0
                            ? `, ${progress.milestones_done} hitos alcanzados`
                            : ''}
                        . Antes de cerrarlo: tres respuestas y una decisión por
                        cada hábito que colgaba de él. Se guarda todo junto.
                    </p>
                </div>
            </div>

            <form onSubmit={submit} className="card" style={{ padding: 24 }}>
                <h2>Qué aprendiste</h2>
                <label className="fld">
                    <span className="l">Qué aprendiste</span>
                    <textarea
                        className="in"
                        rows={2}
                        value={form.data.what_learned}
                        onChange={(event) =>
                            form.setData('what_learned', event.target.value)
                        }
                    />
                </label>
                <label className="fld">
                    <span className="l">Qué repetirías</span>
                    <textarea
                        className="in"
                        rows={2}
                        value={form.data.what_repeat}
                        onChange={(event) =>
                            form.setData('what_repeat', event.target.value)
                        }
                    />
                </label>
                <label className="fld">
                    <span className="l">Qué cambiarías</span>
                    <textarea
                        className="in"
                        rows={2}
                        value={form.data.what_change}
                        onChange={(event) =>
                            form.setData('what_change', event.target.value)
                        }
                    />
                </label>
                {(form.errors.what_learned ||
                    form.errors.what_repeat ||
                    form.errors.what_change) && (
                    <p className="err" role="alert">
                        {form.errors.what_learned ??
                            form.errors.what_repeat ??
                            form.errors.what_change}
                    </p>
                )}

                {habits.length > 0 && (
                    <>
                        <h2 style={{ marginTop: 24 }}>
                            Qué pasa con sus hábitos
                        </h2>
                        <p className="sec-sub">
                            Los hábitos no se cierran con el objetivo: siguen
                            con su historia y su tramo. Decidí uno por uno.
                        </p>
                        {habits.map((habit) => {
                            const entry = form.data.habits.find(
                                (candidate) => candidate.habit_id === habit.id,
                            )!;

                            return (
                                <div key={habit.id} className="habit-row">
                                    <div>
                                        <b>{habit.name}</b>
                                        <p className="meta">
                                            2 min: {habit.two_minute_version}
                                            {' · '}
                                            racha {habit.current_streak}
                                        </p>
                                        {entry.decision === 'retire' && (
                                            <label
                                                className="fld"
                                                style={{
                                                    marginTop: 8,
                                                    maxWidth: 420,
                                                }}
                                            >
                                                <span className="l">
                                                    ¿Por qué lo retirás?
                                                </span>
                                                <textarea
                                                    className="in"
                                                    rows={2}
                                                    value={entry.reason}
                                                    onChange={(event) =>
                                                        setHabitReason(
                                                            habit.id,
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                        )}
                                    </div>
                                    <div
                                        className="seg"
                                        role="group"
                                        aria-label={`Decisión para ${habit.name}`}
                                    >
                                        <button
                                            type="button"
                                            aria-pressed={
                                                entry.decision === 'keep'
                                            }
                                            onClick={() =>
                                                setHabitDecision(
                                                    habit.id,
                                                    'keep',
                                                )
                                            }
                                        >
                                            Mantener
                                        </button>
                                        <button
                                            type="button"
                                            aria-pressed={
                                                entry.decision === 'retire'
                                            }
                                            onClick={() =>
                                                setHabitDecision(
                                                    habit.id,
                                                    'retire',
                                                )
                                            }
                                        >
                                            Retirar
                                        </button>
                                    </div>
                                </div>
                            );
                        })}
                    </>
                )}

                <div className="tri-actions" style={{ marginTop: 24 }}>
                    <button
                        className="btn btn-primary"
                        type="submit"
                        disabled={
                            form.processing ||
                            form.data.what_learned.trim() === '' ||
                            form.data.what_repeat.trim() === '' ||
                            form.data.what_change.trim() === '' ||
                            missingReason
                        }
                    >
                        Cerrar el objetivo
                    </button>
                    <button
                        className="btn-text quiet"
                        type="button"
                        onClick={() => router.visit(objectivesIndex().url)}
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    );
}

ObjectiveClose.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
