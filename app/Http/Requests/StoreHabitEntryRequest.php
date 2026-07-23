<?php

namespace App\Http\Requests;

use App\Enums\HabitType;
use App\Models\Habit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreHabitEntryRequest extends FormRequest
{
    /**
     * Only the habit's owner may record entries against it.
     */
    public function authorize(): bool
    {
        /** @var Habit $habit */
        $habit = $this->route('habit');

        return $this->user()?->can('logEntry', $habit) ?? false;
    }

    /**
     * Quantitative habits require the partial amount being logged; yes/no
     * habits take no amount (the controller records 1 per check-in).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Habit $habit */
        $habit = $this->route('habit');

        return [
            'amount' => $habit->habit_type === HabitType::Quantitative
                ? ['required', 'integer', 'min:1']
                : ['exclude'],
        ];
    }

    /**
     * Archived habits keep their history but stop accepting entries
     * until they are reactivated.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Habit $habit */
                $habit = $this->route('habit');

                if ($habit->archived_at !== null) {
                    $validator->errors()->add(
                        'habit',
                        'No podés registrar en un hábito archivado.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'La cantidad es obligatoria.',
            'amount.integer' => 'La cantidad debe ser un número entero.',
            'amount.min' => 'La cantidad debe ser al menos :min.',
        ];
    }
}
