<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `evidence` is required for milestones only; `CheckItem` enforces it so
     * the rule is the same on the web, the API and MCP. `image` (screen 12,
     * the milestone summit) is optional, images only, up to 5 MB.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'evidence' => ['nullable', 'string', 'max:2000'],
            'link' => ['nullable', 'url', 'max:2048'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evidence.max' => 'La evidencia puede tener hasta :max caracteres.',
            'link.url' => 'El enlace no es una dirección válida.',
            'link.max' => 'El enlace es demasiado largo.',
            'image.image' => 'Ese formato no se acepta: usá JPG, PNG o WebP.',
            'image.mimes' => 'Ese formato no se acepta: usá JPG, PNG o WebP.',
            'image.max' => 'La imagen puede pesar hasta 5 MB.',
        ];
    }
}
