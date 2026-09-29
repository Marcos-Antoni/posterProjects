import { Head, Link, router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import { FocusCueNote, useFocusCue } from '@/components/marcos/focus-cue';
import { NowHabitsRow } from '@/components/marcos/now-habits-row';
import type {
    NowHabit,
    RestingHabit,
} from '@/components/marcos/now-habits-row';
import { NowMiniMap } from '@/components/marcos/now-mini-map';
import { toast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';
import {
    formatLongDateCapitalized,
    formatTime,
    MARCOS_TIME_ZONE,
    todayIso,
} from '@/lib/marcos';
import { closeForToday as closeForTodayRoute } from '@/routes/now';
import { index as objectivesIndex } from '@/routes/objectives';
import {
    check as itemCheck,
    start as itemStart,
    summit as itemSummit,
    twoMinute as itemTwoMinute,
    uncheck as itemUncheck,
} from '@/routes/objectives/items';
import type { ItemKind, ItemRef } from '@/types/models';

type NowItem = {
    key: string;
    kind: ItemKind;
    title: string;
    description: string | null;
    two_minute_version: string;
    is_active: boolean;
    focus_started_at: string | null;
    objective: { key: string; title: string };
    plan: { id: number; title: string };
    milestone: { key: string; title: string } | null;
    prerequisite: ItemRef | null;
    unlocks: ItemRef[];
    url: string;
};

type Props = {
    now: NowItem | null;
    restart: boolean;
    habits: { scheduled: NowHabit[]; resting: RestingHabit[] };
    priority: { title: string; url: string } | null;
    today: string;
    server_now: string;
    /** The owner already closed something for today (UTC-6). */
    closed_today: boolean;
    cue: { minutes: number; visible_seconds: number };
};

/** What the card is showing besides the task itself. */
type Panel = 'none' | 'stuck' | 'two-minutes';

type JustDone = {
    item: ItemRef;
    objectiveKey: string;
    prerequisite: ItemRef | null;
    unlocks: ItemRef[];
    unlockedKeys: string[];
    milestone: { key: string; title: string } | null;
    motion: 'full' | 'reduce';
};

/**
 * Screen 2, "Ahora" (mockup visual/screens/02-now.html): ONE task — the
 * active one or one suggestion — whose 2-minute version is the primary
 * action, what finishing it opens, today's habit marks and the weekly
 * priority it comes from. The silent 25-minute cue, "Estoy trabado", the
 * unlock moment after "Marcar hecho" and the "Hoy retomás" restart all
 * happen inside this same card. No list of tasks, feed or pending counter.
 */
export default function Now({
    now,
    restart,
    habits,
    priority,
    today,
    server_now: serverNow,
    closed_today: closedToday,
    cue: cueSettings,
}: Props) {
    const [panel, setPanel] = useState<Panel>('none');
    const [justDone, setJustDone] = useState<JustDone | null>(null);
    const [closedForToday, setClosedForToday] = useState(
        closedToday && !now?.is_active,
    );
    const [captureMissing, setCaptureMissing] = useState(false);
    const cue = useFocusCue(
        now?.is_active ? now.focus_started_at : null,
        serverNow,
        cueSettings,
    );

    const markDone = useCallback(() => {
        if (now === null) {
            return;
        }

        if (now.kind === 'milestone') {
            // The summit (screen 12) is the milestone completion moment:
            // it collects the evidence there, not inline on Now.
            router.visit(itemSummit([now.objective.key, now.key]).url);

            return;
        }

        complete(now, {}, setJustDone, () => setPanel('none'));
    }, [now]);

    const openStuck = useCallback(() => setPanel('stuck'), []);

    const startOrContinue = useCallback(() => {
        if (now === null) {
            return;
        }

        if (now.is_active) {
            setPanel((current) =>
                current === 'two-minutes' ? 'none' : 'two-minutes',
            );

            return;
        }

        router.post(
            itemStart([now.objective.key, now.key]).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setClosedForToday(false);
                    toast('Empezaste.');
                },
            },
        );
    }, [now]);

    useNowShortcuts({
        enabled: now !== null && justDone === null && !closedForToday,
        onSpace: markDone,
        onEnter: startOrContinue,
        onStuck: openStuck,
    });

    return (
        <div className="mos-s02">
            <Head title="Ahora" />
            <div className="now-wrap">
                <p className="today">
                    <span>
                        <b>{formatLongDateCapitalized(today)}</b>
                    </span>
                    {now && justDone === null && (
                        <span>Objetivo: {now.objective.title}</span>
                    )}
                </p>

                {justDone ? (
                    <DoneMoment
                        done={justDone}
                        next={now}
                        onContinue={() => {
                            setJustDone(null);

                            if (now !== null && !now.is_active) {
                                router.post(
                                    itemStart([now.objective.key, now.key]).url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => toast('Empezaste.'),
                                    },
                                );
                            }
                        }}
                        onClose={() =>
                            closeForToday(justDone.item.key, () => {
                                setJustDone(null);
                                setClosedForToday(true);
                            })
                        }
                    />
                ) : closedForToday ? (
                    <ClosedForToday onReopen={() => setClosedForToday(false)} />
                ) : now === null ? (
                    <EmptyNow
                        captureMissing={captureMissing}
                        onCapture={() => {
                            const event = new CustomEvent('marcos:capture', {
                                cancelable: true,
                            });

                            // The quick-capture overlay (capture-inbox,
                            // Phase 7) handles this event and cancels it.
                            if (window.dispatchEvent(event)) {
                                setCaptureMissing(true);
                            }
                        }}
                    />
                ) : (
                    <article className="now" aria-labelledby="now-task">
                        {now.is_active && (
                            <div className="cue" aria-hidden="true">
                                <i
                                    style={{
                                        width: `${(cue.fill * 100).toFixed(2)}%`,
                                    }}
                                />
                            </div>
                        )}
                        {cue.visible && (
                            <FocusCueNote
                                minutes={cueSettings.minutes}
                                visibleSeconds={cueSettings.visible_seconds}
                                onContinue={cue.dismiss}
                                onDone={markDone}
                                onStuck={openStuck}
                            />
                        )}
                        <div className="ctxrow">
                            <span className="ctx">
                                {restart
                                    ? 'Hoy retomás.'
                                    : `${now.is_active ? '' : 'Sugerida para hoy. '}${contextLine(now)}`}
                            </span>
                            {now.is_active && now.focus_started_at && (
                                <span className="ctx tnum">
                                    En esto desde{' '}
                                    {since(now.focus_started_at, today)}
                                </span>
                            )}
                        </div>
                        <h1 className="task" id="now-task">
                            {now.title}
                        </h1>
                        {now.description && (
                            <p className="desc">{now.description}</p>
                        )}
                        <button
                            className="chip2"
                            type="button"
                            onClick={startOrContinue}
                            aria-expanded={
                                now.is_active
                                    ? panel === 'two-minutes'
                                    : undefined
                            }
                        >
                            <span className="mini" aria-hidden="true" />
                            <span>
                                {restart
                                    ? 'Retomar con 2 minutos'
                                    : now.is_active
                                      ? '2 min'
                                      : 'Empezar los 2 minutos'}
                                : {now.two_minute_version}
                            </span>
                            <kbd>Enter</kbd>
                        </button>

                        {panel === 'two-minutes' && (
                            <TwoMinutesDone
                                onContinue={() => setPanel('none')}
                                onCloseForToday={() =>
                                    closeForToday(now.key, () => {
                                        setPanel('none');
                                        setClosedForToday(true);
                                    })
                                }
                            />
                        )}
                        {panel === 'stuck' && (
                            <StuckFallback
                                item={now}
                                onClose={() => setPanel('none')}
                            />
                        )}
                        <Opens item={now} />

                        <div className="actions">
                            <button
                                className="btn btn-primary"
                                type="button"
                                onClick={markDone}
                                data-testid="mark-done"
                            >
                                <span>Marcar hecho</span>
                                <kbd>Espacio</kbd>
                            </button>
                            <button
                                className="btn btn-outline"
                                type="button"
                                onClick={openStuck}
                                aria-expanded={panel === 'stuck'}
                                data-testid="stuck-button"
                            >
                                <span>Estoy trabado</span>
                                <kbd>T</kbd>
                            </button>
                        </div>
                    </article>
                )}

                <NowHabitsRow
                    scheduled={habits.scheduled}
                    resting={habits.resting}
                />

                {priority && (
                    <p className="prio">
                        Prioridad de la semana:{' '}
                        <Link href={priority.url}>{priority.title}</Link>
                    </p>
                )}
            </div>
        </div>
    );
}

