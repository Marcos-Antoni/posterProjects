<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveIssueRequest extends FormRequest
{
    /**
     * Any member of the project (bound by `{project:key}` on the route,
     * the issue itself is scoped to it via `scopeBindings()`) may drag an
     * issue — not owner-only.
     */
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()?->can('view', $project) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'board_column_id' => [
                'required',
                'integer',
                Rule::exists('board_columns', 'id')->where('project_id', $project->id),
            ],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'board_column_id.required' => 'La columna destino es obligatoria.',
            'board_column_id.exists' => 'La columna no pertenece a este proyecto.',
            'position.required' => 'La posición destino es obligatoria.',
            'position.integer' => 'La posición debe ser un número entero.',
            'position.min' => 'La posición no puede ser negativa.',
        ];
    }
}
