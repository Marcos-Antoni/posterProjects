<?php

namespace App\Http\Requests\Captures;

use App\Actions\Habits\HabitWriter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Triage: convert a capture into a habit (screen 16). Kept minimal — a
 * yes/no daily habit; the owner refines schedule, level and links from the
 * habit form afterward. The 2-minute version is required, same as anywhere
 * else a habit is created.
 */
class ConvertCaptureToHabitRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'two_minute_version' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Falta el nombre.',
            'two_minute_version.required' => HabitWriter::MISSING_TWO_MINUTE,
        ];
    }
}