Now.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

/**
 * "Cerrar por hoy" on the item the owner is looking at: stops it if active
 * and dismisses it for the rest of the UTC-6 day (a just-done item only
 * closes the day; the newly unlocked one is never dismissed) (persisted, so a reload does not re-suggest it).
 */
function closeForToday(itemKey: string, onSuccess: () => void): void {
    router.post(
        closeForTodayRoute().url,
        { item: itemKey },
        { preserveScroll: true, onSuccess },
    );
}

/**
 * "las 09:12" when the focus started today (Guatemala), else "el jue 24,
 * 09:12" so a task left active for days never shows a stale bare time.
 */
function since(startedAt: string, today: string): string {
    if (todayIso(new Date(startedAt)) === today) {
        return `las ${formatTime(startedAt)}`;
    }

    const weekday = new Date(startedAt)
        .toLocaleDateString('es', {
            timeZone: MARCOS_TIME_ZONE,
            weekday: 'short',
            day: 'numeric',
        })
        .replace(',', '')
        .replace('.', '');

    return `el ${weekday}, ${formatTime(startedAt)}`;
}

/** "Hito: …" when the task leads to an open milestone, else its plan. */
function contextLine(item: NowItem): string {
    if (item.kind === 'milestone') {
        return `Hito del plan ${item.plan.title}`;
    }

    return item.milestone
        ? `Hito: ${item.milestone.title}`
        : `Plan: ${item.plan.title}`;
}

