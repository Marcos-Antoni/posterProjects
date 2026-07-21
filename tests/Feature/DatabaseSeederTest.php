<?php

use App\Enums\IssueType;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('the database seeder creates a complete demo project with integrity', function () {
    $this->seed();

    $project = Project::where('key', 'DEMO')->firstOrFail();

    $owner = User::where('email', 'test@example.com')->firstOrFail();
    expect($project->owner_id)->toBe($owner->id);

    // Owner auto-attached as a project member.
    expect($project->members->pluck('id'))->toContain($owner->id);

    // Exactly the 3 default board columns, in order.
    expect($project->boardColumns)->toHaveCount(3)
        ->and($project->boardColumns->pluck('name')->all())->toBe(['To Do', 'In Progress', 'Done'])
        ->and($project->boardColumns->pluck('position')->all())->toBe([0, 1, 2]);

    // At least one sprint.
    expect($project->sprints->count())->toBeGreaterThanOrEqual(1);

    $issues = Issue::where('project_id', $project->id)->orderBy('number')->get();

    // Sequential numbering without gaps: PROJ-1..PROJ-N.
    expect($issues->pluck('number')->all())->toBe(range(1, $issues->count()));
    expect($issues->pluck('key')->all())->toBe(
        collect(range(1, $issues->count()))->map(fn (int $n): string => "DEMO-{$n}")->all()
    );

    // Several distinct issue types are represented.
    expect($issues->pluck('type')->unique()->count())->toBeGreaterThanOrEqual(3);

    // Several distinct priorities are represented.
    expect($issues->pluck('priority')->unique()->count())->toBeGreaterThanOrEqual(2);

    // Some issues are in the sprint, others are in the backlog.
    expect($issues->whereNotNull('sprint_id')->count())->toBeGreaterThanOrEqual(1);
    expect($issues->whereNull('sprint_id')->count())->toBeGreaterThanOrEqual(1);

    // At least one parent -> child relationship.
    expect($issues->whereNotNull('parent_id')->count())->toBeGreaterThanOrEqual(1);

    // Issues span more than one distinct board column.
    expect($issues->pluck('board_column_id')->unique()->count())->toBeGreaterThanOrEqual(2);

    // Every issue's board column belongs to the issue's own project.
    $columnProjectIds = $project->boardColumns->pluck('project_id', 'id');
    foreach ($issues as $issue) {
        expect($columnProjectIds->get($issue->board_column_id))->toBe($issue->project_id);
    }

    // Every parent_id points to an issue that belongs to the same project.
    $issueProjectIds = $issues->pluck('project_id', 'id');
    foreach ($issues->whereNotNull('parent_id') as $issue) {
        expect($issueProjectIds->get($issue->parent_id))->toBe($issue->project_id);
    }

    // Labels exist for the project and are attached to at least one issue.
    expect($project->labels->count())->toBeGreaterThanOrEqual(1);
    expect($issues->sum(fn (Issue $issue): int => $issue->labels->count()))->toBeGreaterThanOrEqual(1);

    // Comments exist on at least one issue.
    expect($issues->sum(fn (Issue $issue): int => $issue->comments->count()))->toBeGreaterThanOrEqual(1);

    // Sanity: every declared IssueType case is representable (enum cast works end to end).
    expect($issues->first()->type)->toBeInstanceOf(IssueType::class);
});
