<?php

namespace App\Http\Requests;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateIssueRequest extends FormRequest
{
    /**
     * Any member of the project (bound by `{project:key}` on the route,
     * the issue itself is scoped to it via `scopeBindings()`) may edit an
     * issue's details — not owner-only.
     */
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()?->can('view', $project) ?? false;
    }

    /**
     * Every field is `sometimes` — the modal auto-saves one field (or a
     * few) at a time, so this request must accept a partial payload
     * without requiring the rest of the issue's fields to be resent.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', Rule::enum(IssueType::class)],
            'priority' => ['sometimes', Rule::enum(IssuePriority::class)],
            'story_points' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'assignee_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('project_members', 'user_id')->where('project_id', $project->id),
            ],
            'sprint_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('sprints', 'id')->where('project_id', $project->id),
            ],
            'board_column_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('board_columns', 'id')->where('project_id', $project->id),
            ],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('issues', 'id')->where('project_id', $project->id),
            ],
        ];
    }

    /**
     * Enforces a single level of issue hierarchy when `parent_id` is being
     * set to a non-null value: the chosen parent can't itself already have
     * a parent, and the issue being edited can't already have children
     * (either would create a two-level hierarchy). Also rejects an issue
     * being set as its own parent.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('parent_id') || $this->input('parent_id') === null) {
                    return;
                }

                /** @var Issue $issue */
                $issue = $this->route('issue');
                $parentId = (int) $this->input('parent_id');

                if ($parentId === $issue->id) {
                    $validator->errors()->add('parent_id', 'Una tarea no puede ser su propia tarea padre.');

                    return;
                }

                if ($issue->children()->exists()) {
                    $validator->errors()->add('parent_id', 'Esta tarea tiene sub-tareas y no puede recibir una tarea padre.');

                    return;
                }

                $parent = Issue::find($parentId);

                if ($parent !== null && $parent->parent_id !== null) {
                    $validator->errors()->add('parent_id', 'Una sub-tarea no puede tener sub-tareas propias.');
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
            'type.enum' => 'El tipo no es válido.',
            'priority.enum' => 'La prioridad no es válida.',
            'story_points.integer' => 'Los puntos de historia deben ser un número entero.',
            'story_points.min' => 'Los puntos de historia no pueden ser negativos.',
            'due_date.date' => 'La fecha límite no es válida.',
            'assignee_id.exists' => 'La persona asignada no es miembro de este proyecto.',
            'sprint_id.exists' => 'El sprint no pertenece a este proyecto.',
            'board_column_id.required' => 'La columna es obligatoria.',
            'board_column_id.exists' => 'La columna no pertenece a este proyecto.',
            'parent_id.exists' => 'La tarea padre no pertenece a este proyecto.',
        ];
    }
}
