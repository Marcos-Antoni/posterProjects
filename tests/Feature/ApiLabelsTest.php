<?php

use App\Enums\TokenName;
use App\Models\Issue;
use App\Models\Label;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The expected wire shape of one label row, mirroring `LabelResource::toArray()`
 * field-for-field (including order — `toBe()` is a strict `===` comparison).
 *
 * @return array<string, mixed>
 */
function labelPayload(Label $label, int $issuesCount = 0): array
{
    return [
        'id' => $label->id,
        'name' => $label->name,
        'issues_count' => $issuesCount,
        'updated_at' => $label->updated_at?->toIso8601String(),
    ];
}

/**
 * Shared non-disclosure assertion: unknown key, non-member key, and
 * archived project must all produce the byte-identical generic 404 body —
 * never leaking `App\Models\Project`.
 */
function assertLabelsNotFound(TestResponse $response): void
{
    $response->assertStatus(404);
    $response->assertExactJson(['message' => 'Recurso no encontrado.']);
    expect($response->getContent())->not->toContain('App\\Models\\Project');
}

test('the shape exposes exactly the 4 pinned fields', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'LBLSHAPE']);
    $label = Label::factory()->for($project)->create(['name' => 'backend']);

    $response = $this->getJson("/api/v1/projects/{$project->key}/labels", apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data'))->toBe([
        labelPayload($label),
    ]);
});

test('labels are ordered by name ascending, identically across two calls', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'LBLORDER']);

    // Explicit lowercase names, inserted non-alphabetically (D-4): SQLite's
    // BINARY collation would let a case-mixed or alphabetically-inserted
    // fixture pass by accident instead of proving ORDER BY. Never
    // `LabelFactory`'s default `fake()->unique()->word()`.
    $frontend = Label::factory()->for($project)->create(['name' => 'frontend']);
    $urgent = Label::factory()->for($project)->create(['name' => 'urgent']);
    $backend = Label::factory()->for($project)->create(['name' => 'backend']);
    $bug = Label::factory()->for($project)->create(['name' => 'bug']);

    $first = $this->getJson("/api/v1/projects/{$project->key}/labels", apiBearerHeaders($token));
    $second = $this->getJson("/api/v1/projects/{$project->key}/labels", apiBearerHeaders($token));

    $expected = [
        labelPayload($backend),
        labelPayload($bug),
        labelPayload($frontend),
        labelPayload($urgent),
    ];
    $first->assertOk();
    $second->assertOk();
    expect($first->json('data'))->toBe($expected)
        ->and($second->json('data'))->toBe($expected);
});

test('a zero-issue label is present and its issues_count is strictly 0', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'LBLZERO']);
    Label::factory()->for($project)->create(['name' => 'backend']);

    $response = $this->getJson("/api/v1/projects/{$project->key}/labels", apiBearerHeaders($token));

    $response->assertOk();
    // Strict `toBe(0)` — never `toBeEmpty()`/`toBeFalsy()`/`==`, all three
    // of which a dropped `withCount` (leaving `issues_count` as `null`)
    // would satisfy just as well (D-3).
    expect($response->json('data.0.issues_count'))->toBe(0);
});

test('a project with no labels returns an empty data array, never a 404', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'LBLEMPTY']);

    $response = $this->getJson("/api/v1/projects/{$project->key}/labels", apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

test('the labels query count does not grow with label count, and every issues_count value is correct', function () {
    Model::preventLazyLoading();

    try {
        $user = User::factory()->create();
        $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
        $project = createMemberProject($user, ['key' => 'LBLNPLUS1']);
        $todo = $project->boardColumns()->orderBy('position')->first();

        $solo = Label::factory()->for($project)->create(['name' => 'backend']);
        Issue::factory()->count(2)->for($project)->for($todo, 'boardColumn')->create()
            ->each(fn (Issue $issue) => $issue->labels()->attach($solo));

        $selectCount = 0;
        DB::listen(function ($query) use (&$selectCount): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $selectCount++;
            }
        });

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $soloResponse = $this->getJson("/api/v1/projects/{$project->key}/labels", apiBearerHeaders($token));
        $soloQueries = $selectCount;

        $bug = Label::factory()->for($project)->create(['name' => 'bug']);
        Issue::factory()->count(3)->for($project)->for($todo, 'boardColumn')->create()
            ->each(fn (Issue $issue) => $issue->labels()->attach($bug));

        $docs = Label::factory()->for($project)->create(['name' => 'docs']);
        Issue::factory()->for($project)->for($todo, 'boardColumn')->create()
            ->labels()->attach($docs);

        $frontend = Label::factory()->for($project)->create(['name' => 'frontend']);
        Issue::factory()->count(4)->for($project)->for($todo, 'boardColumn')->create()
            ->each(fn (Issue $issue) => $issue->labels()->attach($frontend));

        // Zero-issue label — the row this endpoint exists for (D-3).
        $urgent = Label::factory()->for($project)->create(['name' => 'urgent']);

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $manyResponse = $this->getJson("/api/v1/projects/{$project->key}/labels", apiBearerHeaders($token));
        $manyQueries = $selectCount;

        $soloResponse->assertOk();
        $manyResponse->assertOk();
        expect($manyQueries)->toBe($soloQueries)
            ->and($manyResponse->json('data'))->toHaveCount(5);

        $expectedCounts = [
            $solo->id => 2,
            $bug->id => 3,
            $docs->id => 1,
            $frontend->id => 4,
            $urgent->id => 0,
        ];
        foreach ($manyResponse->json('data') as $labelData) {
            expect($labelData['issues_count'])->toBe($expectedCounts[$labelData['id']]);
        }
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('labels respond not found for an unknown, non-member, or archived project', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $unknown = $this->getJson('/api/v1/projects/NOPE/labels', apiBearerHeaders($token));
    assertLabelsNotFound($unknown);

    $stranger = User::factory()->create();
    $strangerProject = createMemberProject($stranger, ['key' => 'THEIRSLBL']);
    $nonMember = $this->getJson("/api/v1/projects/{$strangerProject->key}/labels", apiBearerHeaders($token));
    assertLabelsNotFound($nonMember);

    $archivedProject = createMemberProject($user, ['key' => 'ARCHIVEDLBL']);
    $archivedProject->delete();
    $archived = $this->getJson("/api/v1/projects/{$archivedProject->key}/labels", apiBearerHeaders($token));
    assertLabelsNotFound($archived);
});

test('labels require a bearer token with the mobile ability', function () {
    $user = User::factory()->create();
    $project = createMemberProject($user, ['key' => 'AUTHLBL']);

    $noToken = $this->getJson("/api/v1/projects/{$project->key}/labels");
    $noToken->assertStatus(401);
    expect($noToken->headers->get('WWW-Authenticate'))->toStartWith('Bearer');

    $mcpToken = $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;
    $forbidden = $this->getJson("/api/v1/projects/{$project->key}/labels", apiBearerHeaders($mcpToken));
    $forbidden->assertStatus(403);
    $forbidden->assertExactJson(['message' => 'Este token no tiene permiso para usar esta API.']);
});
