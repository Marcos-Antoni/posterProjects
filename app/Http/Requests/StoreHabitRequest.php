<?php

namespace App\Http\Requests;

use App\Actions\Habits\HabitWriter;
use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHabitRequest extends FormRequest
{
    /**
     * Any authenticated user may create habits — they are personal and
     * always attached to the requester.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Fields tied to a type or recurrence use `exclude_unless`: they are
     * required when their mode applies and silently dropped from the
     * validated payload otherwise, so a yes/no habit never persists a
     * stray unit and a daily habit never persists weekdays.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'habit_type' => ['required', Rule::enum(HabitType::class)],
            'unit' => ['exclude_unless:habit_type,quantitative', 'required', 'string', 'max:255'],
            'daily_target' => ['exclude_unless:habit_type,quantitative', 'required', 'integer', 'min:1'],
            'recurrence_type' => ['required', Rule::enum(RecurrenceType::class)],
            'weekdays' => ['exclude_unless:recurrence_type,specific_weekdays', 'required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'times_per_week' => ['exclude_unless:recurrence_type,times_per_week', 'required', 'integer', 'between:1,7'],
            'planned_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'two_minute_version' => ['required', 'string', 'max:255'],
            'identity_statement' => ['nullable', 'string', 'max:255'],
            'objective_id' => ['nullable', 'integer'],
            'plan_id' => ['nullable', 'integer'],
            'level' => ['nullable', 'integer', 'min:1'],
            'level_ladder' => ['nullable', 'array', 'max:10'],
            'level_ladder.*.label' => ['required', 'string', 'max:255'],
            'level_ladder.*.target' => ['nullable', 'integer', 'min:1'],
            'level_ladder.*.two_minute_version' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede tener más de :max caracteres.',
            'habit_type.required' => 'El tipo de hábito es obligatorio.',
            'habit_type.enum' => 'El tipo de hábito no es válido.',
            'unit.required' => 'La unidad es obligatoria para hábitos cuantitativos.',
            'unit.max' => 'La unidad no puede tener más de :max caracteres.',
            'daily_target.required' => 'La meta diaria es obligatoria para hábitos cuantitativos.',
            'daily_target.integer' => 'La meta diaria debe ser un número entero.',
            'daily_target.min' => 'La meta diaria debe ser al menos :min.',
            'recurrence_type.required' => 'La recurrencia es obligatoria.',
            'recurrence_type.enum' => 'La recurrencia no es válida.',
            'weekdays.required' => 'Elegí al menos un día de la semana.',
            'weekdays.array' => 'Los días de la semana no son válidos.',
            'weekdays.min' => 'Elegí al menos un día de la semana.',
            'weekdays.*.integer' => 'Los días de la semana no son válidos.',
            'weekdays.*.between' => 'Los días de la semana deben estar entre lunes y domingo.',
            'weekdays.*.distinct' => 'No repitas días de la semana.',
            'times_per_week.required' => 'Indicá cuántas veces por semana.',
            'times_per_week.integer' => 'Las veces por semana deben ser un número entero.',
            'times_per_week.between' => 'Las veces por semana deben estar entre :min y :max.',
            'planned_time.date_format' => 'La hora planificada no es válida.',
            'two_minute_version.required' => HabitWriter::MISSING_TWO_MINUTE,
            'two_minute_version.max' => 'La versión de 2 minutos no puede tener más de :max caracteres.',
            'identity_statement.max' => 'La identidad no puede tener más de :max caracteres.',
            'level.min' => 'El nivel actual tiene que ser uno de la escalera.',
            'level_ladder.max' => 'La escalera tiene como mucho :max niveles.',
            'level_ladder.*.label.required' => 'Cada nivel necesita una descripción.',
            'level_ladder.*.target.min' => 'La meta de un nivel debe ser al menos :min.',
            'level_ladder.*.two_minute_version.required' => 'Cada nivel necesita su versión de 2 minutos.',
        ];
    }
}
