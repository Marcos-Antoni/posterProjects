<?php

namespace Database\Factories;

use App\Enums\ReviewKind;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => ReviewKind::Weekly,
            'objective_id' => null,
            'answers' => ['what_worked' => fake()->sentence(), 'what_blocked' => fake()->sentence()],
        ];
    }

    public function objectiveClose(int $objectiveId): static
    {
        return $this->state(fn (): array => [
            'kind' => ReviewKind::Objective,
            'objective_id' => $objectiveId,
            'answers' => [
                'what_learned' => fake()->sentence(),
                'what_repeat' => fake()->sentence(),
                'what_change' => fake()->sentence(),
            ],
        ]);
    }
}
