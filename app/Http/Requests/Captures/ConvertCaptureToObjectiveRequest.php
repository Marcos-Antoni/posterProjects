<?php

namespace App\Http\Requests\Captures;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Triage: convert a capture into a new draft objective (screen 16). Same
 * key shape as the direct objective form; the 5-point plan is filled later,
 * before the owner activates it.
 */
class ConvertCaptureToObjectiveRequest extends FormRequest
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
            'key' => ['required', 'string', 'regex:/^[A-Z]{2,10}$/i', Rule::unique('objectives', 'key')],
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.required' => 'La clave del objetivo es obligatoria.',
            'key.regex' => 'La clave tiene de 2 a 10 letras, sin números ni espacios.',
            'key.unique' => 'Ya existe un objetivo con esa clave.',
            'title.required' => 'El título es obligatorio.',
        ];
    }
}
