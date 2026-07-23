<?php

namespace Database\Factories;

use App\Models\Habit;
use App\Models\HabitDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HabitDay>
 */
class HabitDayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'habit_id' => Habit::factory(),
            'entry_date' => fake()->unique()->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'accumulated_amount' => 1,
            'completion_percent' => 100,
            'completed' => true,
            'planned_delta_minutes' => null,
        ];
    }

    public function incomplete(): static
    {
        return $this->state(fn (): array => [
            'completion_percent' => fake()->numberBetween(1, 99),
            'completed' => false,
        ]);
    }
}
