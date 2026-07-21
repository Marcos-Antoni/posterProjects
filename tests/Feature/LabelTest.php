<?php

use App\Models\Label;
use App\Models\Project;
use Illuminate\Database\QueryException;

test('label persists with expected attributes', function () {
    $project = Project::factory()->create();
    $label = Label::factory()->for($project)->create(['name' => 'bug']);

    expect($label)->toBeInstanceOf(Label::class)
        ->and($label->project_id)->toBe($project->id)
        ->and($label->name)->toBe('bug');

    $this->assertModelExists($label);
});

test('label name is unique per project', function () {
    $project = Project::factory()->create();
    Label::factory()->for($project)->create(['name' => 'bug']);

    expect(fn () => Label::factory()->for($project)->create(['name' => 'bug']))
        ->toThrow(QueryException::class);
});

test('the same label name can be reused across different projects', function () {
    $projectOne = Project::factory()->create();
    $projectTwo = Project::factory()->create();

    Label::factory()->for($projectOne)->create(['name' => 'bug']);
    $labelTwo = Label::factory()->for($projectTwo)->create(['name' => 'bug']);

    $this->assertModelExists($labelTwo);
});

test('a project exposes its labels', function () {
    $project = Project::factory()->create();
    Label::factory()->for($project)->count(3)->create();

    $otherProject = Project::factory()->create();
    Label::factory()->for($otherProject)->create();

    expect($project->labels)->toHaveCount(3);
});
