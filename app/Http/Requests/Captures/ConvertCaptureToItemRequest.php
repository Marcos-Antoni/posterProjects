<?php

namespace App\Http\Requests\Captures;

use App\Actions\Items\AddItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Triage: convert a capture into a task (screen 16). The objective and plan
 * choose where it lands; the 2-minute version is required, same as adding an
 * item anywhere else.
 */
class ConvertCaptureToItemRequest extends FormRequest
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
            'objective_key' => ['required', 'string', 'max:10'],
            'plan_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'two_minute_version' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'objective_key.required' => 'Elegí un objetivo.',
            'plan_id.required' => 'Elegí un plan.',
            'title.required' => 'Falta el título.',
            'two_minute_version.required' => AddItem::MISSING_TWO_MINUTE,
        ];
    }
}
