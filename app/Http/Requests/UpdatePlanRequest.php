<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesControlPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
{
    use ValidatesControlPlan;

    /**
     * Ownership is enforced by the `{objective}` binding and the action.
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'level' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
            ...$this->controlPlanRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'El título del plan es obligatorio.',
            'title.max' => 'El título no puede tener más de :max caracteres.',
            'level.integer' => 'El nivel tiene que ser un número.',
            'level.min' => 'El nivel empieza en 1.',
            'level.max' => 'El nivel no puede ser mayor que :max.',
            ...$this->controlPlanMessages('plan'),
        ];
    }
}
