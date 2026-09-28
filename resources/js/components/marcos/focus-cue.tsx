import { useState, useSyncExternalStore } from 'react';

type CueSettings = { minutes: number; visible_seconds: number };

/**
 * The silent 25-minute cue (now-focus, design D7), computed locally from the
 * persisted focus start and the server clock — no polling, no audio, no
 * Notifications API. Returns how full the current tramo is and whether the
 * cue is showing: from each full tramo until `visible_seconds` later, unless
 * "Sigo" dismissed that tramo. A reload recomputes from the same start, so
 * the clock never resets.
 */
export function useFocusCue(
    startedAt: string | null,
    serverNow: string,
    settings: CueSettings,
) {
    // Server minus browser clock, fixed when the page mounts: the cue
    // follows the server's notion of "since when", whatever the browser
    // clock says.
    const [offset] = useState(() => Date.parse(serverNow) - Date.now());
    const second = useSyncExternalStore(
        subscribeToSeconds,
        currentSecond,
        () => 0,
    );
    const storageKey = startedAt ? `mos-cue-dismissed:${startedAt}` : null;
    const [dismissal, setDismissal] = useState<{
        key: string | null;
        tramo: number;
    } | null>(null);

    if (startedAt === null) {
        return { fill: 0, visible: false, tramo: 0, dismiss: () => {} };
    }

    const dismissed =
        dismissal !== null && dismissal.key === storageKey
            ? dismissal.tramo
            : readDismissed(storageKey);
    const now = second * 1000 + offset;
    const tramoMs = Math.max(1, settings.minutes) * 60_000;
    const elapsed = Math.max(0, now - Date.parse(startedAt));
    const tramo = Math.floor(elapsed / tramoMs);
    const into = elapsed - tramo * tramoMs;
    const inWindow = tramo >= 1 && into < settings.visible_seconds * 1000;
    const visible = inWindow && dismissed < tramo;

    return {
        fill: inWindow ? 1 : into / tramoMs,
        visible,
        tramo,
        dismiss: () => {
            setDismissal({ key: storageKey, tramo });

            try {
                if (storageKey) {
                    window.sessionStorage.setItem(storageKey, String(tramo));
                }
            } catch {
                // Storage can be unavailable (private mode): the dismissal
                // then lasts for this page only, which is still correct.
            }
        },
    };
}

/** A one-second clock as an external store (no re-render storms, no polling of the server). */
function subscribeToSeconds(onTick: () => void): () => void {
    const timer = window.setInterval(onTick, 1000);

    return () => window.clearInterval(timer);
}

function currentSecond(): number {
    return Math.floor(Date.now() / 1000);
}

function readDismissed(key: string | null): number {
    if (key === null) {
        return 0;
    }

    try {
        return Number(window.sessionStorage.getItem(key) ?? 0) || 0;
    } catch {
        return 0;
    }
}

/**
 * The cue itself, inside the Now card (mockup 02, "A los 25 minutos"): a dot,
 * one line of muted text and three labeled text buttons in normal tab order.
 * It is deliberately NOT a live region (`aria-live="off"`, no
 * `role="status"`) and never takes focus.
 */
export function FocusCueNote({
    minutes,
    visibleSeconds,
    onContinue,
    onDone,
    onStuck,
}: {
    minutes: number;
    visibleSeconds: number;
    onContinue: () => void;
    onDone: () => void;
    onStuck: () => void;
}) {
    return (
        <div
            className="cue-note"
            role="group"
            aria-label={`Aviso de ${minutes} minutos`}
            aria-live="off"
            data-testid="focus-cue"
        >
            <span className="dot" aria-hidden="true" />
            {minutes} min. ¿Seguís en esto?
            <button
                type="button"
                aria-label="Sigo: ocultar el aviso hasta el próximo tramo"
                onClick={onContinue}
            >
                Sigo
            </button>
            <button
                type="button"
                aria-label="Terminé: marcar hecho"
                onClick={onDone}
            >
                Terminé
            </button>
            <button
                type="button"
                aria-label="Estoy trabado: pedir una acción más chica"
                onClick={onStuck}
            >
                Estoy trabado
            </button>
            <span className="gone">
                Se va solo{' '}
                {visibleSeconds === 60
                    ? 'en un minuto'
                    : `en ${visibleSeconds} segundos`}{' '}
                si no tocás nada.
            </span>
        </div>
    );
}
