<?php

namespace App\Http\Requests;

use App\Enums\ControlZone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreControlMapEntryRequest extends FormRequest
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
            'zone' => ['required', Rule::enum(ControlZone::class)],
            'text' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zone.required' => 'Elegí la zona del mapa de control.',
            'zone.enum' => 'La zona del mapa de control no es válida.',
            'text.required' => 'Escribí la entrada.',
            'text.max' => 'Cada entrada puede tener hasta :max caracteres.',
        ];
    }
}
