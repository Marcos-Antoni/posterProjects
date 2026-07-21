import { Form, Head } from '@inertiajs/react';

import { store } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function Login() {
    return (
        <AuthLayout
            title="Iniciar sesión"
            description="Ingresá tu correo y contraseña para acceder"
        >
            <Head title="Iniciar sesión" />

            <Form
                {...store.form()}
                resetOnError={['password']}
                className="flex flex-col gap-6"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Correo electrónico</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                autoComplete="username"
                                placeholder="vos@ejemplo.com"
                            />
                            {errors.email ? (
                                <p className="text-sm text-destructive">
                                    {errors.email}
                                </p>
                            ) : null}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Contraseña</Label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                required
                                autoComplete="current-password"
                            />
                            {errors.password ? (
                                <p className="text-sm text-destructive">
                                    {errors.password}
                                </p>
                            ) : null}
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing ? 'Ingresando…' : 'Ingresar'}
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
