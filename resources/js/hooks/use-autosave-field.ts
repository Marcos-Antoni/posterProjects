import { router } from '@inertiajs/react';
import { useState } from 'react';

type Primitive = string | number | null;

/**
 * Optimistic single-field auto-save, used by the issue modal's edit form
 * (`edit-form.tsx`): updates the local value immediately so selects/inputs
 * feel instant, PATCHes just that one field in the background, and rolls
 * back to the last server-confirmed value if the request fails. Consumers
 * should `key` the component owning this hook by the issue's id so
 * switching to a different issue resets the local state instead of
 * carrying over a stale value.
 */
export function useAutosaveField<T extends Primitive>(
    url: string,
    field: string,
    initialValue: T,
) {
    const [value, setValue] = useState(initialValue);
    const [confirmedValue, setConfirmedValue] = useState(initialValue);
    const [saving, setSaving] = useState(false);

    function save(nextValue: T) {
        setValue(nextValue);
        setSaving(true);

        router.patch(
            url,
            { [field]: nextValue },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setConfirmedValue(nextValue),
                onError: () => setValue(confirmedValue),
                onFinish: () => setSaving(false),
            },
        );
    }

    return { value, saving, save } as const;
}
