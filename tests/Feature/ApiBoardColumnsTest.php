<?php

use App\Enums\TokenName;
use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The expected wire shape of one board-column row, mirroring
 * `BoardColumnResource::toArray()` field-for-field (including order —
 * `toBe()` is a strict `===` comparison).
 *
 * @return array<string, mixed>
 */
function boardColumnPayload(BoardColumn $column): array
{
    return [
        'id' => $column->id,
        'name' => $column->name,
        'position' => $column->position,
        'updated_at' => $column->updated_at?->toIso8601String(),
    ];
}

/**
 * Shared non-disclosure assertion: unknown key, non-member key, and
 * archived project must all produce the byte-identical generic 404 body —
 * never leaking `App\Models\Project`.
 */
function assertBoardColumnsNotFound(TestResponse $response): void
{
    $response->assertStatus(404);
    $response->assertExactJson(['message' => 'Recurso no encontrado.']);
    expect($response->getContent())->not->toContain('App\\Models\\Project');
}

test('the shape exposes exactly the 4 pinned fields, ordered by position', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'COLSHAPE']);
    [$todo, $inProgress, $done] = $project->boardColumns()->orderBy('position')->get()->all();

    $response = $this->getJson("/api/v1/projects/{$project->key}/board-columns", apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data'))->toBe([
        boardColumnPayload($todo),
        boardColumnPayload($inProgress),
        boardColumnPayload($done),
    ]);
});

test('an empty board column is not hidden from the response', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'COLEMPTY']);
    // Explicit position (never the factory default — D-7): default columns
    // already occupy 0/1/2.
    $extraColumn = BoardColumn::factory()->for($project)->create(['name' => 'Icebox', 'position' => 3]);
    Issue::factory()->for($project)->for($extraColumn, 'boardColumn')->create();

    $response = $this->getJson("/api/v1/projects/{$project->key}/board-columns", apiBearerHeaders($token));

    $response->assertOk();
    $expected = $project->boardColumns()->orderBy('position')->get()
        ->map(fn (BoardColumn $column): array => boardColumnPayload($column))
        ->all();
    expect($response->json('data'))->toBe($expected)
        ->and($response->json('data'))->toHaveCount(4);
});

test('board columns compose with the issues endpoint to reconstruct the full board, including an empty column', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'COMPOSE']);
    [$todo, $inProgress, $done] = $project->boardColumns()->orderBy('position')->get()->all();
    $emptyColumn = BoardColumn::factory()->for($project)->create(['name' => 'Icebox', 'position' => 3]);

    $todoSecond = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['position' => 1]);
    $todoFirst = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['position' => 0]);
    $inProgressIssue = Issue::factory()->for($project)->for($inProgress, 'boardColumn')->create(['position' => 0]);

    $columnsResponse = $this->getJson("/api/v1/projects/{$project->key}/board-columns", apiBearerHeaders($token));
    $issuesResponse = $this->getJson("/api/v1/projects/{$project->key}/issues", apiBearerHeaders($token));

    $columnsResponse->assertOk();
    $issuesResponse->assertOk();

    $columnIds = collect($columnsResponse->json('data'))->pluck('id')->all();
    $issuesByColumn = collect($issuesResponse->json('data'))->groupBy('board_column_id');

    $board = collect($columnIds)->mapWithKeys(
        fn (int $columnId): array => [$columnId => ($issuesByColumn->get($columnId) ?? collect())->pluck('id')->all()],
    );

    expect($columnIds)->toBe([$todo->id, $inProgress->id, $done->id, $emptyColumn->id])
        ->and($board[$todo->id])->toBe([$todoFirst->id, $todoSecond->id])
        ->and($board[$inProgress->id])->toBe([$inProgressIssue->id])
        ->and($board[$done->id])->toBe([])
        ->and($board[$emptyColumn->id])->toBe([]);
});

test('board columns respond not found for an unknown, non-member, or archived project', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $unknown = $this->getJson('/api/v1/projects/NOPE/board-columns', apiBearerHeaders($token));
    assertBoardColumnsNotFound($unknown);

    $stranger = User::factory()->create();
    $strangerProject = createMemberProject($stranger, ['key' => 'THEIRSCOL']);
    $nonMember = $this->getJson("/api/v1/projects/{$strangerProject->key}/board-columns", apiBearerHeaders($token));
    assertBoardColumnsNotFound($nonMember);

    $archivedProject = createMemberProject($user, ['key' => 'ARCHIVEDCOL']);
    $archivedProject->delete();
    $archived = $this->getJson("/api/v1/projects/{$archivedProject->key}/board-columns", apiBearerHeaders($token));
    assertBoardColumnsNotFound($archived);
});

test('board columns require a bearer token with the mobile ability', function () {
    $user = User::factory()->create();
    $project = createMemberProject($user, ['key' => 'AUTHCOL']);

    $noToken = $this->getJson("/api/v1/projects/{$project->key}/board-columns");
    $noToken->assertStatus(401);
    expect($noToken->headers->get('WWW-Authenticate'))->toStartWith('Bearer');

    $mcpToken = $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;
    $forbidden = $this->getJson("/api/v1/projects/{$project->key}/board-columns", apiBearerHeaders($mcpToken));
    $forbidden->assertStatus(403);
    $forbidden->assertExactJson(['message' => 'Este token no tiene permiso para usar esta API.']);
});

test('the board columns query count does not grow with column count', function () {
    Model::preventLazyLoading();

    try {
        $user = User::factory()->create();
        $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
        $project = createMemberProject($user, ['key' => 'NPLUS1COL']);

        $selectCount = 0;
        DB::listen(function ($query) use (&$selectCount): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $selectCount++;
            }
        });

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $soloResponse = $this->getJson("/api/v1/projects/{$project->key}/board-columns", apiBearerHeaders($token));
        $soloQueries = $selectCount;

        // Explicit positions (never the factory default — D-7): default
        // columns already occupy 0/1/2.
        BoardColumn::factory()->for($project)->create(['name' => 'Icebox', 'position' => 3]);
        BoardColumn::factory()->for($project)->create(['name' => 'Blocked', 'position' => 4]);

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $manyResponse = $this->getJson("/api/v1/projects/{$project->key}/board-columns", apiBearerHeaders($token));
        $manyQueries = $selectCount;

        $soloResponse->assertOk();
        $manyResponse->assertOk();
        expect($manyQueries)->toBe($soloQueries)
            ->and($soloResponse->json('data'))->toHaveCount(3)
            ->and($manyResponse->json('data'))->toHaveCount(5);
    } finally {
        Model::preventLazyLoading(false);
    }
});
