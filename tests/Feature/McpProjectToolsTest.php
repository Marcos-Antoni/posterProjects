<?php

use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Projects\ArchiveProject;
use App\Mcp\Tools\Projects\CreateProject;
use App\Mcp\Tools\Projects\ForceDeleteProject;
use App\Mcp\Tools\Projects\ListProjects;
use App\Mcp\Tools\Projects\ListTrashedProjects;
use App\Mcp\Tools\Projects\RestoreProject;
use App\Mcp\Tools\Projects\UpdateProject;
use App\Models\Project;
use App\Models\User;

test('list-projects returns only member projects with their board urls', function () {
    $user = User::factory()->create();
    $mine = Project::factory()->create(['key' => 'MINE', 'owner_id' => $user->id]);
    $mine->members()->attach($user->id);
    Project::factory()->create(['key' => 'OTHER']);

    $response = PosterServer::actingAs($user)->tool(ListProjects::class);

    $response->assertOk()
        ->assertSee('MINE')
        ->assertSee(route('projects.board', ['project' => 'MINE']))
        ->assertDontSee('OTHER');
});

test('create-project creates the project with default columns and membership', function () {
    $user = User::factory()->create();

    $response = PosterServer::actingAs($user)->tool(CreateProject::class, [
        'key' => 'ENG',
        'name' => 'Engineering',
        'description' => 'Core work',
    ]);

    $response->assertOk()
        ->assertSee('ENG')
        ->assertSee(route('projects.board', ['project' => 'ENG']));

    $project = Project::query()->where('key', 'ENG')->firstOrFail();

    expect($project->owner_id)->toBe($user->id)
        ->and($project->boardColumns()->pluck('name')->all())->toBe(['To Do', 'In Progress', 'Done'])
        ->and($project->members()->whereKey($user->id)->exists())->toBeTrue();
});

test('create-project rejects a duplicate key with the web validation message', function () {
    $user = User::factory()->create();
    Project::factory()->create(['key' => 'ENG']);

    $response = PosterServer::actingAs($user)->tool(CreateProject::class, [
        'key' => 'ENG',
        'name' => 'Duplicate',
    ]);

    $response->assertHasErrors(['Ya existe un proyecto con esa clave.']);

    expect(Project::query()->where('name', 'Duplicate')->exists())->toBeFalse();
});

test('update-project keeps the same key thanks to the replayed unique-ignore rule', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['key' => 'ENG', 'owner_id' => $user->id, 'name' => 'Old name']);
    $project->members()->attach($user->id);

    $response = PosterServer::actingAs($user)->tool(UpdateProject::class, [
        'project_key' => 'ENG',
        'key' => 'ENG',
        'name' => 'New name',
    ]);

    $response->assertOk()->assertSee('New name');

    expect($project->refresh()->name)->toBe('New name');
});

test('update-project is denied for a member who is not the owner', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['key' => 'ENG', 'name' => 'Original']);
    $project->members()->attach($member->id);

    $response = PosterServer::actingAs($member)->tool(UpdateProject::class, [
        'project_key' => 'ENG',
        'key' => 'ENG',
        'name' => 'Hijacked',
    ]);

    $response->assertHasErrors();

    expect($project->refresh()->name)->toBe('Original');
});

test('archive-project soft deletes and restore-project brings it back', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['key' => 'ENG', 'owner_id' => $user->id]);
    $project->members()->attach($user->id);

    PosterServer::actingAs($user)->tool(ArchiveProject::class, ['project_key' => 'ENG'])
        ->assertOk();

    expect($project->refresh()->trashed())->toBeTrue();

    $trash = PosterServer::actingAs($user)->tool(ListTrashedProjects::class);
    $trash->assertOk()
        ->assertSee('ENG')
        ->assertSee(route('projects.trash'));

    PosterServer::actingAs($user)->tool(RestoreProject::class, ['project_key' => 'ENG'])
        ->assertOk();

    expect($project->refresh()->trashed())->toBeFalse();
});

test('archive-project is denied for a non-owner member', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['key' => 'ENG']);
    $project->members()->attach($member->id);

    $response = PosterServer::actingAs($member)->tool(ArchiveProject::class, [
        'project_key' => 'ENG',
    ]);

    $response->assertHasErrors();

    expect($project->refresh()->trashed())->toBeFalse();
});

test('force-delete-project permanently removes a trashed project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['key' => 'ENG', 'owner_id' => $user->id]);
    $project->delete();

    $response = PosterServer::actingAs($user)->tool(ForceDeleteProject::class, [
        'project_key' => 'ENG',
    ]);

    $response->assertOk();

    expect(Project::withTrashed()->find($project->id))->toBeNull();
});

test('force-delete-project refuses a project that is not in the trash', function () {
    $user = User::factory()->create();
    Project::factory()->create(['key' => 'ENG', 'owner_id' => $user->id]);

    $response = PosterServer::actingAs($user)->tool(ForceDeleteProject::class, [
        'project_key' => 'ENG',
    ]);

    $response->assertHasErrors(['Trashed project not found: ENG']);

    expect(Project::query()->where('key', 'ENG')->exists())->toBeTrue();
});
