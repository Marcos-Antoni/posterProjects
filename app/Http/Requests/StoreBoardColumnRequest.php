<?php

namespace App\Http\Requests;

use App\Models\BoardColumn;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoardColumnRequest extends FormRequest
{
    /**
     * Only the project's owner may add a column to its board.
     */
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()?->can('create', [BoardColumn::class, $project]) ?? false;
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
