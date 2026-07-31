<?php

use App\Enums\TokenName;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Creates a project, auto-attaching `$owner` as a member and creating the
 * default board columns, mirroring `Project::createWithDefaultColumns()`'s
 * only entry-point contract.
 */
function createMemberProject(User $owner, array $attributes = []): Project
{
    return Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => strtoupper(fake()->unique()->lexify('???')),
        'name' => fake()->words(3, true),
        'description' => fake()->sentence(),
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function projectPayload(Project $project, int $issuesCount = 0, bool $archived = false): array
{
    return [
        'id' => $project->id,
        'key' => $project->key,
        'name' => $project->name,
        'description' => $project->description,
        'issues_count' => $issuesCount,
        'updated_at' => $project->updated_at->toIso8601String(),
        'archived' => $archived,
    ];
}

/**
 * Shared non-disclosure assertion: unknown key, non-member key, and
 * force-deleted key must all produce the byte-identical generic 404 body —
 * never leaking the model's FQCN or which of the three cases actually
 * occurred.
 */
function assertProjectNotFound(TestResponse $response): void
{
    $response->assertStatus(404);
    $response->assertExactJson(['message' => 'Recurso no encontrado.']);
    expect($response->getContent())->not->toContain('App\\Models\\Project');
}

test('the list is ordered by name and scoped to active membership', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $zebra = createMemberProject($user, ['key' => 'ZEBRA', 'name' => 'Zebra Project']);
    $apple = createMemberProject($user, ['key' => 'APPLE', 'name' => 'Apple Project']);
    $archived = createMemberProject($user, ['key' => 'ARCH', 'name' => 'Archived Project']);
    $archived->delete();

    $response = $this->getJson('/api/v1/projects', apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data'))->toBe([
        projectPayload($apple),
        projectPayload($zebra),
    ]);
});

test('two projects sharing a name come back ordered by id, stable across two calls', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $firstProject = createMemberProject($user, ['name' => 'Same Name']);
    $secondProject = createMemberProject($user, ['name' => 'Same Name']);

    $firstCall = $this->getJson('/api/v1/projects', apiBearerHeaders($token));
    $secondCall = $this->getJson('/api/v1/projects', apiBearerHeaders($token));

    $firstCall->assertOk();
    $secondCall->assertOk();

    $expectedIds = [$firstProject->id, $secondProject->id];
    expect(collect($firstCall->json('data'))->pluck('id')->all())->toBe($expectedIds)
        ->and(collect($secondCall->json('data'))->pluck('id')->all())->toBe($expectedIds);
});

test('a user with no projects gets an empty array, not an error', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $response = $this->getJson('/api/v1/projects', apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json())->toBe(['data' => []]);
});

test('listing projects without a token gets a bearer challenge', function () {
    $response = $this->getJson('/api/v1/projects');

    $response->assertStatus(401);
    expect($response->headers->get('WWW-Authenticate'))->toStartWith('Bearer');
});

test('an mcp-only token cannot list projects', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;

    $response = $this->getJson('/api/v1/projects', apiBearerHeaders($token));

    $response->assertStatus(403);
    $response->assertExactJson(['message' => 'Este token no tiene permiso para usar esta API.']);
});

test('the query count for listing projects does not grow with project count, and issues_count is correct', function () {
    Model::preventLazyLoading();

    try {
        $user = User::factory()->create();
        $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

        $solo = createMemberProject($user, ['key' => 'SOLO']);
        Issue::factory()->count(3)->for($solo)->for($solo->boardColumns->first(), 'boardColumn')->create();

        // Only SELECTs are counted: Sanctum's `last_used_at` touch on the
        // token is dirty-checked and only issues an UPDATE when the value
        // actually changes to-the-second, which is noise unrelated to
        // whether the projects query itself N+1s.
        $selectCount = 0;
        DB::listen(function ($query) use (&$selectCount): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $selectCount++;
            }
        });

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $soloResponse = $this->getJson('/api/v1/projects', apiBearerHeaders($token));
        $soloQueries = $selectCount;

        for ($i = 0; $i < 4; $i++) {
            createMemberProject($user);
        }

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $manyResponse = $this->getJson('/api/v1/projects', apiBearerHeaders($token));
        $manyQueries = $selectCount;

        $soloResponse->assertOk();
        $manyResponse->assertOk();
        expect($manyQueries)->toBe($soloQueries)
            ->and($soloResponse->json('data.0.issues_count'))->toBe(3);
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('an active project resolves archived false, and the same key resolves archived true once soft-deleted', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $project = createMemberProject($user, ['key' => 'ACTIVE']);

    $active = $this->getJson('/api/v1/projects/ACTIVE', apiBearerHeaders($token));
    $active->assertOk();
    expect($active->json('data'))->toBe(projectPayload($project));

    $project->delete();

    $archived = $this->getJson('/api/v1/projects/ACTIVE', apiBearerHeaders($token));
    $archived->assertOk();
    expect($archived->json('data'))->toBe(projectPayload($project, archived: true));
});

test('an unknown key returns the generic not-found body', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $response = $this->getJson('/api/v1/projects/NOPE', apiBearerHeaders($token));

    assertProjectNotFound($response);
});

test('a key belonging to a project the user is not a member of returns the generic not-found body, never 403', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $stranger = User::factory()->create();
    createMemberProject($stranger, ['key' => 'THEIRS']);

    $response = $this->getJson('/api/v1/projects/THEIRS', apiBearerHeaders($token));

    assertProjectNotFound($response);
});

test('a force-deleted project key returns the generic not-found body', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $project = createMemberProject($user, ['key' => 'GONE']);
    $project->forceDelete();

    $response = $this->getJson('/api/v1/projects/GONE', apiBearerHeaders($token));

    assertProjectNotFound($response);
});
