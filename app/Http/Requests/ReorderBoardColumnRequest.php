<?php

namespace App\Http\Requests;

use App\Models\BoardColumn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderBoardColumnRequest extends FormRequest
{
    /**
     * Only the owner of the column's project may reorder its board. The
     * route scopes `{boardColumn}` to the project via `scopeBindings()`.
     */
    public function authorize(): bool
    {
        /** @var BoardColumn $boardColumn */
        $boardColumn = $this->route('boardColumn');

        return $this->user()?->can('update', $boardColumn) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'position' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'position.required' => 'La posición destino es obligatoria.',
            'position.integer' => 'La posición debe ser un número entero.',
            'position.min' => 'La posición no puede ser negativa.',
        ];
    }
}
