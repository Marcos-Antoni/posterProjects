<?php

namespace Database\Factories;

use App\Enums\ObjectiveState;
use App\Models\ControlPlan;
use App\Models\Objective;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Objective>
 */
class ObjectiveFactory extends Factory
{
    /**
     * Define the model's default state: an active objective. Tests that need
     * its 5-point plan opt in with `withControlPlan()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => strtoupper(fake()->unique()->lexify('??????')),
            'title' => fake()->sentence(3),
            'identity_statement' => null,
            'state' => ObjectiveState::Active,
            'position' => 0,
            'next_item_number' => 1,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['state' => ObjectiveState::Draft]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['state' => ObjectiveState::Closed, 'closed_at' => now()]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['state' => ObjectiveState::Retired]);
    }

    /**
     * Attach a complete 5-point plan.
     */
    public function withControlPlan(): static
    {
        return $this->afterCreating(function (Objective $objective): void {
            ControlPlan::factory()->for($objective, 'plannable')->create();
        });
    }
}
