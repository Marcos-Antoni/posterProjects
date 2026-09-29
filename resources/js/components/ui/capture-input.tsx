import { useId, useRef, useState } from 'react';
import type { FocusEvent, FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * Quick capture (§5.8): one line, Enter saves to the inbox, confirmation
 * "Capturado.". It never asks for priority, date or project (capture ≠
 * priority). After saving, focus returns to wherever it was before the
 * capture started, so Marco never loses his place.
 */
type CaptureInputProps = {
    onCapture: (text: string) => void | Promise<void>;
    label?: string;
    placeholder?: string;
    hint?: string;
    className?: string;
    autoFocus?: boolean;
};

export function CaptureInput({
    onCapture,
    label = 'Idea para el inbox',
    placeholder = 'Una idea, una línea. Enter guarda.',
    hint = 'No pide fecha, prioridad ni proyecto.',
    className,
    autoFocus = false,
}: CaptureInputProps) {
    const inputId = useId();
    const [value, setValue] = useState('');
    const [status, setStatus] = useState(hint);
    const [saving, setSaving] = useState(false);
    const returnFocusTo = useRef<HTMLElement | null>(null);

    const rememberFocus = (event: FocusEvent<HTMLInputElement>) => {
        const previous = event.relatedTarget;

        if (previous instanceof HTMLElement) {
            returnFocusTo.current = previous;
        }
    };

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const text = value.trim();

        if (text === '' || saving) {
            return;
        }

        setSaving(true);

        try {
            await onCapture(text);
            setValue('');
            setStatus('Capturado.');
            returnFocusTo.current?.focus();
        } finally {
            setSaving(false);
        }
    };

    return (
        <div data-slot="capture-input" className={cn('grid gap-2', className)}>
            <form className="flex gap-2" onSubmit={submit}>
                <label htmlFor={inputId} className="sr-only">
                    {label}
                </label>
                <Input
                    id={inputId}
                    value={value}
                    autoFocus={autoFocus}
                    autoComplete="off"
                    placeholder={placeholder}
                    className="min-h-12"
                    onFocus={rememberFocus}
                    onChange={(event) => setValue(event.target.value)}
                />
                <Button type="submit" size="lg" disabled={saving}>
                    {saving ? 'Capturando…' : 'Capturar'}
                </Button>
            </form>
            <p role="status" className="text-sm text-muted-foreground">
                {status}
            </p>
        </div>
    );
}
