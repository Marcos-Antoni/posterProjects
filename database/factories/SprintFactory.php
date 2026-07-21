<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sprint>
 */
class SprintFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-1 month', 'now');
        $endDate = (clone $startDate)->modify('+2 weeks');

        return [
            'project_id' => Project::factory(),
            'name' => fake()->words(2, true),
            'goal' => fake()->sentence(),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }
}
