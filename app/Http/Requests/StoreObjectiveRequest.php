<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesControlPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreObjectiveRequest extends FormRequest
{
    use ValidatesControlPlan;

    /**
     * The `auth` middleware gates the route; the objective is always created
     * for the authenticated owner.
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
            'key' => ['required', 'string', 'regex:/^[A-Z]{2,10}$/', Rule::unique('objectives', 'key')],
            'title' => ['required', 'string', 'max:255'],
            'identity_statement' => ['nullable', 'string', 'max:255'],
            ...$this->controlPlanRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.required' => 'La clave del objetivo es obligatoria.',
            'key.regex' => 'La clave tiene de 2 a 10 letras mayúsculas, sin números ni espacios.',
            'key.unique' => 'Ya existe un objetivo con esa clave.',
            'title.required' => 'El título es obligatorio.',
            'title.max' => 'El título no puede tener más de :max caracteres.',
            'identity_statement.max' => 'La frase de identidad no puede tener más de :max caracteres.',
            ...$this->controlPlanMessages('objetivo'),
        ];
    }
}
