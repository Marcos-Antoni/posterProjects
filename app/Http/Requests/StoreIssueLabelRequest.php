<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIssueLabelRequest extends FormRequest
{
    /**
     * Any project member may attach a label to an issue. The owner-only
     * management screen ships in T-10.
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
            'label_id' => [
                'required',
                'integer',
                Rule::exists('labels', 'id')->where('project_id', $project->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label_id.required' => 'Elegí una etiqueta.',
            'label_id.exists' => 'La etiqueta no pertenece a este proyecto.',
        ];
    }
}
