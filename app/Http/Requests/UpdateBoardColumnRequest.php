<?php

namespace App\Http\Requests;

use App\Models\BoardColumn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardColumnRequest extends FormRequest
{
    /**
     * Only the owner of the column's project may rename it. The route
     * scopes `{boardColumn}` to the project via `scopeBindings()`.
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
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la columna es obligatorio.',
            'name.max' => 'El nombre no puede tener más de :max caracteres.',
        ];
    }
}
