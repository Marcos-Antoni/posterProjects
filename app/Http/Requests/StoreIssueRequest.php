<?php

namespace App\Http\Requests;

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIssueRequest extends FormRequest
{
    /**
     * Only members of the project (bound by `{project:key}` on the route)
     * may create issues in it.
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
            'title' => ['required', 'string', 'max:255'],
            'board_column_id' => [
                'required',
                'integer',
                Rule::exists('board_columns', 'id')->where('project_id', $project->id),
            ],
            'sprint_id' => [
                'nullable',
                'integer',
                Rule::exists('sprints', 'id')->where('project_id', $project->id),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('issues', 'id')->where('project_id', $project->id),
            ],
        ];
    }

    /**
     * Enforces a single level of issue hierarchy: an issue that already has
     * a parent cannot itself become a parent.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $parentId = $this->input('parent_id');

                if ($parentId === null) {
                    return;
                }

                $parent = Issue::find((int) $parentId);

                if ($parent !== null && $parent->parent_id !== null) {
                    $validator->errors()->add(
                        'parent_id',
                        'Una sub-tarea no puede tener sub-tareas propias.',
                    );
                }
            },
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
            'board_column_id.required' => 'La columna es obligatoria.',
            'board_column_id.exists' => 'La columna no pertenece a este proyecto.',
            'sprint_id.exists' => 'El sprint no pertenece a este proyecto.',
            'parent_id.exists' => 'La tarea padre no pertenece a este proyecto.',
        ];
    }
}
