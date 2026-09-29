<?php

namespace App\Http\Requests;

use App\Actions\Items\AddItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['nullable', 'in:task,milestone'],
            'title' => ['required', 'string', 'max:255'],
            'two_minute_version' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'target_date' => ['nullable', 'date_format:Y-m-d'],
            'prerequisite_ids' => ['nullable', 'array', 'max:50'],
            'prerequisite_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.in' => 'El tipo tiene que ser tarea o hito.',
            'title.required' => 'Falta el título.',
            'title.max' => 'El título no puede tener más de :max caracteres.',
            'two_minute_version.required' => AddItem::MISSING_TWO_MINUTE,
            'two_minute_version.max' => 'La versión de 2 minutos puede tener hasta :max caracteres.',
            'description.max' => 'La descripción no puede tener más de :max caracteres.',
            'target_date.date_format' => 'La fecha objetivo no es válida.',
            'prerequisite_ids.array' => 'Las dependencias tienen que ser una lista.',
            'prerequisite_ids.*.integer' => 'Una de las dependencias no es válida.',
        ];
    }
}
