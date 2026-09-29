<?php

namespace App\Http\Requests\Reviews;

use App\Actions\Reviews\SubmitWeeklyReview;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The weekly review (screen 14): the two questions, then next week's main
 * priority and at most two maintenance standards.
 */
class SubmitWeeklyReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'what_worked' => ['required', 'string', 'max:2000'],
            'what_blocked' => ['required', 'string', 'max:2000'],
            'main_type' => ['required', Rule::in(['objective', 'plan'])],
            'main_id' => ['required', 'integer'],
            'maintenance' => ['nullable', 'array', 'max:2'],
            'maintenance.*.type' => ['required_with:maintenance', Rule::in(['objective', 'habit'])],
            'maintenance.*.id' => ['required_with:maintenance', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'what_worked.required' => 'Contá qué funcionó, aunque sea una línea.',
            'what_blocked.required' => 'Contá qué se interpuso, aunque sea una línea.',
            'main_type.required' => 'Elegí la prioridad principal de la semana que viene.',
            'main_id.required' => 'Elegí la prioridad principal de la semana que viene.',
            'maintenance.max' => SubmitWeeklyReview::TOO_MANY_MAINTENANCE,
        ];
    }
}
