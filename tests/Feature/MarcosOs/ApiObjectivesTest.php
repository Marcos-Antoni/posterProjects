<?php

use App\Enums\TokenName;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/*
| Task 2.15 — API cutover (api-projects, api-issues, api-auth specs):
| `GET /api/v1/objectives`, `GET /api/v1/objectives/{objective}`, item show and
| check, pinned shapes, uniform 404s, the mobile ability boundary and query
| counts. The retired project/issue/board/sprint/label routes are gone.
*/

test('the list returns only the owner\'s active objectives, in manual order, with the pinned shape', function () {
    $owner = User::factory()->create();
    $second = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD', 'position' => 2]);
    $first = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DINERO', 'position' => 1]);
    Objective::factory()->for($owner)->closed()->withControlPlan()->create();
    Objective::factory()->for($owner)->retired()->withControlPlan()->create();
    Objective::factory()->for($owner)->draft()->create();
    Objective::factory()->withControlPlan()->create();

    $plan = Plan::factory()->for($first)->create();
    Item::factory()->for($plan)->done()->create();
    Item::factory()->for($plan)->create();
    Item::factory()->for($plan)->retired()->create();

    $response = $this->getJson('/api/v1/objectives', mosMobileHeaders($owner))->assertOk();

    expect($response->json('data.*.key'))->toBe(['DINERO', 'SALUD'])
        ->and(array_keys($response->json('data.0')))->toBe([
            'id', 'key', 'title', 'identity_statement', 'state', 'outcome', 'deadline', 'metric', 'progress', 'updated_at',
        ])
        ->and(array_keys($response->json('data.0.metric')))->toBe(['name', 'target', 'current'])
        ->and($response->json('data.0.progress'))->toBe(['done' => 1, 'total' => 2])
        ->and($response->json('data.0.state'))->toBe('active')
        ->and($response->json('data.0.deadline'))->toBe($first->controlPlan->deadline->toDateString())
        ->and($response->json())->not->toHaveKey('meta');
});

test('the list query count does not grow with the number of objectives', function () {
    $one = User::factory()->create();
    Item::factory()->for(Plan::factory()->for(Objective::factory()->for($one)->withControlPlan()))->create();
    $five = User::factory()->create();
    foreach (range(1, 5) as $_) {
        Item::factory()->for(Plan::factory()->for(Objective::factory()->for($five)->withControlPlan()))->count(2)->create();
    }

    $headersOne = mosMobileHeaders($one);
    $headersFive = mosMobileHeaders($five);

    $countOne = mosQueryCount(fn () => $this->getJson('/api/v1/objectives', $headersOne)->assertOk());
    $countFive = mosQueryCount(fn () => $this->getJson('/api/v1/objectives', $headersFive)->assertOk()->assertJsonCount(5, 'data'));

    expect($countFive)->toBe($countOne);
});

test('show returns the objective tree with non-retired items in manual order', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create(['title' => 'Semana 1']);
    $a = Item::factory()->for($plan)->create(['title' => 'A']);
    $b = Item::factory()->for($plan)->milestone()->create(['title' => 'B']);
    Item::factory()->for($plan)->retired()->create(['title' => 'Retirada']);
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    $response = $this->getJson('/api/v1/objectives/SALUD', mosMobileHeaders($owner))->assertOk();

    expect($response->json('data.key'))->toBe('SALUD')
        ->and($response->json('data.plans.0.title'))->toBe('Semana 1')
        ->and($response->json('data.plans.0.items.*.title'))->toBe(['A', 'B'])
        ->and($response->json('data.plans.0.items.1'))->toBe([
            'key' => 'SALUD-2',
            'kind' => 'milestone',
            'title' => 'B',
            'two_minute_version' => $b->two_minute_version,
            'state' => 'locked',
            'prerequisite_keys' => ['SALUD-1'],
        ]);
});

test('a closed objective still resolves; unknown, foreign, retired and draft keys are the same 404', function () {
    $owner = User::factory()->create();
    Objective::factory()->for($owner)->closed()->withControlPlan()->create(['key' => 'CERRADO']);
    Objective::factory()->for($owner)->retired()->withControlPlan()->create(['key' => 'RETIRADO']);
    Objective::factory()->for($owner)->draft()->create(['key' => 'BORRADOR']);
    Objective::factory()->withControlPlan()->create(['key' => 'AJENO']);
    $headers = mosMobileHeaders($owner);

    $this->getJson('/api/v1/objectives/CERRADO', $headers)->assertOk()->assertJsonPath('data.state', 'closed');

    foreach (['NUNCA', 'AJENO', 'RETIRADO', 'BORRADOR'] as $key) {
        $this->getJson("/api/v1/objectives/{$key}", $headers)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Recurso no encontrado.']);
    }
});

test('the show query count does not grow with the number of items', function () {
    $one = Objective::factory()->withControlPlan()->create(['key' => 'UNO']);
    Item::factory()->for(Plan::factory()->for($one))->create();
    $five = Objective::factory()->withControlPlan()->create(['key' => 'CINCO']);
    $plan = Plan::factory()->for($five)->create();
    $items = Item::factory()->for($plan)->count(5)->create();
    foreach ($items->skip(1) as $item) {
        ItemDependency::query()->create(['prerequisite_id' => $items->first()->id, 'dependent_id' => $item->id]);
    }

    $headersOne = mosMobileHeaders($one->user);
    $headersFive = mosMobileHeaders($five->user);

    expect(mosQueryCount(fn () => $this->getJson('/api/v1/objectives/CINCO', $headersFive)->assertOk()))
        ->toBe(mosQueryCount(fn () => $this->getJson('/api/v1/objectives/UNO', $headersOne)->assertOk()));
});

test('objective and item routes enforce the mobile ability', function (string $uri) {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    Item::factory()->for(Plan::factory()->for($objective))->create();

    $this->getJson($uri)->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');

    $mcp = $owner->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;

    $this->getJson($uri, ['Authorization' => "Bearer {$mcp}"])
        ->assertForbidden()
        ->assertExactJson(['message' => 'Este token no tiene permiso para usar esta API.']);
})->with(['/api/v1/objectives', '/api/v1/objectives/SALUD', '/api/v1/objectives/SALUD/items/SALUD-1']);

test('the retired api routes are unregistered and answer the generic 404', function (string $uri) {
    $this->getJson($uri, mosMobileHeaders(User::factory()->create()))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Recurso no encontrado.']);
})->with([
    '/api/v1/projects',
    '/api/v1/projects/DEMO',
    '/api/v1/projects/DEMO/issues',
    '/api/v1/projects/DEMO/issues/DEMO-1',
    '/api/v1/projects/DEMO/board-columns',
    '/api/v1/projects/DEMO/sprints',
    '/api/v1/projects/DEMO/labels',
]);

test('the openapi document is bumped to 2.0.0 and drops the retired tags', function () {
    $document = json_decode((string) file_get_contents(base_path('openapi/v1.json')), true);
    $tags = collect($document['tags'])->pluck('name')->all();

    expect($document['info']['version'])->toBe('2.0.0')
        ->and($tags)->toContain('Objectives', 'Items')
        ->and($tags)->not->toContain('Projects', 'Issues', 'Board', 'Board Columns', 'Sprints', 'Labels')
        ->and($document['paths'])->toHaveKey('/api/v1/objectives/{objective}')
        ->and($document['paths'])->toHaveKey('/api/v1/objectives/{objective}/items/{item}/check');
});
