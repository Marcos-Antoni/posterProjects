import { Form, Head } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useRef, useState } from 'react';

import { store } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useSyncAppearance } from '@/hooks/use-appearance';

/**
 * A stretch of trail (the only decoration on the login screen, mockup 01):
 * a walked segment in ochre, today's blaze painted, the way ahead thin and
 * grey. It anticipates the unlock-graph language without explaining it.
 */
function LoginTrail() {
    const locked = [250, 340, 420];

    return (
        <svg
            viewBox="0 0 520 64"
            role="img"
            aria-label="Un tramo de senda: una marca hecha, la marca de hoy y el camino por venir"
            className="block h-auto w-full max-w-[520px] overflow-visible"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path
                d="M40 32 L150 32"
                fill="none"
                stroke="var(--blaze-ink)"
                strokeWidth={7}
            />
            {[
                [150, 250],
                [250, 340],
                [340, 420],
                [420, 500],
            ].map(([from, to]) => (
                <path
                    key={from}
                    d={`M${from} 32 L${to} 32`}
                    fill="none"
                    stroke="var(--node-locked)"
                    strokeWidth={2.5}
                    opacity={0.85}
                />
            ))}
            <circle
                cx={40}
                cy={32}
                r={11}
                fill="var(--blaze)"
                stroke="var(--blaze-ink)"
                strokeWidth={2}
            />
            <path
                d="M34.9 32.2 l3.5 3.5 l6.8 -7.5"
                fill="none"
                stroke="var(--blaze-foreground)"
                strokeWidth={2.4}
            />
            <circle
                cx={150}
                cy={32}
                r={12.5}
                fill="var(--surface)"
                stroke="var(--blaze-ink)"
                strokeWidth={4.5}
            />
            <rect
                x={145.1}
                y={28.6}
                width={9.9}
                height={6.8}
                rx={1.5}
                fill="var(--blaze)"
                stroke="var(--blaze-ink)"
                strokeWidth={1.5}
            />
            {locked.map((cx) => (
                <circle
                    key={cx}
                    cx={cx}
                    cy={32}
                    r={5.5}
                    fill="var(--surface)"
                    stroke="var(--node-locked)"
                    strokeWidth={2}
                />
            ))}
            <circle
                cx={500}
                cy={32}
                r={9.5}
                fill="var(--surface)"
                stroke="var(--node-locked)"
                strokeWidth={2.5}
            />
        </svg>
    );
}

function SystemError({ title, detail }: { title: string; detail: string }) {
    return (
        <div
            role="alert"
            className="mb-5 flex items-start gap-3 rounded-sm border border-l-4 border-[color-mix(in_srgb,var(--destructive)_45%,var(--border))] border-l-destructive bg-[color-mix(in_srgb,var(--destructive)_7%,var(--surface))] px-3.5 py-3 text-[15px] leading-[22px]"
        >
            <CircleAlert
                className="mt-px size-5 shrink-0 text-destructive"
                strokeWidth={1.75}
                aria-hidden="true"
            />
            <span>
                <b className="block font-semibold">{title}</b>
                {detail}
            </span>
        </div>
    );
}

/**
 * Login (mockup 01): the owner's single account, no registration, one
 * primary action. The two possible errors are system errors (credentials,
 * no connection), so — and only so — they use the error color, say what
 * happened and what to do, keep the email and put the focus back on the
 * password.
 */
