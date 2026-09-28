<?php

namespace App\Http\Requests;

use App\Actions\Retirement\RetireElement;
use App\Enums\RetirementDecision;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The retire flow (screen 23): reason + content decision + its details.
 * The reason length and the decision's own rules live in `RetireElement`,
 * so the web, MCP and a future proposal apply exactly the same checks.
 */
class RetireElementRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:'.RetireElement::REASON_MAX_LENGTH],
            'decision' => ['nullable', Rule::enum(RetirementDecision::class)],
            'target' => ['nullable'],
            'parts' => ['nullable', 'array', 'max:10'],
            'parts.*' => ['array'],
            'parts.*.title' => ['nullable', 'string', 'max:255'],
            'parts.*.two_minute_version' => ['nullable', 'string', 'max:255'],
            'assignments' => ['nullable', 'array'],
            'assignments.*' => ['integer', 'min:0', 'max:9'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => RetireElement::REASON_TOO_SHORT,
            'reason.string' => RetireElement::REASON_TOO_SHORT,
            'reason.max' => 'La razón tiene como máximo :max caracteres.',
            'decision.enum' => 'Elegí mover, dividir o archivar tal cual.',
            'parts.max' => 'Dividí en hasta :max partes.',
            'parts.*.title.max' => 'Cada parte tiene como máximo :max caracteres.',
            'parts.*.two_minute_version.max' => 'Cada versión de 2 minutos tiene como máximo :max caracteres.',
        ];
    }

    public function decision(): ?RetirementDecision
    {
        $decision = $this->validated('decision');

        return is_string($decision) ? RetirementDecision::from($decision) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_filter([
            'target' => $this->validated('target'),
            'parts' => $this->validated('parts'),
            'assignments' => $this->validated('assignments'),
        ], fn (mixed $value): bool => $value !== null);
    }
}
