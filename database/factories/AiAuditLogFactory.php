<?php

namespace Database\Factories;

use App\Models\AiAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAuditLog>
 */
class AiAuditLogFactory extends Factory
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
            'source' => 'ai-mcp',
            'tier' => 'minor',
            'operation' => 'create-capture',
            'target_type' => null,
            'target_id' => null,
            'before' => [],
            'after' => [],
            'proposal_id' => null,
        ];
    }
}
