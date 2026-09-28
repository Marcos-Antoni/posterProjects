<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `evidence` is required for milestones only; `CheckItem` enforces it so
     * the rule is the same on the web, the API and MCP.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'evidence' => ['nullable', 'string', 'max:2000'],
            'link' => ['nullable', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evidence.max' => 'La evidencia puede tener hasta :max caracteres.',
            'link.url' => 'El enlace no es una dirección válida.',
            'link.max' => 'El enlace es demasiado largo.',
        ];
    }
}
