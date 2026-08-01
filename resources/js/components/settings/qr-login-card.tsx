import { useHttp } from '@inertiajs/react';
import { QrCode } from 'lucide-react';
import { toString as qrCodeToString } from 'qrcode';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import type { QrPassMint, QrPassStatus } from '@/types';

type CardState = 'idle' | 'minting' | 'live' | 'consumed' | 'expired';

const MINT_URL = '/settings/mobile-token/qr';
const STATUS_URL = '/settings/mobile-token/qr/status';
const POLL_INTERVAL_MS = 3000;
const RE_MINT_WINDOW_SECONDS = 10;

const formatDateTime = (value: string) =>
    new Date(value).toLocaleString('es', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

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
    const [consumedAt, setConsumedAt] = useState<string | null>(null);
    const [consumedIp, setConsumedIp] = useState<string | null>(null);

    const mintHttp = useHttp<Record<string, never>, QrPassMint>({});
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
        setState('live');

        qrCodeToString(response.payload, { type: 'svg' }).then(setQrSvg);
    };

    const mint = async () => {
        if (mintingRef.current) {
            return;
        }

        mintingRef.current = true;
        setState('minting');

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

    if (state === 'idle') {
        return (
            <div className="flex flex-col gap-4 rounded-lg border p-4">
                <Button
                    type="button"
                    variant="outline"
                    className="self-start"
                    onClick={mint}
                >
                    <QrCode />
                    Mostrar código QR
                </Button>
            </div>
        );
    }

    if (state === 'consumed') {
        return (
            <div className="flex flex-col gap-2 rounded-lg border p-4">
                <p className="text-sm font-medium">
                    Se inició sesión en un teléfono
                    {consumedAt ? ` a las ${formatDateTime(consumedAt)}` : ''}
                    {consumedIp ? ` desde ${consumedIp}` : ''}.
                </p>
            </div>
        );
    }

    if (state === 'expired') {
        return (
            <div className="flex flex-col gap-4 rounded-lg border p-4">
                <p className="text-sm text-muted-foreground">
                    El código expiró. Escaneá el nuevo.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    className="self-start"
                    onClick={mint}
                >
                    <QrCode />
                    Mostrar código QR
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col items-start gap-3 rounded-lg border p-4">
            {qrSvg ? (
                <div
                    data-testid="qr-code"
                    className="h-40 w-40"
                    dangerouslySetInnerHTML={{ __html: qrSvg }}
                />
            ) : (
                <div className="h-40 w-40 animate-pulse rounded bg-muted" />
            )}
            {state === 'live' && (
                <p className="text-sm text-muted-foreground">
                    El código expira en {secondsLeft} s
                </p>
            )}
        </div>
    );
}
