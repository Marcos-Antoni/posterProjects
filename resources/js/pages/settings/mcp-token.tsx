import { Form, Head, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound, RefreshCw, TriangleAlert } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';

import { store } from '@/actions/App/Http/Controllers/Settings/McpTokenController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings-layout';
import { formatMoment } from '@/lib/dates';

type McpTokenProps = {
    token: {
        created_at: string | null;
        last_used_at: string | null;
    } | null;
};

/**
 * Settings → Token MCP (mockup 27). Same behavior as before: a single
 * token, its plain text arriving only via the one-shot
 * `flash.plainMcpToken` shared prop — after any navigation it is gone for
 * good, so the page asks to copy it right away, framed with the blaze
 * border (the only moment on the screen that asks for attention).
 */
export default function McpToken({ token }: McpTokenProps) {
    const { props } = usePage();
    const plainToken = props.flash.plainMcpToken;
    const mcpUrl = `${typeof window === 'undefined' ? '' : window.location.origin}/mcp`;

    const [copied, setCopied] = useState(false);

    const copyToken = async () => {
        if (!plainToken) {
            return;
        }

        await navigator.clipboard.writeText(plainToken);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <>
            <Head title="Token MCP" />

            <h1 className="mb-2 text-2xl font-bold tracking-[-0.01em]">
                Token MCP
            </h1>
            <p className="mb-7 max-w-[68ch] text-muted-foreground">
                Conecta Claude Code o Claude Desktop con Marcos OS. Hay un solo
                token a la vez: si generás uno nuevo, el anterior deja de
                funcionar en ese momento.
            </p>

            {plainToken && (
                <div
                    role="status"
                    className="mb-5 rounded-md border-2 border-blaze-ink bg-[color-mix(in_srgb,var(--blaze)_14%,var(--surface))] p-5"
                >
                    <h2 className="flex items-center gap-2.5 text-[17px] leading-6 font-bold">
                        <TriangleAlert
                            className="size-5 shrink-0"
                            strokeWidth={1.75}
                            aria-hidden="true"
                        />
                        Copiá tu token ahora
                    </h2>
                    <p className="mt-1 mb-3 text-sm text-muted-foreground">
                        No lo vas a volver a ver: al salir de esta página
                        desaparece. Si lo perdés, generás otro.
                    </p>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <label htmlFor="mcp-token" className="sr-only">
                            Token MCP nuevo
                        </label>
                        <Input
                            id="mcp-token"
                            readOnly
                            value={plainToken}
                            className="min-w-0 font-semibold tracking-[0.01em]"
                            onFocus={(event) => event.target.select()}
                        />
                        <Button type="button" onClick={copyToken}>
                            {copied ? <Check /> : <Copy />}
                            {copied ? 'Copiado' : 'Copiar'}
                        </Button>
                    </div>
                </div>
            )}

            <section
                aria-labelledby="mcp-status-heading"
                className="rounded-md border border-border bg-surface p-6"
            >
                <h2 id="mcp-status-heading" className="sr-only">
                    Estado del token
                </h2>
                <div className="flex items-start gap-3.5">
                    <span className="grid size-10 shrink-0 place-items-center rounded-md bg-sunken text-foreground">
                        <KeyRound
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
                                Sin token todavía
                            </b>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Todavía no generaste un token.
                            </p>
                        </div>
                    )}
                </div>

                <Form {...store.form()}>
                    {({ processing }) => (
                        <div className="mt-5 flex flex-wrap items-center gap-x-4 gap-y-3">
                            <Button
                                type="submit"
                                variant={token ? 'outline' : 'default'}
                                disabled={processing}
                            >
                                <RefreshCw />
                                {processing
                                    ? 'Generando…'
                                    : token
                                      ? 'Regenerar token'
                                      : 'Generar token'}
                            </Button>
                            {token && (
                                <p className="max-w-[44ch] text-sm text-muted-foreground">
                                    {plainToken
                                        ? 'Al regenerar, este token queda revocado y cualquier cliente que lo use deja de conectarse.'
                                        : 'El token no se puede volver a mostrar. Si lo necesitás en otro cliente, regeneralo.'}
                                </p>
                            )}
                        </div>
                    )}
                </Form>
            </section>

            <section
                aria-labelledby="mcp-how-heading"
                className="mt-5 rounded-md border border-border bg-surface p-6"
            >
                <h2
                    id="mcp-how-heading"
                    className="mb-1 text-lg leading-[26px] font-bold"
                >
                    Conectarlo a Claude Code
                </h2>
                <p className="text-sm text-muted-foreground">
                    En la terminal del VPS o de tu compu, reemplazando el token:
                </p>
                <p className="mt-3 rounded-sm bg-sunken px-3.5 py-3 text-sm leading-[22px] [overflow-wrap:anywhere]">
                    <code>
                        claude mcp add --transport http poster {mcpUrl} --header
                        "Authorization: Bearer &lt;token&gt;"
                    </code>
                </p>
            </section>
        </>
    );
}

McpToken.layout = (page: ReactElement) => (
    <AppLayout>
        <SettingsLayout>{page}</SettingsLayout>
    </AppLayout>
);
