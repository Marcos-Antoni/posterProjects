<?php

namespace Database\Factories;

use App\Enums\ItemKind;
use App\Models\Item;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use WeakMap;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * Define the model's default state: an available task. The objective and
     * the number come from the plan (number via the row-locked allocator).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'kind' => ItemKind::Task,
            'title' => fake()->sentence(3),
            'description' => null,
            'two_minute_version' => 'abrir '.fake()->word().' '.fake()->word(),
            'target_date' => null,
            'is_active' => false,
            'completed_at' => null,
            'retired_at' => null,
        ];
    }

    /**
     * Keep `objective_id` consistent with the plan, allocate the number and
     * append at the end of the plan unless they were given explicitly (the
     * position is settled after insert, so `count(n)` appends 0..n-1).
     */
    public function configure(): static
    {
        $appended = new WeakMap;

        return $this
            ->afterMaking(function (Item $item) use ($appended): void {
                $plan = Plan::withRetired()->findOrFail($item->plan_id);

                $item->objective_id ??= $plan->objective_id;
                $item->number ??= $plan->objective->allocateNextItemNumber();

                if ($item->getAttribute('position') === null) {
                    $item->position = 0;
                    $appended[$item] = true;
                }
            })
            ->afterCreating(function (Item $item) use ($appended): void {
                if (isset($appended[$item])) {
                    $item->update(['position' => Item::withRetired()
                        ->where('plan_id', $item->plan_id)
                        ->where('id', '<', $item->id)
                        ->count()]);
                }
            });
    }

    public function milestone(): static
    {
        return $this->state(fn (): array => ['kind' => ItemKind::Milestone]);
    }

    public function done(): static
    {
        return $this->state(fn (): array => ['completed_at' => now()]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['is_active' => true]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['retired_at' => now()]);
    }
}
