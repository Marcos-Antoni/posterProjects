<?php

use App\Models\Comment;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

test('the owner can archive a project and it disappears from their index', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id]);
    $project->members()->attach($owner);

    $response = $this->actingAs($owner)->delete("/projects/{$project->id}");

    $response->assertRedirect(route('projects.index', absolute: false));
    expect($project->fresh()->deleted_at)->not->toBeNull();

    $index = $this->actingAs($owner)->get('/projects', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    expect($index->json('props.projects'))->toBeEmpty();
});

test('a non-owner member cannot archive a project', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id]);
    $project->members()->attach([$owner->id, $member->id]);

    $response = $this->actingAs($member)->delete("/projects/{$project->id}");

    $response->assertForbidden();
    expect($project->fresh()->deleted_at)->toBeNull();
});

test('a guest cannot archive a project', function () {
    $project = Project::factory()->create();

    $response = $this->delete("/projects/{$project->id}");

    $response->assertRedirect('/login');
});

test('the owner can restore an archived project and it reappears in their index', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id]);
    $project->members()->attach($owner);
    $project->delete();

    $response = $this->actingAs($owner)->post("/projects/{$project->id}/restore");

    $response->assertRedirect(route('projects.trash', absolute: false));
    expect($project->fresh()->deleted_at)->toBeNull();
});

test('a non-owner member cannot restore an archived project', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id]);
    $project->members()->attach([$owner->id, $member->id]);
    $project->delete();

    $response = $this->actingAs($member)->post("/projects/{$project->id}/restore");

    $response->assertForbidden();
    expect($project->fresh()->deleted_at)->not->toBeNull();
});

test('the trash page lists only the authenticated owner\'s archived projects', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();

    $ownArchived = Project::factory()->create(['owner_id' => $owner->id, 'name' => 'Mine, archived']);
    $ownArchived->delete();

    $ownActive = Project::factory()->create(['owner_id' => $owner->id, 'name' => 'Mine, active']);

    $othersArchived = Project::factory()->create(['owner_id' => $otherOwner->id, 'name' => 'Not mine, archived']);
    $othersArchived->delete();

    $response = $this->actingAs($owner)->get('/projects/trash', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'projects/trash');

    $projects = $response->json('props.projects');

    expect($projects)->toHaveCount(1)
        ->and($projects[0]['id'])->toBe($ownArchived->id);
});

test('the trash listing includes how many issues and sprints each archived project has', function () {
    $owner = User::factory()->create();

    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'CNT',
        'name' => 'Counted',
    ]);
    [$toDo] = $project->boardColumns;

    Issue::factory()->for($project)->count(2)->create([
        'board_column_id' => $toDo->id,
        'reporter_id' => $owner->id,
    ]);

    Sprint::factory()->for($project)->create();

    $project->delete();

    $response = $this->actingAs($owner)->get('/projects/trash', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();

    $projects = $response->json('props.projects');

    expect($projects[0]['issues_count'])->toBe(2)
        ->and($projects[0]['sprints_count'])->toBe(1);
});

test('the trash page renders as a full page with the built Vite assets', function () {
    // Unlike the Inertia-headers test above, this hits the real Blade shell,
    // which requires the compiled `resources/js/pages/projects/trash.tsx`
    // asset to exist in the Vite manifest. This is the regression test the
    // T-8.9 plan asked for.
    $owner = User::factory()->create();

    $response = $this->actingAs($owner)->get('/projects/trash');

    $response->assertOk();
});

test('a guest cannot view the trash page', function () {
    $response = $this->get('/projects/trash');

    $response->assertRedirect('/login');
});

test('a non-owner member cannot force delete a project', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id]);
    $project->members()->attach([$owner->id, $member->id]);
    $project->delete();

    $response = $this->actingAs($member)->delete("/projects/{$project->id}/force");

    $response->assertForbidden();
    $this->assertDatabaseHas('projects', ['id' => $project->id]);
});

test('force deleting an archived project cascades to delete all of its child rows, but never touches users', function () {
    $owner = User::factory()->create();
    $reporter = User::factory()->create();

    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'CAS',
        'name' => 'Cascade Test',
    ]);

    [$toDo] = $project->boardColumns;

    $sprint = Sprint::factory()->for($project)->create();
    $label = Label::factory()->for($project)->create();

    $parentIssue = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => $sprint->id,
        'reporter_id' => $reporter->id,
    ]);

    $childIssue = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'parent_id' => $parentIssue->id,
        'reporter_id' => $reporter->id,
    ]);

    $childIssue->labels()->attach($label);

    Comment::factory()->for($childIssue, 'issue')->for($reporter, 'author')->create();

    $project->delete();

    $response = $this->actingAs($owner)->delete("/projects/{$project->id}/force");

    $response->assertRedirect(route('projects.trash', absolute: false));

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    $this->assertDatabaseMissing('board_columns', ['project_id' => $project->id]);
    $this->assertDatabaseMissing('sprints', ['id' => $sprint->id]);
    $this->assertDatabaseMissing('labels', ['id' => $label->id]);
    $this->assertDatabaseMissing('project_members', ['project_id' => $project->id]);
    $this->assertDatabaseMissing('issues', ['id' => $parentIssue->id]);
    $this->assertDatabaseMissing('issues', ['id' => $childIssue->id]);
    $this->assertDatabaseMissing('issue_label', ['issue_id' => $childIssue->id]);
    $this->assertDatabaseMissing('comments', ['issue_id' => $childIssue->id]);

    $this->assertDatabaseHas('users', ['id' => $reporter->id]);
    $this->assertDatabaseHas('users', ['id' => $owner->id]);
});
