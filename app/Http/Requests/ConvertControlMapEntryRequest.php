<?php

namespace App\Http\Requests;

use App\Actions\Items\AddItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConvertControlMapEntryRequest extends FormRequest
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
            'plan_id' => ['required', 'integer'],
            'kind' => ['nullable', 'in:task,milestone'],
            'two_minute_version' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.required' => 'Elegí el plan donde va la tarea.',
            'kind.in' => 'El tipo tiene que ser tarea o hito.',
            'two_minute_version.required' => AddItem::MISSING_TWO_MINUTE,
            'two_minute_version.max' => 'La versión de 2 minutos puede tener hasta :max caracteres.',
        ];
    }
}
