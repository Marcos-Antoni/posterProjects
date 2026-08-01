<?php

namespace Database\Factories;

use App\Models\QrLoginPass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QrLoginPass>
 */
class QrLoginPassFactory extends Factory
{
    /**
     * Default state: a live, unconsumed pass minted just now, expiring in
     * 60 seconds — mirrors `QrLoginController`'s TTL (design.md Decision 2).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', $this->faker->uuid()),
            'expires_at' => now()->addSeconds(60),
            'consumed_at' => null,
            'consumed_ip' => null,
        ];
    }

    /**
     * A pass whose TTL is already in the past. Never consumed.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subSeconds(1),
        ]);
    }

    /**
     * A pass that was already redeemed. Retained as the audit trail
     * (design.md Decision 3) — never deleted on consumption.
     */
    public function consumed(): static
    {
        return $this->state(fn (): array => [
            'consumed_at' => now(),
            'consumed_ip' => $this->faker->ipv4(),
        ]);
    }
}
