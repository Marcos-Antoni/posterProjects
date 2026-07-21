import { CalendarIcon } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import { parseIsoDate, toIsoDateString } from '@/lib/dates';

type SprintFormFieldsProps = {
    errors: Record<string, string | undefined>;
    defaults?: {
        name?: string;
        goal?: string | null;
        start_date?: string;
        end_date?: string;
    };
};

/**
 * Name/goal/date-range fields shared by the create and edit sprint
 * dialogs. `start_date`/`end_date` use the same shadcn Popover+Calendar
 * date picker as the issue modal's due-date field (`edit-form.tsx`), wired
 * to a hidden input so the surrounding `<Form>` component still submits
 * them by `name` like every other field.
 */
export function SprintFormFields({ errors, defaults }: SprintFormFieldsProps) {
    const [startDate, setStartDate] = useState(defaults?.start_date);
    const [endDate, setEndDate] = useState(defaults?.end_date);

    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-2">
                <Label htmlFor="name">Nombre</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    placeholder="Sprint 1"
                    defaultValue={defaults?.name}
                    aria-invalid={Boolean(errors.name)}
                />
                {errors.name ? (
                    <p className="text-sm text-destructive">{errors.name}</p>
                ) : null}
            </div>

            <div className="grid gap-2">
                <Label htmlFor="goal">Objetivo</Label>
                <Textarea
                    id="goal"
                    name="goal"
                    placeholder="¿Qué buscamos lograr en este sprint?"
                    defaultValue={defaults?.goal ?? ''}
                    aria-invalid={Boolean(errors.goal)}
                />
                {errors.goal ? (
                    <p className="text-sm text-destructive">{errors.goal}</p>
                ) : null}
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label>Fecha de inicio</Label>
                    <Popover>
                        <PopoverTrigger asChild>
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full justify-start font-normal"
                            >
                                <CalendarIcon className="size-4" />
                                {startDate
                                    ? parseIsoDate(
                                          startDate,
                                      ).toLocaleDateString('es-AR')
                                    : 'Elegir fecha'}
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent className="w-auto p-0" align="start">
                            <Calendar
                                mode="single"
                                selected={
                                    startDate
                                        ? parseIsoDate(startDate)
                                        : undefined
                                }
                                onSelect={(date) =>
                                    setStartDate(
                                        date
                                            ? toIsoDateString(date)
                                            : undefined,
                                    )
                                }
                            />
                        </PopoverContent>
                    </Popover>
                    <input
                        type="hidden"
                        name="start_date"
                        value={startDate ?? ''}
                    />
                    {errors.start_date ? (
                        <p className="text-sm text-destructive">
                            {errors.start_date}
                        </p>
                    ) : null}
                </div>

                <div className="grid gap-2">
                    <Label>Fecha de fin</Label>
                    <Popover>
                        <PopoverTrigger asChild>
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full justify-start font-normal"
                            >
                                <CalendarIcon className="size-4" />
                                {endDate
                                    ? parseIsoDate(endDate).toLocaleDateString(
                                          'es-AR',
                                      )
                                    : 'Elegir fecha'}
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent className="w-auto p-0" align="start">
                            <Calendar
                                mode="single"
                                selected={
                                    endDate ? parseIsoDate(endDate) : undefined
                                }
                                onSelect={(date) =>
                                    setEndDate(
                                        date
                                            ? toIsoDateString(date)
                                            : undefined,
                                    )
                                }
                            />
                        </PopoverContent>
                    </Popover>
                    <input
                        type="hidden"
                        name="end_date"
                        value={endDate ?? ''}
                    />
                    {errors.end_date ? (
                        <p className="text-sm text-destructive">
                            {errors.end_date}
                        </p>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
