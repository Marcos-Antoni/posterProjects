<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ItemDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The other end of the edge, by public key (e.g. "SALUD-7"). It may belong
     * to any of the owner's objectives (cross-objective edges).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.required' => 'Elegí la tarea o el hito.',
            'key.max' => 'No encontramos esa tarea o hito.',
        ];
    }
}
