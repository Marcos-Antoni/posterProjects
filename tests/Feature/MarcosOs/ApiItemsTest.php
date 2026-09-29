<?php

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/*
| Task 2.15 — item show and check through `/api/v1` (api-issues spec).
*/

function mosApiObjective(string $key = 'SALUD'): Objective
{
    return Objective::factory()->for(User::factory())->withControlPlan()->create(['key' => $key]);
}

test('the item detail shape is pinned and embeds prerequisites and unlocks', function () {
    $objective = mosApiObjective();
    $plan = Plan::factory()->for($objective)->create(['title' => 'Semana 1']);
    $pre = Item::factory()->for($plan)->done()->create(['title' => 'Mesa lista']);
    $item = Item::factory()->for($plan)->create(['title' => 'Captura 10 min', 'target_date' => '2026-10-01']);
    $next1 = Item::factory()->for($plan)->create(['title' => 'Clasificar']);
    $next2 = Item::factory()->for($plan)->create(['title' => 'Revisar']);
    ItemDependency::query()->create(['prerequisite_id' => $pre->id, 'dependent_id' => $item->id]);
    ItemDependency::query()->create(['prerequisite_id' => $item->id, 'dependent_id' => $next1->id]);
    ItemDependency::query()->create(['prerequisite_id' => $item->id, 'dependent_id' => $next2->id]);

    $response = $this->getJson('/api/v1/objectives/SALUD/items/SALUD-2', mosMobileHeaders($objective->user))->assertOk();

    expect(array_keys($response->json('data')))->toBe([
        'id', 'key', 'kind', 'title', 'description', 'two_minute_version', 'state', 'target_date', 'plan',
        'prerequisites', 'unlocks', 'completed_at', 'evidence', 'updated_at',
    ])
        ->and($response->json('data.key'))->toBe('SALUD-2')
        ->and($response->json('data.state'))->toBe('available')
        ->and($response->json('data.target_date'))->toBe('2026-10-01')
        ->and($response->json('data.plan'))->toBe(['id' => $plan->id, 'title' => 'Semana 1'])
        ->and($response->json('data.prerequisites'))->toBe([['key' => 'SALUD-1', 'title' => 'Mesa lista', 'state' => 'done']])
        ->and($response->json('data.unlocks'))->toHaveCount(2)
        ->and($response->json('data.unlocks.0'))->toBe(['key' => 'SALUD-3', 'title' => 'Clasificar', 'state' => 'locked'])
        ->and($response->json('data.evidence'))->toBeNull();
});

test('every malformed or foreign item key is the same 404', function (string $segment) {
    $objective = mosApiObjective();
    $plan = Plan::factory()->for($objective)->create();
    Item::factory()->for($plan)->create();
    Item::factory()->for($plan)->retired()->create();
    $dinero = Objective::factory()->for($objective->user)->withControlPlan()->create(['key' => 'DINERO']);
    Item::factory()->for(Plan::factory()->for($dinero))->create();

    $this->getJson("/api/v1/objectives/SALUD/items/{$segment}", mosMobileHeaders($objective->user))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Recurso no encontrado.']);
})->with(['nodash', 'SALUD-', 'SALUD-x1', 'SALUD-99', 'DINERO-1', 'SALUD-2', 'salud-1']);

test('checking a task reports what it unlocked', function () {
    $objective = mosApiObjective();
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create(['title' => 'B']);
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    $response = $this->postJson('/api/v1/objectives/SALUD/items/SALUD-1/check', [], mosMobileHeaders($objective->user))->assertOk();

    expect($response->json('data.state'))->toBe('done')
        ->and($response->json('data.completed_at'))->not->toBeNull()
        ->and($response->json('data.unlocked'))->toBe([['key' => 'SALUD-2', 'title' => 'B', 'state' => 'available']]);
});

test('checking a locked item is 422 and changes nothing', function () {
    $objective = mosApiObjective();
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    $this->postJson('/api/v1/objectives/SALUD/items/SALUD-2/check', [], mosMobileHeaders($objective->user))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('item');

    expect($b->fresh()->completed_at)->toBeNull();
});

test('a milestone needs non-empty evidence through the api', function () {
    $objective = mosApiObjective();
    Item::factory()->for(Plan::factory()->for($objective))->milestone()->create();
    $headers = mosMobileHeaders($objective->user);

    $this->postJson('/api/v1/objectives/SALUD/items/SALUD-1/check', ['evidence' => ''], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('evidence');

    $this->postJson('/api/v1/objectives/SALUD/items/SALUD-1/check', ['evidence' => 'Foto del boceto'], $headers)
        ->assertOk()
        ->assertJsonPath('data.state', 'done')
        ->assertJsonPath('data.evidence.text', 'Foto del boceto');
});

test('checking an already done item is 200 and unchanged', function () {
    $objective = mosApiObjective();
    $item = Item::factory()->for(Plan::factory()->for($objective))->done()->create();
    $completedAt = $item->completed_at->toIso8601String();

    $this->postJson('/api/v1/objectives/SALUD/items/SALUD-1/check', [], mosMobileHeaders($objective->user))
        ->assertOk()
        ->assertJsonPath('data.completed_at', $completedAt)
        ->assertJsonPath('data.unlocked', []);
});

test('items of a closed objective resolve read-only', function () {
    $objective = Objective::factory()->closed()->withControlPlan()->create(['key' => 'SALUD']);
    Item::factory()->for(Plan::factory()->for($objective))->create();
    $headers = mosMobileHeaders($objective->user);

    $this->getJson('/api/v1/objectives/SALUD/items/SALUD-1', $headers)->assertOk();
    $this->postJson('/api/v1/objectives/SALUD/items/SALUD-1/check', [], $headers)->assertUnprocessable();
});

test('item detail serialization does not grow in queries with its neighbours', function () {
    $small = mosApiObjective('UNO');
    $smallPlan = Plan::factory()->for($small)->create();
    $smallPre = Item::factory()->for($smallPlan)->create();
    $smallItem = Item::factory()->for($smallPlan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $smallPre->id, 'dependent_id' => $smallItem->id]);

    $big = mosApiObjective('CINCO');
    $bigPlan = Plan::factory()->for($big)->create();
    $bigItems = Item::factory()->for($bigPlan)->count(11)->create();
    $center = $bigItems->get(5);
    foreach ($bigItems->take(5) as $pre) {
        ItemDependency::query()->create(['prerequisite_id' => $pre->id, 'dependent_id' => $center->id]);
    }
    foreach ($bigItems->slice(6) as $next) {
        ItemDependency::query()->create(['prerequisite_id' => $center->id, 'dependent_id' => $next->id]);
    }

    $smallHeaders = mosMobileHeaders($small->user);
    $bigHeaders = mosMobileHeaders($big->user);
    $bigUrl = "/api/v1/objectives/CINCO/items/{$center->key}";
    $smallUrl = "/api/v1/objectives/UNO/items/{$smallItem->key}";

    expect(mosQueryCount(fn () => $this->getJson($bigUrl, $bigHeaders)->assertOk()->assertJsonCount(5, 'data.unlocks')))
        ->toBe(mosQueryCount(fn () => $this->getJson($smallUrl, $smallHeaders)->assertOk()));
});
