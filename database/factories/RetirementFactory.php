<?php

namespace Database\Factories;

use App\Enums\RetirableKind;
use App\Enums\RetirementDecision;
use App\Models\Item;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Retirement>
 */
class RetirementFactory extends Factory
{
    /**
     * Define the model's default state: a task archived as-is. Prefer the
     * `RetireElement` action in tests; this factory only writes the row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'retirable_type' => 'item',
            'retirable_id' => fn (): int => Item::factory()->retired()->create()->id,
            'user_id' => fn (array $attributes): int => Item::withRetired()->findOrFail((int) $attributes['retirable_id'])->objective->user_id,
            'objective_id' => fn (array $attributes): int => Item::withRetired()->findOrFail((int) $attributes['retirable_id'])->objective_id,
            'kind' => RetirableKind::Task,
            'parent_id' => null,
            'reason' => 'demasiado grande para arrancar',
            'decision' => RetirementDecision::ArchiveAsIs,
            'decision_payload' => null,
            'prior_state' => 'available',
            'retired_at' => now(),
            'restored_at' => null,
        ];
    }
}
