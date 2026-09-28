import { Form, Head, Link } from '@inertiajs/react';
import { CircleAlert, Clock } from 'lucide-react';
import type { ReactElement } from 'react';

import { store } from '@/actions/App/Http/Controllers/Auth/ConfirmablePasswordController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AuthLayout from '@/layouts/auth-layout';
import { home } from '@/routes';

/**
 * Password confirmation (mockup 30): the reused Laravel confirm screen that
 * will guard the AI bridge and AI permission grants. One primary action,
 * one clear way out, and the 15-minute window explained in context so the
 * next prompt does not surprise. A wrong password is an authentication
 * (system) error, so it uses the error color.
 */
export default function ConfirmPassword() {
    return (
        <>
            <Head title="Confirmar contraseña" />

            <h1 className="mt-4 text-xl font-bold tracking-[-0.01em]">
                Confirmá tu contraseña
            </h1>
            <p className="mt-2 mb-5 text-muted-foreground">
                Antes de seguir, porque esto le da poder a la IA sobre tu
                servidor o tus datos.
            </p>

            <Form {...store.form()} resetOnError={['password']}>
                {({ errors, processing }) => (
                    <>
                        <div className="mb-5 flex flex-col gap-1.5">
                            <label
                                htmlFor="password"
                                className="text-sm font-semibold"
                            >
                                Contraseña
                            </label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                required
                                autoFocus
                                autoComplete="current-password"
                                aria-invalid={
                                    errors.password ? true : undefined
                                }
                                aria-describedby={
                                    errors.password
                                        ? 'password-error'
                                        : undefined
                                }
                            />
                            {errors.password ? (
                                <p
                                    id="password-error"
                                    className="mt-1.5 flex items-center gap-1.5 text-sm text-destructive"
                                >
                                    <CircleAlert
                                        className="size-4 shrink-0"
                                        strokeWidth={1.75}
                                        aria-hidden="true"
                                    />
                                    {errors.password}
                                </p>
                            ) : null}
                        </div>

                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing ? 'Confirmando…' : 'Confirmar y seguir'}
                        </Button>
                    </>
                )}
            </Form>

            <Link
                href={home()}
                className="mt-3.5 block text-center text-sm font-semibold text-primary"
            >
                Volver sin hacerlo
            </Link>

            <p className="mt-5 flex gap-2 text-[13px] leading-[18px] text-muted-foreground">
                <Clock
                    className="size-4 shrink-0"
                    strokeWidth={1.75}
                    aria-hidden="true"
                />
                <span>
                    Vale 15 minutos. Después, el puente y los permisos te la
                    vuelven a pedir.
                </span>
            </p>
        </>
    );
}

ConfirmPassword.layout = (page: ReactElement) => (
    <AuthLayout>{page}</AuthLayout>
);
