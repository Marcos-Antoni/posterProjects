<?php

use App\Enums\TokenName;
use App\Http\Controllers\BoardController;
use App\Models\Issue;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The expected wire shape of one sprint row, mirroring
 * `SprintResource::toArray()` field-for-field (including order — `toBe()`
 * is a strict `===` comparison). `start_date`/`end_date` are dates
 * (`YYYY-MM-DD`), never ISO8601 timestamps.
 *
 * @return array<string, mixed>
 */
function sprintPayload(Sprint $sprint, string $state, int $issuesCount = 0): array
{
    return [
        'id' => $sprint->id,
        'name' => $sprint->name,
        'goal' => $sprint->goal,
        'start_date' => $sprint->start_date->toDateString(),
        'end_date' => $sprint->end_date->toDateString(),
        'state' => $state,
        'issues_count' => $issuesCount,
        'updated_at' => $sprint->updated_at?->toIso8601String(),
    ];
}

/**
 * Shared non-disclosure assertion: unknown key, non-member key, and
 * archived project must all produce the byte-identical generic 404 body —
 * never leaking `App\Models\Project`.
 */
function assertSprintsNotFound(TestResponse $response): void
{
    $response->assertStatus(404);
    $response->assertExactJson(['message' => 'Recurso no encontrado.']);
    expect($response->getContent())->not->toContain('App\\Models\\Project');
}

afterEach(fn () => Carbon::setTestNow());

test('the shape exposes exactly the 8 pinned fields, dates as YYYY-MM-DD not ISO8601', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'SPRSHAPE']);
    $sprint = Sprint::factory()->for($project)->create([
        'name' => 'Sprint 1',
        'goal' => 'Ship the thing',
        'start_date' => today()->addDays(1),
        'end_date' => today()->addDays(10),
    ]);

    $response = $this->getJson("/api/v1/projects/{$project->key}/sprints", apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data'))->toBe([sprintPayload($sprint, 'future')])
        ->and($response->json('data.0.start_date'))->not->toContain('T');
});

test('sprint state reflects both inclusive boundaries and a single-day sprint', function () {
    Carbon::setTestNow(today()->addHours(12));

    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'STATEMTX']);
    $today = today();

    Sprint::factory()->for($project)->create([
        'name' => 'Starts Today',
        'start_date' => $today,
        'end_date' => $today->copy()->addDays(7),
    ]);
    Sprint::factory()->for($project)->create([
        'name' => 'Ends Today',
        'start_date' => $today->copy()->subDays(7),
        'end_date' => $today,
    ]);
    Sprint::factory()->for($project)->create([
        'name' => 'Single Day',
        'start_date' => $today,
        'end_date' => $today,
    ]);
    Sprint::factory()->for($project)->create([
        'name' => 'Starts Tomorrow',
        'start_date' => $today->copy()->addDay(),
        'end_date' => $today->copy()->addDays(8),
    ]);
    Sprint::factory()->for($project)->create([
        'name' => 'Ended Yesterday',
        'start_date' => $today->copy()->subDays(8),
        'end_date' => $today->copy()->subDay(),
    ]);

    $response = $this->getJson("/api/v1/projects/{$project->key}/sprints", apiBearerHeaders($token));

    $response->assertOk();
    $statesByName = collect($response->json('data'))->pluck('state', 'name');

    expect($statesByName->get('Starts Today'))->toBe('active')
        ->and($statesByName->get('Ends Today'))->toBe('active')
        ->and($statesByName->get('Single Day'))->toBe('active')
        ->and($statesByName->get('Starts Tomorrow'))->toBe('future')
        ->and($statesByName->get('Ended Yesterday'))->toBe('completed');
});

test('two sprints sharing a start_date come back ordered by id descending', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'TIESPR']);
    $sharedStart = today()->addDays(3);

    $first = Sprint::factory()->for($project)->create([
        'start_date' => $sharedStart,
        'end_date' => $sharedStart->copy()->addDays(7),
    ]);
    $second = Sprint::factory()->for($project)->create([
        'start_date' => $sharedStart,
        'end_date' => $sharedStart->copy()->addDays(7),
    ]);

    $response = $this->getJson("/api/v1/projects/{$project->key}/sprints", apiBearerHeaders($token));

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('the first active sprint element matches BoardController::resolveActiveSprint\'s pick, including two overlapping active sprints', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'ACTIVEPICK']);
    $today = today();

    Sprint::factory()->for($project)->create([
        'name' => 'Overlap A',
        'start_date' => $today->copy()->subDays(3),
        'end_date' => $today->copy()->addDays(3),
    ]);
    Sprint::factory()->for($project)->create([
        'name' => 'Overlap B',
        'start_date' => $today->copy()->subDays(1),
        'end_date' => $today->copy()->addDays(5),
    ]);
    Sprint::factory()->for($project)->create([
        'name' => 'Future One',
        'start_date' => $today->copy()->addDays(10),
        'end_date' => $today->copy()->addDays(17),
    ]);

    $response = $this->getJson("/api/v1/projects/{$project->key}/sprints", apiBearerHeaders($token));
    $response->assertOk();

    $firstActive = collect($response->json('data'))->firstWhere('state', 'active');
    expect($firstActive)->not->toBeNull();

    $boardProps = app(BoardController::class)->boardProps(Request::create('/'), $project->fresh());

    expect($firstActive['id'])->toBe($boardProps['activeSprintId']);
});

