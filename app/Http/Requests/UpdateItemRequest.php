<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only what is sent changes. The 2-minute version may be replaced but
     * never cleared.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'two_minute_version' => ['sometimes', 'required', 'string', 'max:255'],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'plan_id' => ['sometimes', 'required', 'integer'],
            'position' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Falta el título.',
            'title.max' => 'El título no puede tener más de :max caracteres.',
            'description.max' => 'La descripción no puede tener más de :max caracteres.',
            'two_minute_version.required' => 'La versión de 2 minutos no se puede borrar: es lo primero que ves en Ahora.',
            'two_minute_version.max' => 'La versión de 2 minutos puede tener hasta :max caracteres.',
            'target_date.date_format' => 'La fecha objetivo no es válida.',
            'plan_id.required' => 'Elegí un plan.',
            'position.min' => 'La posición no es válida.',
        ];
    }
}
