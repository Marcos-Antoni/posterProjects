<?php

use App\Models\BoardColumn;
use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * End-to-end coverage of the MCP HTTP endpoint: real Sanctum bearer
 * tokens (no `actingAs`) driving the full JSON-RPC cycle — handshake,
 * discovery, and a chain of tool calls that mutate real projects, issues
 * and habits, each one checked against the database. `mcpInitializePayload()`
 * and `mcpHeaders()` are shared globals declared in `McpServerTest.php`.
 */
test('the http endpoint exposes every registered tool by its kebab-case name', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mcp')->plainTextToken;

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        // The default page is 15 tools (max 50) — request the max so the
        // full ~38-tool catalog comes back in one page.
        'params' => ['per_page' => 50],
    ], mcpHeaders($token));

    $response->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name');

    expect($names)->toHaveCount(38)
        ->and($names)->toContain(
            'create-project',
            'create-issue',
            'move-issue',
            'create-habit',
            'log-habit-entry',
            'show-habit',
        );
});

test('a full create-project to regenerated-token cycle works over real http with sanctum bearer auth', function () {
    $user = User::factory()->create();
    $user->tokens()->delete();
    $token = $user->createToken('mcp')->plainTextToken;

    $callId = 1;

    $call = function (string $name, array $arguments) use (&$callId, $token): array {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => ++$callId,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => (object) $arguments,
            ],
        ], mcpHeaders($token));

        $response->assertOk();

        $result = $response->json('result');

        expect($result['isError'] ?? false)->toBeFalse();

        return json_decode((string) $result['content'][0]['text'], true);
    };

    // 1. initialize.
    $initialize = $this->postJson('/mcp', mcpInitializePayload(), mcpHeaders($token));
    $initialize->assertOk();
    $initialize->assertJsonPath('result.serverInfo.name', 'Poster Projects');

    // 2. tools/list sanity (full coverage asserted in the previous test).
    $list = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => (object) [],
    ], mcpHeaders($token));
    $list->assertOk();
    expect($list->json('result.tools'))->toBeArray();

    // 3. create-project.
    $created = $call('create-project', [
        'key' => 'E2E',
        'name' => 'End to end',
    ]);

    expect($created['project']['key'])->toBe('E2E')
        ->and($created['project']['url'])->toBe(route('projects.board', ['project' => 'E2E']));

    $project = Project::query()->where('key', 'E2E')->firstOrFail();
    expect($project->boardColumns()->pluck('name')->all())->toBe(['To Do', 'In Progress', 'Done']);

    $todoColumnId = BoardColumn::query()->where('project_id', $project->id)->where('name', 'To Do')->value('id');
    $inProgressColumnId = BoardColumn::query()->where('project_id', $project->id)->where('name', 'In Progress')->value('id');

    // 4. create-issue.
    $issueCreated = $call('create-issue', [
        'project_key' => 'E2E',
        'title' => 'First issue',
        'board_column_id' => $todoColumnId,
    ]);

    expect($issueCreated['issue']['key'])->toBe('E2E-1')
        ->and($issueCreated['issue']['url'])->toBe(route('projects.issues.show', [
            'project' => 'E2E',
            'issueKey' => 'E2E-1',
        ]));

    $issue = Issue::query()->where('id', $issueCreated['issue']['id'])->firstOrFail();
    expect($issue->board_column_id)->toBe($todoColumnId);

    // 5. move-issue to "In Progress" at position 0.
    $moved = $call('move-issue', [
        'project_key' => 'E2E',
        'issue_key' => 'E2E-1',
        'board_column_id' => $inProgressColumnId,
        'position' => 0,
    ]);

    expect($moved['issue']['board_column_id'])->toBe($inProgressColumnId)
        ->and($moved['issue']['position'])->toBe(0);

    $issue->refresh();
    expect($issue->board_column_id)->toBe($inProgressColumnId)
        ->and($issue->position)->toBe(0);

    // 6. create-habit (quantitative, daily).
    $habitCreated = $call('create-habit', [
        'name' => 'Read',
        'habit_type' => 'quantitative',
        'unit' => 'pages',
        'daily_target' => 20,
        'recurrence_type' => 'daily',
    ]);

    expect($habitCreated['habit']['name'])->toBe('Read')
        ->and($habitCreated['habit']['url'])->toBe(route('habits.show', ['habit' => $habitCreated['habit']['id']]));

    $habit = Habit::query()->findOrFail($habitCreated['habit']['id']);
    expect($habit->user_id)->toBe($user->id)
        ->and($habit->daily_target)->toBe(20);

    // 7. log-habit-entry: a partial entry, then one that pushes past the target.
    $firstEntry = $call('log-habit-entry', [
        'habit_id' => $habit->id,
        'amount' => 15,
    ]);

    expect($firstEntry['day']['accumulated_amount'])->toBe(15)
        ->and($firstEntry['day']['completion_percent'])->toBe(75)
        ->and($firstEntry['day']['completed'])->toBeFalse();

    $secondEntry = $call('log-habit-entry', [
        'habit_id' => $habit->id,
        'amount' => 10,
    ]);

    expect($secondEntry['day']['accumulated_amount'])->toBe(25)
        ->and($secondEntry['day']['completion_percent'])->toBe(125)
        ->and($secondEntry['day']['completed'])->toBeTrue();

    $day = HabitDay::query()->where('habit_id', $habit->id)->sole();
    expect($day->accumulated_amount)->toBe(25)
        ->and($day->completion_percent)->toBe(125)
        ->and($day->completed)->toBeTrue();

    // 8. show-habit: streak plus the raw (uncapped) percent for today.
    $shown = $call('show-habit', [
        'habit_id' => $habit->id,
    ]);

    expect($shown['metrics']['current_streak'])->toBe(1)
        ->and($shown['metrics']['best_streak'])->toBe(1);

    $todaySeriesPoint = collect($shown['series'])->firstWhere('date', $day->entry_date->toDateString());
    expect($todaySeriesPoint['completion_percent'])->toBe(125)
        ->and($todaySeriesPoint['completed'])->toBeTrue();

    // 9. regenerating the token revokes the one used throughout this test.
    $user->tokens()->delete();
    $user->createToken('mcp');

    expect(PersonalAccessToken::query()->count())->toBe(1);

    // The sanctum guard caches the user it resolved on its first call and
    // this test keeps reusing the same in-memory app across requests, so
    // it must be dropped here to force re-authentication from the fresh
    // (now-invalid) bearer token — production serves every request from a
    // clean process and never hits this.
    $this->app['auth']->forgetGuards();

    $afterRegeneration = $this->postJson('/mcp', mcpInitializePayload(), mcpHeaders($token));
    $afterRegeneration->assertStatus(401);
    expect($afterRegeneration->headers->get('WWW-Authenticate'))->toStartWith('Bearer');
});
