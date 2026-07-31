import { Form, Head } from '@inertiajs/react';
import { KeyRound, ShieldOff } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';

import { destroy } from '@/actions/App/Http/Controllers/Settings/MobileTokenController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';

type MobileTokenProps = {
    token: {
        created_at: string | null;
        last_used_at: string | null;
    } | null;
};

/**
 * Settings page for the mobile app's personal access token. Unlike
 * `settings/mcp-token`, this page never mints or displays a plain-text
 * token: the token is minted on the phone via `POST /api/v1/login` and
 * its plaintext never reaches a browser (design.md decision D-1), so
 * there is no flash, no plaintext input, and no copy button here — only
 * status and a destructive revoke action behind an explicit confirm step.
 */
export default function MobileToken({ token }: MobileTokenProps) {
    const [confirmOpen, setConfirmOpen] = useState(false);

    const formatDate = (value: string | null) =>
        value
            ? new Date(value).toLocaleString('es', {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              })
            : null;

    return (
        <>
            <Head title="Token móvil" />

            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
                <div>
                    <h1 className="font-heading text-2xl font-medium">
                        Token móvil
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Este token lo genera la app móvil al iniciar sesión
                        con tu email y contraseña — nunca se muestra acá.
                        Desde esta página solo podés ver su estado y
                        revocarlo.
                    </p>
                </div>

                <div className="flex flex-col gap-4 rounded-lg border p-4">
                    <div className="flex items-center gap-3">
                        <KeyRound className="size-5 text-muted-foreground" />
                        {token ? (
                            <div className="text-sm">
                                <p className="font-medium">Token activo</p>
                                <p className="text-muted-foreground">
                                    Generado el {formatDate(token.created_at)}
                                    {token.last_used_at
                                        ? ` · último uso el ${formatDate(token.last_used_at)}`
                                        : ' · sin usos todavía'}
                                </p>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Todavía no hay un token móvil activo.
                            </p>
                        )}
                    </div>

                    {token && (
                        <Dialog
                            open={confirmOpen}
                            onOpenChange={setConfirmOpen}
                        >
                            <DialogTrigger asChild>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    className="self-start"
                                >
                                    <ShieldOff />
                                    Revocar token
                                </Button>
                            </DialogTrigger>

                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>
                                        ¿Revocar el token móvil?
                                    </DialogTitle>
                                    <DialogDescription>
                                        La app deja de autenticar de
                                        inmediato. Los tokens no expiran
                                        solos, así que esta acción no se
                                        puede deshacer — vas a necesitar
                                        iniciar sesión de nuevo desde el
                                        teléfono.
                                    </DialogDescription>
                                </DialogHeader>

                                <Form
                                    {...destroy.form()}
                                    onSuccess={() => setConfirmOpen(false)}
                                    className="mt-4"
                                >
                                    {({ processing }) => (
                                        <DialogFooter>
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
                    )}
                </div>
            </div>
        </>
    );
}

MobileToken.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
