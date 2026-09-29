import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { toast } from '@/components/ui/toast';
import {
    index as capturesIndex,
    store as storeCapture,
} from '@/routes/captures';

const MAX_LENGTH = 500;

/**
 * The quick-entry overlay (screen 17, capture-inbox spec "Capture Is
 * Reachable In One Step From Every Web Screen"): one field, opened with the
 * `c` shortcut or the visible "Capturar" button from ANY authenticated
 * screen, or by the `marcos:capture` window event other pages dispatch (the
 * Now empty state). Saving never navigates away: the current screen stays
 * exactly as it was.
 */
export function QuickCapture() {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const inputRef = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            if (event.key.toLowerCase() !== 'c') {
                return;
            }

            const target = event.target as HTMLElement | null;

            if (
                target?.closest(
                    'input, textarea, select, [contenteditable="true"]',
                )
            ) {
                return;
            }

            event.preventDefault();
            setOpen(true);
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        const onCapture = (event: Event) => {
            event.preventDefault();
            setOpen(true);
        };

        window.addEventListener('marcos:capture', onCapture);

        return () => window.removeEventListener('marcos:capture', onCapture);
    }, []);

    const submit = () => {
        const trimmed = text.trim();

        if (trimmed === '') {
            return;
        }

        setProcessing(true);
        setError(null);

        router.post(
            storeCapture().url,
            { text: trimmed },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setOpen(false);
                    setText('');
                    toast('Capturado. Está en el inbox.', {
                        action: {
                            label: 'Ver inbox',
                            onClick: () => router.visit(capturesIndex().url),
                        },
                    });
                },
                onError: (errors) =>
                    setError(
                        (errors as Record<string, string>).text ??
                            'No se pudo guardar.',
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <button
                className="btn-sm btn-outline"
                type="button"
                onClick={() => setOpen(true)}
            >
                Capturar <kbd>C</kbd>
            </button>
            <Dialog
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);

                    if (!next) {
                        setText('');
                        setError(null);
                    }
                }}
            >
                <DialogContent
                    className="mos gap-3"
                    onOpenAutoFocus={(event) => {
                        event.preventDefault();
                        inputRef.current?.focus();
                    }}
                >
                    <DialogTitle>Capturar</DialogTitle>
                    <DialogDescription className="text-sm text-muted-foreground">
                        Va al inbox, sin fecha ni prioridad.
                    </DialogDescription>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                        className="grid gap-2"
                    >
                        <label className="sr" htmlFor="quick-capture-text">
                            Idea a capturar
                        </label>
                        <textarea
                            ref={inputRef}
                            id="quick-capture-text"
                            className="in"
                            rows={2}
                            maxLength={MAX_LENGTH}
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && !event.shiftKey) {
                                    event.preventDefault();
                                    submit();
                                }
                            }}
                        />
                        <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
                            <span>
                                {error ?? `${text.length} / ${MAX_LENGTH}`}
                            </span>
                            <button
                                className="btn btn-primary btn-md"
                                type="submit"
                                disabled={processing || text.trim() === ''}
                            >
                                Guardar
                            </button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
