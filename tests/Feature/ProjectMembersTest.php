<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a user can be attached to a project as a member', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $project->members()->attach($user);

    expect($project->members)->toHaveCount(1)
        ->and($project->members->first()->id)->toBe($user->id);
});

test('a project exposes all of its members', function () {
    $project = Project::factory()->create();
    $members = User::factory()->count(3)->create();

    $project->members()->attach($members);

    expect($project->members)->toHaveCount(3)
        ->and($project->members->pluck('id')->sort()->values()->all())
        ->toBe($members->pluck('id')->sort()->values()->all());
});

test('a user exposes all projects they belong to', function () {
    $user = User::factory()->create();
    $projects = Project::factory()->count(2)->create();

    foreach ($projects as $project) {
        $project->members()->attach($user);
    }

    expect($user->projects)->toHaveCount(2)
        ->and($user->projects->pluck('id')->sort()->values()->all())
        ->toBe($projects->pluck('id')->sort()->values()->all());
});

test('the same user cannot be attached to a project twice', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $project->members()->attach($user);

    expect(fn () => $project->members()->attach($user))
        ->toThrow(QueryException::class);
});