/**
 * Check the item exactly like "Marcar hecho" anywhere (`CheckItem`), then
 * switch the card to the unlock moment with what just opened.
 */
function complete(
    item: NowItem,
    data: { evidence?: string; link?: string },
    onDone: (done: JustDone) => void,
    onFinish: () => void,
    onError?: (errors: Record<string, string>) => void,
): void {
    router.post(itemCheck([item.objective.key, item.key]).url, data, {
        preserveScroll: true,
        onSuccess: (page) => {
            const flash = page.props.flash as {
                unlocked?: { key: string; title: string }[];
            };
            const unlockedKeys = (flash.unlocked ?? []).map(
                (unlocked) => unlocked.key,
            );

            onDone({
                item: { key: item.key, title: item.title, state: 'done' },
                objectiveKey: item.objective.key,
                prerequisite: item.prerequisite,
                unlocks: item.unlocks.map((unlock) =>
                    unlockedKeys.includes(unlock.key)
                        ? { ...unlock, state: 'available' }
                        : unlock,
                ),
                unlockedKeys,
                milestone: item.milestone,
                motion: prefersReducedMotion() ? 'reduce' : 'full',
            });
            toast(`Hecho: ${item.title}`, {
                action: {
                    label: 'Deshacer',
                    onClick: () =>
                        router.post(
                            itemUncheck([item.objective.key, item.key]).url,
                            {},
                            { preserveScroll: true },
                        ),
                },
            });
        },
        onError: (errors) => onError?.(errors as Record<string, string>),
        onFinish,
    });
}

