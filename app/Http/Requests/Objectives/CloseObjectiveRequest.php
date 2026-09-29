<?php

namespace App\Http\Requests\Objectives;

use App\Actions\Objectives\CloseObjective;
use App\Actions\Retirement\RetireElement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Closing an objective (screen 13): the learning review's three answers plus
 * one keep/retire decision per linked habit.
 */
class CloseObjectiveRequest extends FormRequest
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
            'what_learned' => ['required', 'string', 'max:2000'],
            'what_repeat' => ['required', 'string', 'max:2000'],
            'what_change' => ['required', 'string', 'max:2000'],
            'habits' => ['array'],
            'habits.*.habit_id' => ['required', 'integer'],
            'habits.*.decision' => ['required', Rule::in(['keep', 'retire'])],
            'habits.*.reason' => ['nullable', 'string', 'max:'.RetireElement::REASON_MAX_LENGTH],
            'habits.*.relink_objective_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'what_learned.required' => CloseObjective::MISSING_ANSWER,
            'what_repeat.required' => CloseObjective::MISSING_ANSWER,
            'what_change.required' => CloseObjective::MISSING_ANSWER,
            'habits.*.decision.required' => CloseObjective::MISSING_DECISIONS,
        ];
    }
}
