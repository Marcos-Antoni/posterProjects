import { useHttp } from '@inertiajs/react';
import { Check, QrCode } from 'lucide-react';
import { toString as qrCodeToString } from 'qrcode';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { formatMoment } from '@/lib/dates';
import type { QrPassMint, QrPassStatus } from '@/types';

type CardState = 'idle' | 'minting' | 'live' | 'consumed' | 'expired';

const MINT_URL = '/settings/mobile-token/qr';
const STATUS_URL = '/settings/mobile-token/qr/status';
const POLL_INTERVAL_MS = 3000;
const RE_MINT_WINDOW_SECONDS = 10;

const secondsUntil = (isoDate: string) =>
    Math.max(0, Math.ceil((new Date(isoDate).getTime() - Date.now()) / 1000));

/**
 * QR login pass card. Default state is a bare button — no mint, no poll,
 * no `qrcode` import work happens until the owner explicitly clicks it, so
 * a drive-by visit or prefetch never mints a credential and
 * `MobileTokenRevokeFlowTest` sees an unchanged default page (design.md).
 *
 * The plaintext payload arrives once, in the mint response, and is never
 * held in its own state — it is converted to an SVG string immediately
 * and discarded. It never rides on a prop (props survive back-navigation
 * via `window.history.state`) and is never re-fetchable (`…/qr/status`
 * never returns it).
 */
