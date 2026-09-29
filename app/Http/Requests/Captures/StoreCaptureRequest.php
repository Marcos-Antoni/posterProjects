<?php

namespace App\Http\Requests\Captures;

use App\Actions\Captures\CreateCapture;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The quick-capture overlay (screen 17) and the inbox's own capture control:
 * one field, nothing else (capture-inbox spec).
 */
class StoreCaptureRequest extends FormRequest
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
            'text' => ['required', 'string', 'max:'.CreateCapture::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required' => CreateCapture::EMPTY,
            'text.max' => 'La captura tiene como máximo :max caracteres.',
        ];
    }
}
