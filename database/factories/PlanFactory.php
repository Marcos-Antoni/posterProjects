<?php

namespace Database\Factories;

use App\Enums\PlanState;
use App\Models\ControlPlan;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use WeakMap;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state: an active plan appended at the end of
     * its objective.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'objective_id' => Objective::factory(),
            'title' => fake()->sentence(3),
            'state' => PlanState::Active,
            'level' => null,
            'position' => null,
        ];
    }

    /**
     * Append at the end of the objective unless a position was given. The
     * position is settled after insert, so `count(n)` appends 0..n-1 too.
     */
    public function configure(): static
    {
        $appended = new WeakMap;

        return $this
            ->afterMaking(function (Plan $plan) use ($appended): void {
                if ($plan->getAttribute('position') === null) {
                    $plan->position = 0;
                    $appended[$plan] = true;
                }
            })
            ->afterCreating(function (Plan $plan) use ($appended): void {
                if (isset($appended[$plan])) {
                    $plan->update(['position' => Plan::query()
                        ->where('objective_id', $plan->objective_id)
                        ->where('id', '<', $plan->id)
                        ->count()]);
                }
            });
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['state' => PlanState::Draft]);
    }

    /**
     * Attach a complete 5-point plan.
     */
    public function withControlPlan(): static
    {
        return $this->afterCreating(function (Plan $plan): void {
            ControlPlan::factory()->for($plan, 'plannable')->create();
        });
    }
}
