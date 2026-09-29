<?php

use App\Actions\Items\StartItem;
use App\Actions\Support\Actor;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Views\NowView;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Task 3.8 — MCP `now-view` (mcp-server "Read Views Match The Marcos OS
| Screens"): the same single active or suggested task as the Now screen, with
| its 2-minute version, what it unlocks and absolute URLs.
*/

function p3McpNow(User $owner): array
{
    $token = $owner->createToken('mcp', ['mcp'])->plainTextToken;

    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'now-view', 'arguments' => (object) []],
    ], ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream'])->assertOk();

    return json_decode($response->json('result.content.0.text'), true);
}

test('now-view states its read tier and is read-only', function () {
    expect((string) app(NowView::class)->description())->toContain('Nivel IA: read')
        ->and(app(NowView::class)->name())->toBe('now-view');
});

test('now-view returns the active Now task with its 2-minute version, unlocks and absolute urls, matching the web', function () {
    Carbon::setTestNow('2026-09-27 15:12:00');
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario']);
    $plan = Plan::factory()->for($objective)->create();
    $task = Item::factory()->for($plan)->create(['title' => 'Captura 10 min', 'two_minute_version' => 'abrir el inbox']);
    $next = Item::factory()->for($plan)->create(['title' => 'Clasificar']);
    ItemDependency::query()->create(['prerequisite_id' => $task->id, 'dependent_id' => $next->id]);
    app(StartItem::class)(Actor::ownerWeb($owner), $task);

    $result = p3McpNow($owner);

    expect($result['now']['key'])->toBe('DIARIO-1')
        ->and($result['now']['two_minute_version'])->toBe('abrir el inbox')
        ->and($result['now']['is_active'])->toBeTrue()
        ->and($result['now']['focus_started_at'])->toBe('2026-09-27T15:12:00+00:00')
        ->and($result['now']['unlocks'])->toBe([['key' => 'DIARIO-2', 'title' => 'Clasificar', 'state' => 'locked']])
        ->and($result['now']['url'])->toBe(route('objectives.items.show', ['DIARIO', 'DIARIO-1']))
        ->and($result['now']['url'])->toStartWith('http')
        ->and($result['now_url'])->toBe(route('now'))
        ->and($result['restart'])->toBeFalse();

    $this->actingAs($owner)->get(route('now'))
        ->assertInertia(fn (Assert $page) => $page->where('now', $result['now']));
});

test('now-view returns the one suggestion when nothing is active, and null when nothing is available', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $only = Item::factory()->for($plan)->create();

    expect(p3McpNow($owner)['now']['key'])->toBe('DIARIO-1')
        ->and(p3McpNow($owner)['now']['is_active'])->toBeFalse();

    $only->update(['completed_at' => now()]);

    expect(p3McpNow($owner)['now'])->toBeNull();
});

test('now-view is scoped to the authenticated owner', function () {
    $owner = User::factory()->create();
    $foreign = Objective::factory()->withControlPlan()->create(['key' => 'AJENO']);
    Item::factory()->for(Plan::factory()->for($foreign)->create())->create();

    PosterServer::actingAs($owner)->tool(NowView::class)
        ->assertOk()
        ->assertDontSee('AJENO');
});
