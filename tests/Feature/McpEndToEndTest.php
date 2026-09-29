<?php

use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * End-to-end coverage of the MCP HTTP endpoint: real Sanctum bearer
 * tokens (no `actingAs`) driving the full JSON-RPC cycle — handshake,
 * discovery, and a chain of tool calls that read and mutate real
 * objectives, items and habits, each one checked against the database. `mcpInitializePayload()`
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
        // full catalog comes back in one page.
        'params' => ['per_page' => 50],
    ], mcpHeaders($token));

    $response->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name');

    expect($names)->toHaveCount(24) // 13 + phase 3 now-view + phase 4 log-two-minute + phase 5 graph tools + phase 6 (retire-habit, restore-habit, retired-view replace archive/unarchive) + phase 7 (capture, list-inbox) + phase 8 (propose, start-item, replace-two-minute, triage-capture)
        ->and($names)->toContain(
            'list-objectives',
            'show-objective',
            'show-item',
            'check-item',
            'uncheck-item',
            'now-view',
            'objective-graph',
            'global-graph',
            'create-habit',
            'log-habit-entry',
            'show-habit',
            'log-two-minute',
            'retire-habit',
            'restore-habit',
            'retired-view',
            'capture',
            'list-inbox',
            'propose',
            'start-item',
            'replace-two-minute',
            'triage-capture',
        );
});

test('a full objective-check to regenerated-token cycle works over real http with sanctum bearer auth', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'E2E']);
    $plan = Plan::factory()->for($objective)->create();
    $first = Item::factory()->for($plan)->create(['title' => 'First item']);
    $second = Item::factory()->for($plan)->create(['title' => 'Second item']);
    ItemDependency::query()->create(['prerequisite_id' => $first->id, 'dependent_id' => $second->id]);
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

    // 3. show-objective: the tree, with the second item locked by the first.
    $shown = $call('show-objective', ['objective_key' => 'E2E']);

    expect($shown['objective']['url'])->toBe(route('objectives.show', 'E2E'))
        ->and($shown['objective']['plans'][0]['items'][0]['key'])->toBe('E2E-1')
        ->and($shown['objective']['plans'][0]['items'][1]['state'])->toBe('locked');

    // 4. check-item reports what it unlocked.
    $checked = $call('check-item', ['objective_key' => 'E2E', 'item_key' => 'E2E-1']);

    expect($checked['item']['state'])->toBe('done')
        ->and($checked['item']['url'])->toBe(route('objectives.items.show', ['E2E', 'E2E-1']))
        ->and($checked['unlocked'])->toBe([['key' => 'E2E-2', 'title' => 'Second item', 'state' => 'available']]);

    expect($first->fresh()->completed_at)->not->toBeNull();

    // 5. show-item: the unlocked item is now available.
    $item = $call('show-item', ['objective_key' => 'E2E', 'item_key' => 'E2E-2']);

    expect($item['item']['state'])->toBe('available')
        ->and($item['item']['prerequisites'][0]['state'])->toBe('done');

    // 6. create-habit is a major AI operation (ai-operations spec): refused
    //    over MCP, nothing written. The habit is created by the owner instead.
    $refused = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => ++$callId,
        'method' => 'tools/call',
        'params' => ['name' => 'create-habit', 'arguments' => (object) [
            'name' => 'Read', 'habit_type' => 'quantitative', 'unit' => 'pages', 'daily_target' => 20,
            'recurrence_type' => 'daily', 'two_minute_version' => 'Open the book',
        ]],
    ], mcpHeaders($token));

    expect($refused->json('result.isError'))->toBeTrue()
        ->and(Habit::query()->count())->toBe(0);

    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->daily()->create(['name' => 'Read']);

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
