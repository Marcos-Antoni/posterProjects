<?php

use App\Models\Project;
use App\Models\User;

test('sidebarProjects only includes projects the authenticated user is a member of, excluding archived ones', function () {
    $user = User::factory()->create();

    $ownProject = Project::factory()->create(['key' => 'MIN', 'name' => 'Mine']);
    $ownProject->members()->attach($user);

    $archivedProject = Project::factory()->create(['key' => 'ARC', 'name' => 'Archived']);
    $archivedProject->members()->attach($user);
    $archivedProject->delete();

    $othersProject = Project::factory()->create(['key' => 'OTR', 'name' => 'Not mine']);
    $othersProject->members()->attach(User::factory()->create());

    $response = $this->actingAs($user)->get('/projects', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();

    $sidebarProjects = $response->json('props.sidebarProjects');

    expect($sidebarProjects)->toHaveCount(1)
        ->and($sidebarProjects[0])->toBe([
            'id' => $ownProject->id,
            'key' => $ownProject->key,
            'name' => $ownProject->name,
        ]);
});

test('sidebarProjects is empty for guests', function () {
    $response = $this->get('/login', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.sidebarProjects'))->toBe([]);
});
