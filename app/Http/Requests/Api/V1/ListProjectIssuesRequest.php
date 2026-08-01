<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListProjectIssuesRequest extends FormRequest
{
    /**
     * Query-shape only. Membership and existence are resolved by the
     * controller (`IssueController::resolveProject()`), never here, so
     * this request never discloses project access through its own check.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `sprint` accepts only the literal `backlog` or a positive integer
     * string here — a syntactically valid but foreign sprint id is a
     * controller-level `404`, not a `422` from this request: confirming a
     * value's shape is not the same as confirming it exists.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sprint' => ['nullable', 'regex:/^(backlog|[1-9][0-9]*)$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.integer' => 'El número de resultados por página debe ser un número entero.',
            'per_page.min' => 'El número de resultados por página debe ser al menos :min.',
            'per_page.max' => 'El número de resultados por página no puede superar :max.',
            'page.integer' => 'El número de página debe ser un número entero.',
            'page.min' => 'El número de página debe ser al menos :min.',
            'sprint.regex' => 'El sprint indicado no es válido.',
        ];
    }
}
