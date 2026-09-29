<?php

namespace Database\Factories;

use App\Enums\ProposalStatus;
use App\Models\AiProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProposal>
 */
class AiProposalFactory extends Factory
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
            'kind' => 'retire',
            'payload' => [],
            'summary' => fake()->sentence(8),
            'status' => ProposalStatus::Pending,
            'source' => 'mcp',
            'decided_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['status' => ProposalStatus::Accepted, 'decided_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['status' => ProposalStatus::Rejected, 'decided_at' => now()]);
    }
}
