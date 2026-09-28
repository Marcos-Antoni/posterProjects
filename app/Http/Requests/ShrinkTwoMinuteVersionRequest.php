<?php

namespace App\Http\Requests;

use App\Actions\Items\ShrinkTwoMinuteVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ShrinkTwoMinuteVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The trimmed-empty and "same as now" checks live in the action, so the
     * web, the API (Phase 11) and MCP `shrink-step` (Phase 8) share them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'two_minute_version' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'two_minute_version.required' => ShrinkTwoMinuteVersion::MISSING,
            'two_minute_version.string' => ShrinkTwoMinuteVersion::MISSING,
            'two_minute_version.max' => 'La acción puede tener hasta :max caracteres.',
        ];
    }
}
