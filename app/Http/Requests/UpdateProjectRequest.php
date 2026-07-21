<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    /**
     * Only the project's owner may update it.
     */
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()?->can('update', $project) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'key' => [
                'required',
                'string',
                'max:10',
                'regex:/^[A-Z][A-Z0-9]*$/',
                Rule::unique('projects', 'key')->ignore($project->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.required' => 'La clave del proyecto es obligatoria.',
            'key.max' => 'La clave no puede tener más de :max caracteres.',
            'key.regex' => 'La clave solo puede tener letras mayúsculas y números, y debe empezar con una letra.',
            'key.unique' => 'Ya existe un proyecto con esa clave.',
            'name.required' => 'El nombre del proyecto es obligatorio.',
            'name.max' => 'El nombre no puede tener más de :max caracteres.',
        ];
    }
}