function prefersReducedMotion(): boolean {
    return (
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/** "Esto abre": the mini-map for the active task, chips for a suggestion. */
function Opens({ item }: { item: NowItem }) {
    if (item.is_active) {
        return (
            <>
                <p className="opens-h">Esto abre</p>
                <NowMiniMap
                    self={{ key: item.key, title: item.title, state: 'active' }}
                    selfLabel="Ahora"
                    prerequisite={item.prerequisite}
                    unlocks={item.unlocks}
                    milestone={item.milestone}
                />
                {item.unlocks.length === 0 && (
                    <p className="opens">No abre otra tarea por ahora.</p>
                )}
            </>
        );
    }

    return (
        <p className="opens">
            {item.unlocks.length === 0 ? (
                'No abre otra tarea por ahora.'
            ) : (
                <>
                    Esto abre:{' '}
                    <span className="node-mini">{item.unlocks[0].title}</span>
                    {item.unlocks.length > 1 && (
                        <span className="node-mini">
                            +{item.unlocks.length - 1} en paralelo
                        </span>
                    )}
                </>
            )}
        </p>
    );
}

/**
 * The 2 minutes are done (design proposal §2): "Seguir" and "Cerrar por
 * hoy" with the same weight — both are wins.
 */
function TwoMinutesDone({
    onContinue,
    onCloseForToday,
}: {
    onContinue: () => void;
    onCloseForToday: () => void;
}) {
    return (
        <div className="stuck-result" data-testid="two-minutes-done">
            <p>
                <b>Dos minutos hechos.</b> ¿Seguís con la tarea o cerrás por
                hoy?
            </p>
            <div className="btns">
                <button
                    className="btn btn-outline"
                    type="button"
                    onClick={onContinue}
                >
                    Seguir
                </button>
                <button
                    className="btn btn-outline"
                    type="button"
                    onClick={onCloseForToday}
                >
                    Cerrar por hoy
                </button>
            </div>
        </div>
    );
}

/**
 * "Estoy trabado" without AI (now-focus): ask for ONE smaller physical
 * action; "Probar esta" makes it the new 2-minute version and keeps the
 * previous one in the item's history.
 */
function StuckFallback({
    item,
    onClose,
}: {
    item: NowItem;
    onClose: () => void;
}) {
    const form = useForm({ two_minute_version: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(itemTwoMinute([item.objective.key, item.key]).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast('Listo: esa es tu nueva versión de 2 minutos.');
                onClose();
            },
        });
    };

    return (
        <form
            className="stuck-result"
            onSubmit={submit}
            aria-labelledby="stuck-h"
            data-testid="stuck-fallback"
        >
            <p id="stuck-h">
                <b>Más chico:</b> ¿cuál es la acción física más chica que podés
                hacer ahora? Algo de 2 minutos o menos.
            </p>
            <label className="sr" htmlFor="stuck-action">
                Acción física más chica
            </label>
            <input
                id="stuck-action"
                name="two_minute_version"
                className="in"
                maxLength={255}
                // The owner asked for this: moving focus here is theirs, not
                // the system stealing it.
                autoFocus
                value={form.data.two_minute_version}
                onChange={(event) =>
                    form.setData('two_minute_version', event.target.value)
                }
                placeholder={`Más chico que «${item.two_minute_version}»`}
                aria-invalid={form.errors.two_minute_version ? true : undefined}
                aria-describedby={
                    form.errors.two_minute_version ? 'stuck-error' : undefined
                }
            />
            {(form.errors.two_minute_version ||
                (form.errors as Record<string, string>).item) && (
                <p className="msg-attn" id="stuck-error">
                    {form.errors.two_minute_version ??
                        (form.errors as Record<string, string>).item}
                </p>
            )}
            <div className="btns">
                <button
                    className="btn btn-outline"
                    type="submit"
                    disabled={
                        form.processing ||
                        form.data.two_minute_version.trim() === ''
                    }
                >
                    Probar esta
                </button>
                <button
                    className="btn btn-outline"
                    type="button"
                    onClick={onClose}
                >
                    Ahora no
                </button>
            </div>
            <p className="src">
                Sin IA en este momento: la escribís vos. Si la probás, reemplaza
                la versión de 2 minutos y la anterior queda en el historial.
            </p>
        </form>
    );
}

/**
 * "Recién marcada" (mockup 02): the mark is painted, the segment is traced
 * and the next station opens; then "Seguir con la próxima" or "Cerrar por
 * hoy", same weight.
 */
function DoneMoment({
    done,
    next,
    onContinue,
    onClose,
}: {
    done: JustDone;
    next: NowItem | null;
    onContinue: () => void;
    onClose: () => void;
}) {
    const opened = done.unlocks.filter((unlock) =>
        done.unlockedKeys.includes(unlock.key),
    );

    return (
        <section
            className="done-moment"
            aria-labelledby="done-h"
            data-motion={done.motion}
            data-testid="done-moment"
        >
            <h1 className="sr" id="done-h">
                Hecho: {done.item.title}
            </h1>
            <NowMiniMap
                self={done.item}
                selfLabel={null}
                prerequisite={done.prerequisite}
                unlocks={done.unlocks}
                unlockedKeys={[done.item.key, ...done.unlockedKeys]}
                milestone={done.milestone}
            />
            <p className="unlocked-note">
                <i aria-hidden="true" />
                {opened.length > 0
                    ? `Se abrió: ${opened.map((unlock) => unlock.title).join(', ')}.${next && !next.is_active && opened.some((unlock) => unlock.key === next.key) ? ' Queda sugerida para después.' : ''}`
                    : `Hecho: ${done.item.title}.`}
            </p>
            <div className="actions">
                <button
                    className="btn btn-outline"
                    type="button"
                    onClick={onContinue}
                >
                    {next ? 'Seguir con la próxima' : 'Seguir'}
                </button>
                <button
                    className="btn btn-outline"
                    type="button"
                    onClick={onClose}
                >
                    Cerrar por hoy
                </button>
            </div>
        </section>
    );
}

function ClosedForToday({ onReopen }: { onReopen: () => void }) {
    return (
        <div className="empty" data-testid="closed-for-today">
            <h2>Listo por hoy.</h2>
            <p>La próxima marca te espera acá cuando vuelvas.</p>
            <div className="actions">
                <button
                    className="btn btn-outline"
                    type="button"
                    onClick={onReopen}
                >
                    Ver la próxima
                </button>
            </div>
        </div>
    );
}

function EmptyNow({
    captureMissing,
    onCapture,
}: {
    captureMissing: boolean;
    onCapture: () => void;
}) {
    return (
        <div className="empty">
            <h2>No hay una marca pintada para hoy.</h2>
            <p>
                Capturá una idea o abrí un objetivo para elegir la próxima
                estación.
            </p>
            <div className="actions">
                <button
                    className="btn btn-primary"
                    type="button"
                    onClick={onCapture}
                >
                    Capturar una idea
                </button>
                <Link className="btn btn-outline" href={objectivesIndex()}>
                    Abrir Objetivos
                </Link>
            </div>
            {captureMissing && (
                <p className="small" style={{ margin: '12px 0 0' }}>
                    La bandeja de captura todavía no está disponible. Mientras
                    tanto, anotá la idea como tarea de un objetivo.
                </p>
            )}
        </div>
    );
}

/**
 * The mockup's keys: Espacio = Marcar hecho, Enter = the 2-minute chip,
 * T = Estoy trabado. Only when nothing interactive has focus, so typing in
 * a field or pressing a focused button keeps its native meaning.
 */
function useNowShortcuts({
    enabled,
    onSpace,
    onEnter,
    onStuck,
}: {
    enabled: boolean;
    onSpace: () => void;
    onEnter: () => void;
    onStuck: () => void;
}) {
    const handlers = useRef({ onSpace, onEnter, onStuck });

    useEffect(() => {
        handlers.current = { onSpace, onEnter, onStuck };
    }, [onSpace, onEnter, onStuck]);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (
                event.ctrlKey ||
                event.metaKey ||
                event.altKey ||
                event.defaultPrevented
            ) {
                return;
            }

            const target = event.target as HTMLElement | null;

            if (
                target?.closest(
                    'input, textarea, select, button, a, summary, [contenteditable="true"], [role="dialog"], [role="checkbox"]',
                )
            ) {
                return;
            }

            if (event.key === ' ') {
                event.preventDefault();
                handlers.current.onSpace();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                handlers.current.onEnter();
            } else if (event.key.toLowerCase() === 't') {
                event.preventDefault();
                handlers.current.onStuck();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [enabled]);
}
