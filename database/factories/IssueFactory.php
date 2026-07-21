<?php

namespace Database\Factories;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'board_column_id' => BoardColumn::factory(),
            'sprint_id' => null,
            'parent_id' => null,
            'type' => fake()->randomElement(IssueType::cases()),
            'priority' => fake()->randomElement(IssuePriority::cases()),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'story_points' => fake()->optional()->numberBetween(1, 13),
            'due_date' => fake()->optional()->dateTimeBetween('now', '+2 months'),
            'assignee_id' => null,
            'reporter_id' => User::factory(),
            'position' => 0,
        ];
    }

    /**
     * Configure the factory to always allocate a valid sequential issue
     * number from the owning project, unless one was explicitly given.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Issue $issue) {
            if ($issue->getAttribute('number') === null) {
                $issue->number = Project::findOrFail($issue->project_id)->allocateNextIssueNumber();
            }
        });
    }

    public function epic(): static
    {
        return $this->state(fn (): array => ['type' => IssueType::Epic]);
    }

    public function story(): static
    {
        return $this->state(fn (): array => ['type' => IssueType::Story]);
    }

    public function task(): static
    {
        return $this->state(fn (): array => ['type' => IssueType::Task]);
    }

    public function bug(): static
    {
        return $this->state(fn (): array => ['type' => IssueType::Bug]);
    }
}
