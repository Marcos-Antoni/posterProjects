<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'key' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'next_issue_number' => 1,
        ];
    }
}
