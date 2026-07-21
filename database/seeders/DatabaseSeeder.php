<?php

namespace Database\Seeders;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->seedDemoProject($owner);
    }

    /**
     * Seed a fully populated demo project owned by the given user:
     * default board columns, a sprint, varied issues (types, priorities,
     * backlog vs. sprint, a parent/child pair, spread across columns),
     * labels attached to issues, and comments.
     */
    private function seedDemoProject(User $owner): void
    {
        $teammates = User::factory()->count(2)->create();
        $reporter = $teammates[0];
        $assignee = $teammates[1];

        $project = Project::createWithDefaultColumns([
            'owner_id' => $owner->id,
            'key' => 'DEMO',
            'name' => 'Demo Project',
            'description' => 'A fully populated demo project for local development.',
        ]);

        $project->members()->attach([$reporter->id, $assignee->id]);

        [$toDo, $inProgress, $done] = $project->boardColumns;

        $sprint = Sprint::factory()->for($project)->create([
            'name' => 'Sprint 1',
        ]);

        $bugLabel = Label::factory()->for($project)->create(['name' => 'bug']);
        $urgentLabel = Label::factory()->for($project)->create(['name' => 'urgent']);
        Label::factory()->for($project)->create(['name' => 'frontend']);

        $epic = Issue::factory()->for($project)->epic()->create([
            'board_column_id' => $toDo->id,
            'sprint_id' => null,
            'reporter_id' => $owner->id,
            'assignee_id' => null,
            'priority' => IssuePriority::Medium,
            'title' => 'Launch the new marketing site',
        ]);

        $story = Issue::factory()->for($project)->story()->create([
            'board_column_id' => $inProgress->id,
            'sprint_id' => $sprint->id,
            'parent_id' => $epic->id,
            'reporter_id' => $reporter->id,
            'assignee_id' => $assignee->id,
            'priority' => IssuePriority::High,
            'title' => 'Build the pricing page',
        ]);

        Issue::factory()->for($project)->task()->create([
            'board_column_id' => $done->id,
            'sprint_id' => null,
            'reporter_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'priority' => IssuePriority::Low,
            'title' => 'Write onboarding documentation',
        ]);

        $bug = Issue::factory()->for($project)->bug()->create([
            'board_column_id' => $toDo->id,
            'sprint_id' => $sprint->id,
            'reporter_id' => $reporter->id,
            'assignee_id' => $owner->id,
            'priority' => IssuePriority::Highest,
            'title' => 'Checkout button unresponsive on mobile',
        ]);

        Issue::factory()->for($project)->create([
            'type' => IssueType::Task,
            'board_column_id' => $inProgress->id,
            'sprint_id' => null,
            'reporter_id' => $owner->id,
            'assignee_id' => null,
            'priority' => IssuePriority::Lowest,
            'title' => 'Upgrade CI runner images',
        ]);

        $story->labels()->attach([$urgentLabel->id]);
        $bug->labels()->attach([$bugLabel->id, $urgentLabel->id]);

        Comment::factory()->for($epic, 'issue')->for($owner, 'author')->create([
            'body' => 'Kicking this off — let\'s scope the sub-tasks.',
        ]);

        Comment::factory()->for($story, 'issue')->for($reporter, 'author')->create([
            'body' => 'Pricing tiers are still pending finance sign-off.',
        ]);

        Comment::factory()->for($bug, 'issue')->for($assignee, 'author')->create([
            'body' => 'Reproduced on iOS Safari, investigating now.',
        ]);
    }
}
