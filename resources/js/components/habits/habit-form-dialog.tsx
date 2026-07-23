import { useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { motion } from 'motion/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

import { store, update } from '@/actions/App/Http/Controllers/HabitController';
import { WEEKDAY_SHORT_LABELS } from '@/components/habits/habit-labels';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Habit, HabitType, RecurrenceType } from '@/types/models';

type HabitFormDialogProps = {
    /** When present the dialog edits this habit; otherwise it creates one. */
    habit?: Habit;
};

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

/**
 * Create/edit habit dialog. Type and recurrence drive which fields are
 * visible (unit + daily target for quantitative habits, weekday toggles
 * or a weekly quota for non-daily recurrences); the planned time is
 * always optional. Fields that don't apply are dropped server-side.
 */
export function HabitFormDialog({ habit }: HabitFormDialogProps) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: habit?.name ?? '',
        habit_type: habit?.habit_type ?? ('yes_no' as HabitType),
        unit: habit?.unit ?? '',
        daily_target: habit?.daily_target?.toString() ?? '',
        recurrence_type: habit?.recurrence_type ?? ('daily' as RecurrenceType),
        weekdays: habit?.weekdays ?? [],
        times_per_week: habit?.times_per_week?.toString() ?? '',
        planned_time: habit?.planned_time?.slice(0, 5) ?? '',
    });

    function toggleWeekday(weekday: number) {
        setData(
            'weekdays',
            data.weekdays.includes(weekday)
                ? data.weekdays.filter((day) => day !== weekday)
                : [...data.weekdays, weekday].sort((a, b) => a - b),
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        const options = {
            onSuccess: () => {
                setOpen(false);

                if (!habit) {
                    reset();
                }
            },
        };

        if (habit) {
            patch(update.url(habit.id), options);
        } else {
            post(store.url(), options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {habit ? (
                    <Button type="button" variant="outline" size="sm">
                        <Pencil />
                        Editar
                    </Button>
                ) : (
                    <Button type="button">
                        <Plus />
                        Nuevo hábito
                    </Button>
                )}
            </DialogTrigger>

            <DialogContent>
                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2, ease: 'easeOut' }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {habit ? 'Editar hábito' : 'Nuevo hábito'}
                        </DialogTitle>
                        <DialogDescription>
                            {habit
                                ? `Actualizá la configuración de ${habit.name}.`
                                : 'Definí qué querés medir y con qué frecuencia.'}
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        onSubmit={submit}
                        className="mt-4 flex flex-col gap-4"
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="habit-name">Nombre</Label>
                            <Input
                                id="habit-name"
                                required
                                placeholder="Leer"
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                aria-invalid={Boolean(errors.name)}
                            />
                            {errors.name && (
                                <p className="text-sm text-destructive">
                                    {errors.name}
                                </p>
                            )}
                        </div>

                        <div className="grid gap-2">
                            <Label>Tipo</Label>
                            <Select
                                value={data.habit_type}
                                onValueChange={(value) =>
                                    setData('habit_type', value as HabitType)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="yes_no">
                                        Sí / No
                                    </SelectItem>
                                    <SelectItem value="quantitative">
                                        Cuantitativo
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {data.habit_type === 'quantitative' && (
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="habit-unit">Unidad</Label>
                                    <Input
                                        id="habit-unit"
                                        required
                                        placeholder="páginas"
                                        value={data.unit}
                                        onChange={(event) =>
                                            setData('unit', event.target.value)
                                        }
                                        aria-invalid={Boolean(errors.unit)}
                                    />
                                    {errors.unit && (
                                        <p className="text-sm text-destructive">
                                            {errors.unit}
                                        </p>
                                    )}
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="habit-target">
                                        Meta diaria
                                    </Label>
                                    <Input
                                        id="habit-target"
                                        required
                                        type="number"
                                        min={1}
                                        inputMode="numeric"
                                        placeholder="20"
                                        value={data.daily_target}
                                        onChange={(event) =>
                                            setData(
                                                'daily_target',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            errors.daily_target,
                                        )}
                                    />
                                    {errors.daily_target && (
                                        <p className="text-sm text-destructive">
                                            {errors.daily_target}
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label>Recurrencia</Label>
                            <Select
                                value={data.recurrence_type}
                                onValueChange={(value) =>
                                    setData(
                                        'recurrence_type',
                                        value as RecurrenceType,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="daily">
                                        Todos los días
                                    </SelectItem>
                                    <SelectItem value="specific_weekdays">
                                        Días específicos
                                    </SelectItem>
                                    <SelectItem value="times_per_week">
                                        X veces por semana
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {data.recurrence_type === 'specific_weekdays' && (
                            <div className="grid gap-2">
                                <Label>Días de la semana</Label>
                                <div className="flex flex-wrap gap-1.5">
                                    {WEEKDAYS.map((weekday) => (
                                        <Button
                                            key={weekday}
                                            type="button"
                                            size="sm"
                                            variant={
                                                data.weekdays.includes(weekday)
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            className="w-11"
                                            onClick={() =>
                                                toggleWeekday(weekday)
                                            }
                                        >
                                            {WEEKDAY_SHORT_LABELS[weekday]}
                                        </Button>
                                    ))}
                                </div>
                                {errors.weekdays && (
                                    <p className="text-sm text-destructive">
                                        {errors.weekdays}
                                    </p>
                                )}
                            </div>
                        )}

                        {data.recurrence_type === 'times_per_week' && (
                            <div className="grid gap-2">
                                <Label htmlFor="habit-times">
                                    Veces por semana
                                </Label>
                                <Input
                                    id="habit-times"
                                    required
                                    type="number"
                                    min={1}
                                    max={7}
                                    inputMode="numeric"
                                    placeholder="3"
                                    value={data.times_per_week}
                                    onChange={(event) =>
                                        setData(
                                            'times_per_week',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={Boolean(
                                        errors.times_per_week,
                                    )}
                                />
                                {errors.times_per_week && (
                                    <p className="text-sm text-destructive">
                                        {errors.times_per_week}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="habit-planned-time">
                                Hora planificada (opcional)
                            </Label>
                            <Input
                                id="habit-planned-time"
                                type="time"
                                value={data.planned_time}
                                onChange={(event) =>
                                    setData('planned_time', event.target.value)
                                }
                                aria-invalid={Boolean(errors.planned_time)}
                            />
                            {errors.planned_time && (
                                <p className="text-sm text-destructive">
                                    {errors.planned_time}
                                </p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="submit" disabled={processing}>
                                {processing
                                    ? 'Guardando…'
                                    : habit
                                      ? 'Guardar cambios'
                                      : 'Crear hábito'}
                            </Button>
                        </DialogFooter>
                    </form>
                </motion.div>
            </DialogContent>
        </Dialog>
    );
}
