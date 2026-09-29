<?php

namespace Database\Factories;

use App\Models\Objective;
use App\Models\User;
use App\Models\WeeklyPriority;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyPriority>
 */
class WeeklyPriorityFactory extends Factory
{
    /**
     * Define the model's default state: this week's priority is an objective.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $week = WeeklyPriority::currentWeek();

        return [
            'user_id' => User::factory(),
            'iso_year' => $week['year'],
            'iso_week' => $week['week'],
            'main_type' => 'objective',
            'main_id' => fn (array $attributes): int => Objective::factory()->create(['user_id' => $attributes['user_id']])->id,
            'maintenance' => null,
        ];
    }
}
