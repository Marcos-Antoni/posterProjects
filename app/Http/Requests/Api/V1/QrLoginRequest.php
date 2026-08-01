<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class QrLoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The grammar (`pposter_qr_v1:` + 43 base64url chars) matches exactly
     * what `MobileTokenQrController::store` mints — see design.md
     * "Payload grammar".
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'regex:/^pposter_qr_v1:[A-Za-z0-9_-]{43}$/'],
        ];
    }

    /**
     * Every rule shares the same Spanish message: a malformed pass must be
     * indistinguishable from an unknown, expired, or consumed one
     * (design.md "Requirement: Uniform Redemption Failure").
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'El código QR no es válido o expiró.',
            'token.string' => 'El código QR no es válido o expiró.',
            'token.regex' => 'El código QR no es válido o expiró.',
        ];
    }
}
