<?php

namespace Database\Factories;

use App\Enums\ControlZone;
use App\Models\ControlMapEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ControlMapEntry>
 */
class ControlMapEntryFactory extends Factory
{
    /**
     * Define the model's default state. Attach it with
     * `->for($objectiveOrPlan, 'plannable')`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'zone' => ControlZone::Mine,
            'text' => fake()->sentence(4),
            'position' => 0,
        ];
    }
}
