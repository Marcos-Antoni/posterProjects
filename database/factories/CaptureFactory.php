<?php

namespace Database\Factories;

use App\Enums\CaptureSource;
use App\Models\Capture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Capture>
 */
class CaptureFactory extends Factory
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
            'text' => fake()->sentence(6),
            'source' => CaptureSource::Web,
            'triaged_at' => null,
            'result_type' => null,
            'result_id' => null,
            'retired_at' => null,
        ];
    }

    public function triaged(string $resultType, int $resultId): static
    {
        return $this->state(fn (): array => [
            'triaged_at' => now(),
            'result_type' => $resultType,
            'result_id' => $resultId,
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['retired_at' => now()]);
    }
}
