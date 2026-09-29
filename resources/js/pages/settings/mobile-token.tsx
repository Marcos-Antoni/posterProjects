import { Form, Head } from '@inertiajs/react';
import { ShieldOff, Smartphone } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';

import { destroy } from '@/actions/App/Http/Controllers/Settings/MobileTokenController';
import QrLoginCard from '@/components/settings/qr-login-card';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings-layout';
import { formatMoment } from '@/lib/dates';

type MobileTokenProps = {
    token: {
        created_at: string | null;
        last_used_at: string | null;
    } | null;
};

/**
 * Settings → App móvil y QR (mockup 28). This page never mints or displays
 * a plain-text *token*: the token is minted on the phone — via
 * `POST /api/v1/login` or by redeeming a short-lived QR *pass* at
 * `POST /api/v1/qr-login` — and its plaintext never reaches a browser
 * (design.md decision D-1). The QR card mints and briefly shows that pass,
 * gated behind an explicit click so a drive-by visit never mints one.
 * Below it: token status and the revoke action behind a confirmation
 * dialog — the only red on the screen, because revoking is a destructive
 * system action, not Marco's conduct.
 */
export default function MobileToken({ token }: MobileTokenProps) {
    const [confirmOpen, setConfirmOpen] = useState(false);

    return (
        <>
            <Head title="App móvil y QR" />

            <h1 className="mb-2 text-2xl font-bold tracking-[-0.01em]">
                App móvil y QR
            </h1>
            <p className="mb-7 max-w-[68ch] text-muted-foreground">
                La app Poster del teléfono inicia sesión con tu email y
                contraseña, o escaneando este código. El token del teléfono
                nunca se muestra acá: solo ves su estado y lo podés revocar.
            </p>

            <QrLoginCard />

            <section
                aria-labelledby="mobile-status-heading"
                className="mt-5 rounded-md border border-border bg-surface p-6"
            >
                <h2 id="mobile-status-heading" className="sr-only">
                    Token del teléfono
                </h2>
                <div className="flex items-start gap-3.5">
                    <span className="grid size-10 shrink-0 place-items-center rounded-md bg-sunken text-foreground">
                        <Smartphone
                            className="size-5"
                            strokeWidth={1.75}
                            aria-hidden="true"
                        />
                    </span>
                    {token ? (
                        <div>
                            <b className="block font-semibold">Token activo</b>
                            <dl className="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm">
                                <dt className="text-muted-foreground">
                                    Generado
                                </dt>
                                <dd>
                                    {token.created_at
                                        ? formatMoment(token.created_at)
                                        : '—'}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Último uso
                                </dt>
                                <dd>
                                    {token.last_used_at
                                        ? formatMoment(token.last_used_at)
                                        : 'sin usos todavía'}
                                </dd>
                            </dl>
                        </div>
                    ) : (
                        <div>
                            <b className="block font-semibold">
                                Sin teléfono conectado
                            </b>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Todavía no hay un token móvil activo.
                            </p>
                        </div>
                    )}
                </div>

                {token && (
                    <div className="mt-5 flex flex-wrap items-center gap-x-4 gap-y-3">
                        <Dialog
                            open={confirmOpen}
                            onOpenChange={setConfirmOpen}
                        >
                            <DialogTrigger asChild>
                                <Button type="button" variant="outline">
                                    <ShieldOff />
                                    Revocar token
                                </Button>
                            </DialogTrigger>

                            <DialogContent showCloseButton={false}>
                                <DialogHeader>
                                    <DialogTitle>
                                        ¿Revocar el token móvil?
                                    </DialogTitle>
                                    <DialogDescription>
                                        La app deja de funcionar al instante.
                                        Esto no se deshace: para volver a
                                        usarla, iniciás sesión otra vez desde el
                                        teléfono. Tus hábitos y tareas no se
                                        tocan.
                                    </DialogDescription>
                                </DialogHeader>

                                <Form
                                    {...destroy.form()}
                                    onSuccess={() => setConfirmOpen(false)}
                                >
                                    {({ processing }) => (
                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                >
                                                    Cancelar
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                {processing
                                                    ? 'Revocando…'
                                                    : 'Revocar token'}
                                            </Button>
                                        </DialogFooter>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                        <p className="max-w-[44ch] text-sm text-muted-foreground">
                            El teléfono deja de estar conectado y tenés que
                            volver a iniciar sesión ahí.
                        </p>
                    </div>
                )}
            </section>
        </>
    );
}

MobileToken.layout = (page: ReactElement) => (
    <AppLayout>
        <SettingsLayout>{page}</SettingsLayout>
    </AppLayout>
);
