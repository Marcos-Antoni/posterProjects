<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesControlPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateObjectiveRequest extends FormRequest
{
    use ValidatesControlPlan;

    /**
     * Ownership is enforced by the `{objective}` binding (another owner's key
     * is not found) and again by the domain action.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is optional: only what is sent changes. The key is fixed
     * once created, because every item key is built from it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'identity_statement' => ['sometimes', 'nullable', 'string', 'max:255'],
            'key' => ['prohibited'],
            ...$this->controlPlanRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'El título es obligatorio.',
            'title.max' => 'El título no puede tener más de :max caracteres.',
            'identity_statement.max' => 'La frase de identidad no puede tener más de :max caracteres.',
            'key.prohibited' => 'La clave no cambia: las tareas ya la usan.',
            ...$this->controlPlanMessages('objetivo'),
        ];
    }
}
