<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /**
     * The `auth` middleware already gates this route — any authenticated
     * user may create a project (and becomes its owner).
     */
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
            'key' => [
                'required',
                'string',
                'max:10',
                'regex:/^[A-Z][A-Z0-9]*$/',
                Rule::unique('projects', 'key'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.required' => 'La clave del proyecto es obligatoria.',
            'key.max' => 'La clave no puede tener más de :max caracteres.',
            'key.regex' => 'La clave solo puede tener letras mayúsculas y números, y debe empezar con una letra.',
            'key.unique' => 'Ya existe un proyecto con esa clave.',
            'name.required' => 'El nombre del proyecto es obligatorio.',
            'name.max' => 'El nombre no puede tener más de :max caracteres.',
        ];
    }
}
