<?php

namespace App\Http\Requests;

use App\Models\Label;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLabelRequest extends FormRequest
{
    /**
     * Only the label's project owner may rename it. The route scopes
     * `{label}` to the project via `scopeBindings()`.
     */
    public function authorize(): bool
    {
        /** @var Label $label */
        $label = $this->route('label');

        return $this->user()?->can('update', $label) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Label $label */
        $label = $this->route('label');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('labels', 'name')->where('project_id', $label->project_id)->ignore($label->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la etiqueta es obligatorio.',
            'name.unique' => 'Ya existe una etiqueta con ese nombre en este proyecto.',
        ];
    }
}