export default function Login() {
    useSyncAppearance();

    const passwordRef = useRef<HTMLInputElement>(null);
    const [showPassword, setShowPassword] = useState(false);
    const [offline, setOffline] = useState(false);

    return (
        <>
            <Head title="Entrar" />

            <main className="mx-auto grid min-h-svh max-w-[1120px] items-center gap-8 px-4 py-8 min-[880px]:grid-cols-[minmax(0,1.1fr)_minmax(360px,440px)] min-[880px]:gap-16 min-[880px]:px-8 min-[880px]:py-12">
                <section aria-labelledby="login-word">
                    <p
                        id="login-word"
                        className="mb-4 font-display text-[38px] leading-[44px] font-medium tracking-[-0.005em] min-[880px]:text-5xl min-[880px]:leading-[52px]"
                    >
                        Marcos OS
                    </p>
                    <p className="mb-2 max-w-[30ch] text-lg">
                        Al entrar ves una sola tarea y su versión de 2 minutos.
                    </p>
                    <p className="mb-6 max-w-[44ch] text-muted-foreground min-[880px]:mb-10">
                        Nada más que eso. El mapa completo está a un clic, pero
                        no te espera en la puerta.
                    </p>
                    <LoginTrail />
                </section>

                <section
                    aria-labelledby="login-title"
                    className="rounded-lg border border-border bg-surface p-6 shadow-elevation-1 sm:p-8"
                >
                    <h1
                        id="login-title"
                        className="mb-1 text-xl font-bold tracking-[-0.01em]"
                    >
                        Entrar
                    </h1>
                    <p className="mb-6 text-sm text-muted-foreground">
                        Cuenta única de Marco. No hay registro.
                    </p>

                    <Form
                        {...store.form()}
                        resetOnError={['password']}
                        onStart={() => setOffline(false)}
                        onError={() => passwordRef.current?.focus()}
                        onNetworkError={() => {
                            setOffline(true);

                            return false;
                        }}
                    >
                        {({ errors, processing }) => {
                            const credentialsError =
                                errors.email ?? errors.password;

                            return (
                                <>
                                    {credentialsError ? (
                                        <SystemError
                                            title={credentialsError}
                                            detail="Escribí la contraseña de nuevo."
                                        />
                                    ) : offline ? (
                                        <SystemError
                                            title="No se pudo conectar con el servidor."
                                            detail="Revisá la conexión y tocá Entrar otra vez. Tus datos no se tocaron."
                                        />
                                    ) : null}

                                    <label className="mb-5 block">
                                        <span className="mb-2 block text-sm font-semibold">
                                            Correo
                                        </span>
                                        <Input
                                            type="email"
                                            name="email"
                                            required
                                            autoFocus
                                            autoComplete="username"
                                        />
                                    </label>

                                    <label className="mb-5 block">
                                        <span className="mb-2 block text-sm font-semibold">
                                            Contraseña
                                        </span>
                                        <span className="relative block">
                                            <Input
                                                ref={passwordRef}
                                                type={
                                                    showPassword
                                                        ? 'text'
                                                        : 'password'
                                                }
                                                name="password"
                                                required
                                                autoComplete="current-password"
                                                placeholder="Tu contraseña"
                                                className="pr-24"
                                            />
                                            <button
                                                type="button"
                                                className="absolute top-1/2 right-1.5 -translate-y-1/2 cursor-pointer rounded-sm px-2.5 py-2 text-sm font-semibold text-foreground underline underline-offset-[3px]"
                                                aria-pressed={showPassword}
                                                onClick={() =>
                                                    setShowPassword(
                                                        (visible) => !visible,
                                                    )
                                                }
                                            >
                                                {showPassword
                                                    ? 'Ocultar'
                                                    : 'Mostrar'}
                                            </button>
                                        </span>
                                    </label>

                                    <label className="-mt-1 mb-6 flex min-h-11 items-center gap-2.5 text-[15px]">
                                        <input
                                            type="checkbox"
                                            name="remember"
                                            value="1"
                                            defaultChecked
                                            className="size-5 accent-primary"
                                        />
                                        Recordarme en este equipo
                                    </label>

                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="w-full"
                                        disabled={processing}
                                    >
                                        {processing ? 'Entrando…' : 'Entrar'}
                                    </Button>
                                </>
                            );
                        }}
                    </Form>
                </section>
            </main>
        </>
    );
}
