import { Form, Head, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';

import { store } from '@/actions/App/Http/Controllers/Settings/McpTokenController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';

type McpTokenProps = {
    token: {
        created_at: string | null;
        last_used_at: string | null;
    } | null;
};

/**
 * Settings page for the single MCP personal access token. The plain-text
 * token only ever arrives via the one-shot `flash.plainMcpToken` shared
 * prop — after any navigation it is gone for good, so the page pushes
 * the user to copy it immediately.
 */
export default function McpToken({ token }: McpTokenProps) {
    const { props } = usePage();
    const plainToken = props.flash.plainMcpToken;

    const [copied, setCopied] = useState(false);

    const copyToken = async () => {
        if (!plainToken) {
            return;
        }

        await navigator.clipboard.writeText(plainToken);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const formatDate = (value: string | null) =>
        value
            ? new Date(value).toLocaleString('es', {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              })
            : null;

    return (
        <>
            <Head title="Token MCP" />

            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
                <div>
                    <h1 className="font-heading text-2xl font-medium">
                        Token MCP
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Conectá Claude Code o Claude Desktop al servidor MCP de
                        esta app con un token personal. Solo puede existir un
                        token a la vez: al generar uno nuevo, el anterior deja
                        de funcionar al instante.
                    </p>
                </div>

                {plainToken && (
                    <div className="flex flex-col gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-4">
                        <p className="text-sm font-medium">
                            Copiá tu token ahora — no lo vas a volver a ver.
                        </p>
                        <div className="flex items-center gap-2">
                            <Input
                                readOnly
                                value={plainToken}
                                className="font-mono text-xs"
                                onFocus={(event) => event.target.select()}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={copyToken}
                            >
                                {copied ? (
                                    <Check className="size-4" />
                                ) : (
                                    <Copy className="size-4" />
                                )}
                                {copied ? 'Copiado' : 'Copiar'}
                            </Button>
                        </div>
                    </div>
                )}

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
                                Todavía no generaste un token.
                            </p>
                        )}
                    </div>

                    <Form {...store.form()}>
                        {({ processing }) => (
                            <div className="flex flex-col gap-2">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="self-start"
                                >
                                    {token ? 'Regenerar token' : 'Generar token'}
                                </Button>
                                {token && (
                                    <p className="text-xs text-muted-foreground">
                                        Al regenerar, el token actual queda
                                        revocado y cualquier cliente que lo use
                                        va a dejar de autenticar.
                                    </p>
                                )}
                            </div>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

McpToken.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
