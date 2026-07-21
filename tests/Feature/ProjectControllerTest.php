<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('guests are redirected to login when visiting the projects index', function () {
    $response = $this->get('/projects');

    $response->assertRedirect('/login');
});

test('the projects index renders as a full page with the built Vite assets', function () {
    // Unlike the Inertia-headers test below, this hits the real Blade shell
    // (`resources/views/app.blade.php`), which requires the compiled
    // `resources/js/pages/projects/index.tsx` asset to exist in the Vite
    // manifest. This is the regression test the T-8.8 plan asked for.
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/projects');

    $response->assertOk();
});

test('a member only sees the projects they belong to, each with its issue count', function () {
    $user = User::factory()->create();

    $ownProject = Project::factory()->create(['name' => 'Mine']);
    $ownProject->members()->attach($user);
    $column = $ownProject->boardColumns()->create(['name' => 'To Do', 'position' => 0]);
    Issue::factory()->for($ownProject)->count(2)->create([
        'board_column_id' => $column->id,
        'reporter_id' => $user->id,
    ]);

    $otherProject = Project::factory()->create(['name' => 'Not mine']);
    $otherProject->members()->attach(User::factory()->create());

    $response = $this->actingAs($user)->get('/projects', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'projects/index');

    $projects = $response->json('props.projects');

    expect($projects)->toHaveCount(1)
        ->and($projects[0]['id'])->toBe($ownProject->id)
        ->and($projects[0]['issues_count'])->toBe(2);
});

test('an authenticated user can create a project and becomes its owner and member', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/projects', [
        'key' => 'ENG',
        'name' => 'Engineering',
        'description' => 'Core engineering work',
    ]);

    $response->assertRedirect(route('projects.index', absolute: false));

    $project = Project::where('key', 'ENG')->firstOrFail();

    expect($project->owner_id)->toBe($user->id)
        ->and($project->name)->toBe('Engineering')
        ->and($project->boardColumns)->toHaveCount(3)
        ->and($project->members->pluck('id')->all())->toBe([$user->id]);
});

test('creating a project validates the key format, uniqueness, and name', function (array $payload, string $invalidField) {
    $user = User::factory()->create();
    Project::factory()->create(['key' => 'DUP']);

    $response = $this->actingAs($user)->post('/projects', $payload);

    $response->assertSessionHasErrors($invalidField);
})->with([
    'lowercase key' => [['key' => 'web', 'name' => 'Website'], 'key'],
    'key starting with a digit' => [['key' => '1AB', 'name' => 'Website'], 'key'],
    'duplicate key' => [['key' => 'DUP', 'name' => 'Website'], 'key'],
    'missing name' => [['key' => 'WEB', 'name' => ''], 'name'],
]);

test('the owner can update their project, including keeping the same key', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id, 'key' => 'WEB', 'name' => 'Website']);
    $project->members()->attach($owner);

    $response = $this->actingAs($owner)->put("/projects/{$project->id}", [
        'key' => 'WEB',
        'name' => 'Website Revamp',
        'description' => 'Updated description',
    ]);

    $response->assertRedirect(route('projects.index', absolute: false));

    $project->refresh();

    expect($project->key)->toBe('WEB')
        ->and($project->name)->toBe('Website Revamp')
        ->and($project->description)->toBe('Updated description');
});

test('the owner cannot update their project key to one already used by another project', function () {
    $owner = User::factory()->create();
    Project::factory()->create(['key' => 'TAKEN']);
    $project = Project::factory()->create(['owner_id' => $owner->id, 'key' => 'WEB']);
    $project->members()->attach($owner);

    $response = $this->actingAs($owner)->put("/projects/{$project->id}", [
        'key' => 'TAKEN',
        'name' => $project->name,
    ]);

    $response->assertSessionHasErrors('key');
    expect($project->fresh()->key)->toBe('WEB');
});

test('a non-owner member cannot update a project', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id, 'key' => 'WEB', 'name' => 'Website']);
    $project->members()->attach([$owner->id, $member->id]);

    $response = $this->actingAs($member)->put("/projects/{$project->id}", [
        'key' => 'WEB',
        'name' => 'Hijacked',
    ]);

    $response->assertForbidden();
    expect($project->fresh()->name)->toBe('Website');
});

test('a guest cannot update a project', function () {
    $project = Project::factory()->create();

    $response = $this->put("/projects/{$project->id}", [
        'key' => $project->key,
        'name' => 'Should not apply',
    ]);

    $response->assertRedirect('/login');
});
