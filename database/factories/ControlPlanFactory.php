<?php

namespace Database\Factories;

use App\Models\ControlPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ControlPlan>
 */
class ControlPlanFactory extends Factory
{
    /**
     * Define the model's default state: a complete 5-point plan. Attach it
     * with `->for($objectiveOrPlan, 'plannable')`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outcome' => fake()->sentence(),
            'deadline' => now()->addMonth()->toDateString(),
            'metric_name' => fake()->words(3, true),
            'metric_target' => 10,
            'metric_current' => 1,
            'risks' => [fake()->sentence()],
            'contingency' => 'Cuando '.fake()->word().' '.fake()->word().', entonces '.fake()->word().' '.fake()->word().'.',
        ];
    }

    public function incomplete(): static
    {
        return $this->state(fn (): array => [
            'deadline' => null,
            'metric_name' => null,
            'metric_target' => null,
            'metric_current' => null,
            'risks' => null,
            'contingency' => null,
        ]);
    }
}
