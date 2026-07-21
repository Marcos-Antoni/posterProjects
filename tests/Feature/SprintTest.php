<?php

use App\Models\Project;
use App\Models\Sprint;

test('sprint persists with expected attributes', function () {
    $project = Project::factory()->create();
    $sprint = Sprint::factory()->for($project)->create([
        'name' => 'Sprint 1',
        'goal' => 'Ship the MVP',
    ]);

    expect($sprint)->toBeInstanceOf(Sprint::class)
        ->and($sprint->project_id)->toBe($project->id)
        ->and($sprint->name)->toBe('Sprint 1')
        ->and($sprint->goal)->toBe('Ship the MVP');

    $this->assertModelExists($sprint);
});

test('sprint goal is nullable', function () {
    $sprint = Sprint::factory()->create(['goal' => null]);

    expect($sprint->goal)->toBeNull();
    $this->assertModelExists($sprint);
});

test('sprint stores start and end dates as carbon instances', function () {
    $sprint = Sprint::factory()->create([
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-14',
    ]);

    expect($sprint->start_date->toDateString())->toBe('2026-08-01')
        ->and($sprint->end_date->toDateString())->toBe('2026-08-14');
});

test('a project exposes its sprints', function () {
    $project = Project::factory()->create();
    Sprint::factory()->for($project)->count(3)->create();

    $otherProject = Project::factory()->create();
    Sprint::factory()->for($otherProject)->create();

    expect($project->sprints)->toHaveCount(3);
});