test('issues_count reflects the eager aggregate, including zero for an empty sprint', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'COUNTSPR']);
    $todo = $project->boardColumns()->orderBy('position')->first();

    $populated = Sprint::factory()->for($project)->create([
        'start_date' => today()->addDays(1),
        'end_date' => today()->addDays(10),
    ]);
    $empty = Sprint::factory()->for($project)->create([
        'start_date' => today()->addDays(20),
        'end_date' => today()->addDays(30),
    ]);
    Issue::factory()->count(3)->for($project)->for($todo, 'boardColumn')->create(['sprint_id' => $populated->id]);

    $response = $this->getJson("/api/v1/projects/{$project->key}/sprints", apiBearerHeaders($token));

    $response->assertOk();
    $countsById = collect($response->json('data'))->pluck('issues_count', 'id');

    expect($countsById->get($populated->id))->toBe(3)
        ->and($countsById->get($empty->id))->toBe(0);
});

test('sprints respond not found for an unknown, non-member, or archived project', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $unknown = $this->getJson('/api/v1/projects/NOPE/sprints', apiBearerHeaders($token));
    assertSprintsNotFound($unknown);

    $stranger = User::factory()->create();
    $strangerProject = createMemberProject($stranger, ['key' => 'THEIRSSPR']);
    $nonMember = $this->getJson("/api/v1/projects/{$strangerProject->key}/sprints", apiBearerHeaders($token));
    assertSprintsNotFound($nonMember);

    $archivedProject = createMemberProject($user, ['key' => 'ARCHIVEDSPR']);
    $archivedProject->delete();
    $archived = $this->getJson("/api/v1/projects/{$archivedProject->key}/sprints", apiBearerHeaders($token));
    assertSprintsNotFound($archived);
});

test('sprints require a bearer token with the mobile ability', function () {
    $user = User::factory()->create();
    $project = createMemberProject($user, ['key' => 'AUTHSPR']);

    $noToken = $this->getJson("/api/v1/projects/{$project->key}/sprints");
    $noToken->assertStatus(401);
    expect($noToken->headers->get('WWW-Authenticate'))->toStartWith('Bearer');

    $mcpToken = $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;
    $forbidden = $this->getJson("/api/v1/projects/{$project->key}/sprints", apiBearerHeaders($mcpToken));
    $forbidden->assertStatus(403);
    $forbidden->assertExactJson(['message' => 'Este token no tiene permiso para usar esta API.']);
});

test('the sprints query count does not grow with sprint count, and issues_count values are correct', function () {
    Model::preventLazyLoading();

    try {
        $user = User::factory()->create();
        $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
        $project = createMemberProject($user, ['key' => 'NPLUS1SPR']);
        $todo = $project->boardColumns()->orderBy('position')->first();

        $solo = Sprint::factory()->for($project)->create([
            'start_date' => today()->addDays(1),
            'end_date' => today()->addDays(10),
        ]);
        Issue::factory()->count(2)->for($project)->for($todo, 'boardColumn')->create(['sprint_id' => $solo->id]);

        $selectCount = 0;
        DB::listen(function ($query) use (&$selectCount): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $selectCount++;
            }
        });

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $soloResponse = $this->getJson("/api/v1/projects/{$project->key}/sprints", apiBearerHeaders($token));
        $soloQueries = $selectCount;

        for ($i = 0; $i < 4; $i++) {
            $sprint = Sprint::factory()->for($project)->create([
                'start_date' => today()->addDays(20 + $i),
                'end_date' => today()->addDays(27 + $i),
            ]);
            Issue::factory()->count(2)->for($project)->for($todo, 'boardColumn')->create(['sprint_id' => $sprint->id]);
        }

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $manyResponse = $this->getJson("/api/v1/projects/{$project->key}/sprints", apiBearerHeaders($token));
        $manyQueries = $selectCount;

        $soloResponse->assertOk();
        $manyResponse->assertOk();
        expect($manyQueries)->toBe($soloQueries)
            ->and($manyResponse->json('data'))->toHaveCount(5);

        foreach ($manyResponse->json('data') as $sprintData) {
            expect($sprintData['issues_count'])->toBe(2);
        }
    } finally {
        Model::preventLazyLoading(false);
    }
});