export default function QrLoginCard() {
    const [state, setState] = useState<CardState>('idle');
    const [qrSvg, setQrSvg] = useState<string | null>(null);
    const [expiresAt, setExpiresAt] = useState<string | null>(null);
    const [secondsLeft, setSecondsLeft] = useState(0);
    const [lifetime, setLifetime] = useState(0);
    const [consumedAt, setConsumedAt] = useState<string | null>(null);
    const [consumedIp, setConsumedIp] = useState<string | null>(null);

    const mintHttp = useHttp<{ acknowledge_consumed: boolean }, QrPassMint>({
        acknowledge_consumed: false,
    });
    const statusHttp = useHttp<Record<string, never>, QrPassStatus>({});

    // Re-mint is poll-driven, not timer-driven (design.md "The Web Page"):
    // it only fires in reaction to a `status` response that is still
    // `live` and within `RE_MINT_WINDOW_SECONDS` of expiry.
    const mintingRef = useRef(false);

    const applyMintResponse = (response: QrPassMint) => {
        if (response.state === 'consumed') {
            setState('consumed');
            setQrSvg(null);
            setConsumedAt(response.consumed_at);
            setConsumedIp(response.consumed_ip);

            return;
        }

        setExpiresAt(response.expires_at);
        setLifetime(secondsUntil(response.expires_at));
        setState('live');

        qrCodeToString(response.payload, { type: 'svg' }).then(setQrSvg);
    };

    // `acknowledgeConsumed` must only ever be `true` when the owner
    // deliberately clicks past the consumed notice (see the button in the
    // `consumed` branch below). Every other caller — the idle/expired
    // buttons and the poll-driven re-mint — calls `mint()` with no
    // argument, so a poll, a prefetch, or a page refresh can never send it.
    const mint = async (acknowledgeConsumed = false) => {
        if (mintingRef.current) {
            return;
        }

        mintingRef.current = true;
        setState('minting');
        mintHttp.setData('acknowledge_consumed', acknowledgeConsumed);

        try {
            const response = await mintHttp.post(MINT_URL);
            applyMintResponse(response);
        } finally {
            mintingRef.current = false;
        }
    };

    // Live 1-second countdown, purely local — separate from the network
    // poll below. Flips to `expired` if the server's re-mint (triggered
    // by the poll, not by this timer) hasn't arrived by T-0. The expiry
    // check happens inside `tick()` itself, against the value it just
    // computed — never against the `secondsLeft` state variable, which
    // would still hold its stale pre-tick value on the render that just
    // transitioned into `live` and cause an immediate false expiry.
    useEffect(() => {
        if (state !== 'live' || expiresAt === null) {
            return;
        }

        const tick = () => {
            const remaining = secondsUntil(expiresAt);
            setSecondsLeft(remaining);

            if (remaining <= 0) {
                setState('expired');
            }
        };

        tick();

        const timer = window.setInterval(tick, 1000);

        return () => window.clearInterval(timer);
    }, [state, expiresAt]);

    // Status poll: stops on any terminal state (consumed/expired), and is
    // the only thing allowed to trigger a re-mint (never this timer).
    useEffect(() => {
        if (state !== 'live') {
            return;
        }

        const poll = window.setInterval(async () => {
            const response = await statusHttp.get(STATUS_URL);

            if (response.state === 'consumed') {
                setState('consumed');
                setQrSvg(null);
                setConsumedAt(response.consumed_at);
                setConsumedIp(response.consumed_ip);

                return;
            }

            if (response.state === 'expired' || response.state === 'none') {
                setState('expired');

                return;
            }

            if (secondsUntil(response.expires_at) <= RE_MINT_WINDOW_SECONDS) {
                await mint();
            }
        }, POLL_INTERVAL_MS);

        return () => window.clearInterval(poll);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [state]);

    return (
        <section
            aria-labelledby="qr-heading"
            className="rounded-md border border-border bg-surface p-6"
        >
            <h2
                id="qr-heading"
                className="mb-1 text-lg leading-[26px] font-bold"
            >
                Iniciar sesión con QR
            </h2>
            <QrCardBody
                state={state}
                qrSvg={qrSvg}
                secondsLeft={secondsLeft}
                lifetime={lifetime}
                consumedAt={consumedAt}
                consumedIp={consumedIp}
                onMint={mint}
            />
        </section>
    );
}

type QrCardBodyProps = {
    state: CardState;
    qrSvg: string | null;
    secondsLeft: number;
    lifetime: number;
    consumedAt: string | null;
    consumedIp: string | null;
    onMint: (acknowledgeConsumed?: boolean) => void;
};

/**
 * Card states. No countdown in seconds (mockup 28, COGA: no time
 * pressure): a thin ochre line shows the code's remaining life and the
 * copy explains it renews itself while the page is open.
 */
function QrCardBody({
    state,
    qrSvg,
    secondsLeft,
    lifetime,
    consumedAt,
    consumedIp,
    onMint,
}: QrCardBodyProps) {
    if (state === 'idle' || state === 'expired') {
        return (
            <div className="mt-3 flex flex-col items-start gap-3">
                <p className="text-sm text-muted-foreground">
                    {state === 'expired'
                        ? 'El código venció. Mostrá uno nuevo para escanearlo.'
                        : 'El código se genera solo cuando lo pedís y vale poco tiempo.'}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => onMint()}
                >
                    <QrCode />
                    Mostrar código QR
                </Button>
            </div>
        );
    }

    if (state === 'consumed') {
        return (
            <div className="mt-3 flex flex-col items-start gap-4" role="status">
                <div className="flex items-start gap-3.5">
                    <span className="grid size-10 shrink-0 place-items-center rounded-md bg-sunken text-primary">
                        <Check
                            className="size-5"
                            strokeWidth={1.75}
                            aria-hidden="true"
                        />
                    </span>
                    <div>
                        <b className="block font-semibold">
                            Se inició sesión en un teléfono
                        </b>
                        <p className="text-sm text-muted-foreground">
                            {consumedAt ? formatMoment(consumedAt) : 'Recién'}
                            {consumedIp ? `, desde ${consumedIp}` : ''}.
                        </p>
                    </div>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => onMint(true)}
                >
                    <QrCode />
                    Regenerar código QR
                </Button>
            </div>
        );
    }

    const remaining = lifetime > 0 ? Math.min(1, secondsLeft / lifetime) : 1;

    return (
        <div className="mt-3 grid items-center gap-7 sm:grid-cols-[auto_1fr]">
            <div className="rounded-md border border-border bg-[#FAFBF9] p-2.5 leading-none">
                {qrSvg ? (
                    <div
                        data-testid="qr-code"
                        role="img"
                        aria-label="Código QR para iniciar sesión en la app"
                        className="size-40"
                        dangerouslySetInnerHTML={{ __html: qrSvg }}
                    />
                ) : (
                    <div className="size-40 animate-pulse rounded-sm bg-[#E3E9E5]" />
                )}
            </div>
            <div>
                <ol className="mt-3 list-decimal pl-5 text-[15px] leading-6">
                    <li>Abrí Poster en el teléfono.</li>
                    <li>Tocá “Entrar con código QR”.</li>
                    <li>Apuntá la cámara a este código.</li>
                </ol>
                {state === 'live' && (
                    <p className="mt-4 flex items-center gap-2 text-sm text-muted-foreground">
                        <span
                            aria-hidden="true"
                            className="relative block h-[3px] w-[120px] overflow-hidden rounded-xs bg-sunken"
                        >
                            <span
                                className="absolute inset-y-0 left-0 bg-blaze-ink"
                                style={{ width: `${remaining * 100}%` }}
                            />
                        </span>
                        Vale poco tiempo y se renueva solo mientras esta página
                        esté abierta.
                    </p>
                )}
            </div>
        </div>
    );
}
