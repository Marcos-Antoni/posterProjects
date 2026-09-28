import { Head } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import type { ReactElement } from 'react';

import { useAppearance } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings-layout';
import { cn } from '@/lib/utils';
import type { Appearance as AppearanceValue } from '@/types/global';

type ThemeOption = {
    value: AppearanceValue;
    label: string;
    hint: string;
};

const OPTIONS: ThemeOption[] = [
    { value: 'light', label: 'Claro', hint: 'Niebla' },
    { value: 'dark', label: 'Oscuro', hint: 'Basalto' },
    { value: 'system', label: 'Sistema', hint: 'el que use tu equipo' },
];

/**
 * Fixed palette values: each preview shows ITS theme regardless of the
 * active one, so it can be recognized, not remembered (mockup 29 `.pv`).
 */
const PREVIEWS: Record<
    AppearanceValue,
    {
        background: string;
        bar: string;
        line: string;
        muted: string;
        blaze: string;
        ink: string;
        primary: string;
    }
> = {
    light: {
        background: '#EEF2EF',
        bar: '#FAFBF9',
        line: '#1B2A2A',
        muted: '#C9D3CF',
        blaze: '#E6AE1F',
        ink: '#8A6200',
        primary: '#1D5E4E',
    },
    dark: {
        background: '#142022',
        bar: '#1C2A2C',
        line: '#E3ECE8',
        muted: '#33464A',
        blaze: '#F0BF45',
        ink: '#F0BF45',
        primary: '#6CC3A6',
    },
    system: {
        background: 'linear-gradient(90deg,#EEF2EF 50%,#142022 50%)',
        bar: 'linear-gradient(90deg,#FAFBF9 50%,#1C2A2C 50%)',
        line: '#7D8C89',
        muted: '#7D8C89',
        blaze: '#E6AE1F',
        ink: '#8A6200',
        primary: '#1D5E4E',
    },
};

function ThemePreview({ value }: { value: AppearanceValue }) {
    const palette = PREVIEWS[value];

    return (
        <span
            aria-hidden="true"
            className="grid h-[84px] grid-rows-[14px_1fr] overflow-hidden rounded-sm border border-border"
            style={{ background: palette.background }}
        >
            <span
                className="flex items-center gap-1 px-1.5"
                style={{ background: palette.bar }}
            >
                <span
                    className="h-1.5 w-2 rounded-xs border"
                    style={{
                        background: palette.blaze,
                        borderColor: palette.ink,
                    }}
                />
            </span>
            <span className="flex flex-col gap-[5px] p-2">
                <span
                    className="h-[5px] w-[70%] rounded-xs"
                    style={{ background: palette.line }}
                />
                <span
                    className="h-[5px] w-[45%] rounded-xs"
                    style={{ background: palette.muted }}
                />
                <span
                    className="h-3 w-[18px] rounded-xs border-2"
                    style={{
                        background: palette.blaze,
                        borderColor: palette.ink,
                    }}
                />
                <span
                    className="mt-auto h-2.5 w-[34px] rounded-xs"
                    style={{ background: palette.primary }}
                />
            </span>
        </span>
    );
}

/**
 * Settings → Apariencia (mockup 29): exactly one control, the theme
 * selector. It saves on choice — no save button, no toast stealing focus —
 * and the choice is stored per user, so every browser renders it. Profile
 * and password are intentionally not editable on the web.
 */
export default function Appearance() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <>
            <Head title="Apariencia" />

            <h1 className="mb-2 text-2xl font-bold tracking-[-0.01em]">
                Apariencia
            </h1>
            <p className="mb-7 max-w-[68ch] text-muted-foreground">
                Lo poco que se configura en Marcos OS. El resto viene decidido
                para que no tengas que pensarlo.
            </p>

            <section
                aria-labelledby="appearance-heading"
                className="rounded-md border border-border bg-surface p-6"
            >
                <h2
                    id="appearance-heading"
                    className="mb-1 text-lg leading-[26px] font-bold"
                >
                    Apariencia
                </h2>
                <p className="text-sm text-muted-foreground">
                    Se guarda al elegir.
                </p>

                <div
                    role="radiogroup"
                    aria-labelledby="appearance-heading"
                    className="mt-3.5 grid gap-3 sm:grid-cols-3"
                >
                    {OPTIONS.map((option) => {
                        const selected = appearance === option.value;

                        return (
                            <label
                                key={option.value}
                                data-selected={selected || undefined}
                                className={cn(
                                    'relative flex cursor-pointer flex-col gap-2.5 rounded-md border-[1.5px] border-input p-2.5 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring',
                                    selected && 'border-2 border-primary',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="appearance"
                                    value={option.value}
                                    checked={selected}
                                    onChange={() =>
                                        updateAppearance(option.value)
                                    }
                                    className="sr-only"
                                />
                                <span className="flex items-center justify-between text-sm font-semibold">
                                    <span className="flex items-baseline gap-1">
                                        <span>{option.label}</span>
                                        <small className="text-[13px] font-normal text-muted-foreground">
                                            {option.hint}
                                        </small>
                                    </span>
                                    {selected ? (
                                        <span
                                            aria-hidden="true"
                                            className="size-2.5 rounded-full bg-primary shadow-[0_0_0_3px_var(--surface),0_0_0_4.5px_var(--primary)]"
                                        />
                                    ) : null}
                                </span>
                                <ThemePreview value={option.value} />
                            </label>
                        );
                    })}
                </div>

                <p className="mt-3 text-sm text-muted-foreground">
                    Las animaciones siguen la preferencia de movimiento reducido
                    de tu sistema.
                </p>
            </section>

            <p className="mt-6 text-sm text-muted-foreground">
                El perfil y la contraseña no se editan en la web. La contraseña
                se cambia en el servidor con{' '}
                <code>php artisan marcos:set-password</code>.
            </p>
            <p className="mt-5 flex items-start gap-2.5 text-sm text-muted-foreground">
                <Archive
                    className="size-[18px] shrink-0"
                    strokeWidth={1.75}
                    aria-hidden="true"
                />
                <span>
                    No hay “borrar cuenta”: en Marcos OS nada se borra. Tus
                    respaldos viven en el VPS.
                </span>
            </p>
        </>
    );
}

Appearance.layout = (page: ReactElement) => (
    <AppLayout>
        <SettingsLayout>{page}</SettingsLayout>
    </AppLayout>
);
