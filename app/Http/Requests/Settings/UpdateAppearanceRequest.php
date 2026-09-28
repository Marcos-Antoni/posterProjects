<?php

namespace App\Http\Requests\Settings;

use App\Enums\Appearance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppearanceRequest extends FormRequest
{
    /**
     * Any authenticated user may change their own theme (the route sits
     * behind `auth`; there is no one else's preference to reach).
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The only editable setting on this page: nothing else about the user
     * (name, email, password) is accepted here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appearance' => ['required', 'string', Rule::enum(Appearance::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'appearance.required' => 'Elegí un tema: claro, oscuro o sistema.',
            'appearance.string' => 'Elegí un tema: claro, oscuro o sistema.',
            'appearance.enum' => 'Elegí un tema: claro, oscuro o sistema.',
        ];
    }
}
