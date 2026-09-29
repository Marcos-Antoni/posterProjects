<?php

/*
| Phase 2 acceptance (independent tester) — specs `issues` (deep links),
| `api-projects`, `api-issues`, `api-auth` (Phase-2 parts) and `mcp-server`
| (Phase-2 tools): uniform 404 non-disclosure, pinned shapes, query counts,
| mobile ability boundary, MCP ↔ web parity and the retired surface.
*/

use App\Enums\ObjectiveState;
use App\Enums\TokenName;
use App\Http\Resources\ItemDetails;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Items\CheckItem;
use App\Mcp\Tools\Items\ShowItem;
use App\Mcp\Tools\Items\UncheckItem;
use App\Mcp\Tools\Objectives\ListObjectives;
use App\Mcp\Tools\Objectives\ShowObjective;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function p2xHeaders(User $user, array $abilities = ['mobile']): array
{
    return [
        'Authorization' => 'Bearer '.$user->createToken($abilities[0], $abilities)->plainTextToken,
        'Accept' => 'application/json',
    ];
}

function p2xQueries(Closure $request): int
{
    app('auth')->forgetGuards();
    Model::preventLazyLoading();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    Model::preventLazyLoading(false);

    return $count;
}

function p2xObjective(User $owner, string $key, ObjectiveState $state = ObjectiveState::Active, int $position = 0): Objective
{
    return Objective::factory()->for($owner)->withControlPlan()->create(['key' => $key, 'state' => $state, 'position' => $position]);
}

function p2xItem(Plan $plan, array $attributes = []): Item
{
    return Item::factory()->for($plan)->create($attributes)->load('objective');
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->salud = p2xObjective($this->owner, 'SALUD');
    $this->plan = Plan::factory()->for($this->salud)->create(['title' => 'Base']);
    $this->a = p2xItem($this->plan, ['title' => 'A']);
    $this->b = p2xItem($this->plan, ['title' => 'B']);
    ItemDependency::query()->create(['prerequisite_id' => $this->a->id, 'dependent_id' => $this->b->id]);
    $this->p2xRetired = p2xItem($this->plan, ['title' => 'Retirada', 'retired_at' => now()]); // SALUD-3
    $this->dinero = p2xObjective($this->owner, 'DINERO');
    p2xItem(Plan::factory()->for($this->dinero)->create());                                  // DINERO-1
    $this->stranger = User::factory()->create();
    p2xItem(Plan::factory()->for(p2xObjective($this->stranger, 'AJENO'))->create());          // AJENO-1
    p2xObjective($this->owner, 'RETIRO', ObjectiveState::Retired);
});

describe('deep links fail closed (web)', function () {
    test('a malformed, mismatched, unknown, foreign or retired key is 404', function (string $uri) {
        $this->actingAs($this->owner)->get($uri)->assertNotFound();
    })->with([
        'no dash' => '/objectives/SALUD/items/SALUD1',
        'non-numeric suffix' => '/objectives/SALUD/items/SALUD-x',
        'empty suffix' => '/objectives/SALUD/items/SALUD-',
        'negative' => '/objectives/SALUD/items/SALUD--1',
        'nonexistent number' => '/objectives/SALUD/items/SALUD-99',
        'huge number' => '/objectives/SALUD/items/SALUD-99999999999999999999',
        'prefix mismatch' => '/objectives/SALUD/items/DINERO-1',
        'lowercase prefix' => '/objectives/SALUD/items/salud-1',
        'another user' => '/objectives/AJENO/items/AJENO-1',
        'retired item' => '/objectives/SALUD/items/SALUD-3',
        'retired objective' => '/objectives/RETIRO',
        'unknown objective' => '/objectives/NADA',
    ]);

    test('a valid deep link is a full page that survives a refresh', function () {
        $this->actingAs($this->owner)->get('/objectives/SALUD/items/SALUD-2')
            ->assertOk()
            ->assertSee('<!DOCTYPE html>', false)
            ->assertSee('items\\/show', false);
    });

    test('write routes on a retired item are 404 too', function (string $method, string $uri) {
        $this->actingAs($this->owner)->call($method, $uri, ['title' => 'x'])->assertNotFound();
        expect($this->p2xRetired->fresh()->title)->toBe('Retirada');
    })->with([
        ['POST', '/objectives/SALUD/items/SALUD-3/check'],
        ['POST', '/objectives/SALUD/items/SALUD-3/uncheck'],
        ['PATCH', '/objectives/SALUD/items/SALUD-3'],
    ]);
});

describe('api v1 objectives', function () {
    test('lists exactly the active objectives in manual order with the pinned shape', function () {
        $this->salud->update(['position' => 2]);
        $this->dinero->update(['position' => 1]);
        p2xObjective($this->owner, 'CERRADO', ObjectiveState::Closed);
        p2xObjective($this->owner, 'BORRADOR', ObjectiveState::Draft);

        $response = $this->getJson('/api/v1/objectives', p2xHeaders($this->owner))->assertOk();

        expect(collect($response->json('data'))->pluck('key')->all())->toBe(['DINERO', 'SALUD'])
            ->and(array_keys($response->json()))->toBe(['data'])
            ->and(array_keys($response->json('data.1')))->toEqualCanonicalizing(['id', 'key', 'title', 'identity_statement', 'state', 'outcome', 'deadline', 'metric', 'progress', 'updated_at'])
            ->and(array_keys($response->json('data.1.metric')))->toEqualCanonicalizing(['name', 'target', 'current'])
            ->and($response->json('data.1.progress'))->toBe(['done' => 0, 'total' => 2]);
    });

    test('progress counts only non-retired items and done ones', function () {
        $this->a->update(['completed_at' => now()]);

        $response = $this->getJson('/api/v1/objectives/SALUD', p2xHeaders($this->owner))->assertOk();

        expect($response->json('data.progress'))->toBe(['done' => 1, 'total' => 2]);
    });

    test('the list query count does not grow with objectives', function () {
        $headers = p2xHeaders($this->owner);
        $this->getJson('/api/v1/objectives', $headers)->assertOk(); // warm the token (last_used_at)
        $one = p2xQueries(fn () => $this->getJson('/api/v1/objectives', $headers)->assertOk());

        foreach (range(1, 5) as $i) {
            $objective = p2xObjective($this->owner, 'EXTRA'.chr(64 + $i));
            p2xItem(Plan::factory()->for($objective)->create());
        }

        $many = p2xQueries(fn () => $this->getJson('/api/v1/objectives', $headers)->assertOk());

        expect($many)->toBe($one);
    });

    test('the detail returns the tree without retired items, each item with the pinned keys', function () {
        $response = $this->getJson('/api/v1/objectives/SALUD', p2xHeaders($this->owner))->assertOk();

        $items = collect($response->json('data.plans'))->flatMap(fn ($plan) => $plan['items']);
        expect($items->pluck('key')->all())->toBe(['SALUD-1', 'SALUD-2'])
            ->and(array_keys($items->first()))->toEqualCanonicalizing(['key', 'kind', 'title', 'two_minute_version', 'state', 'prerequisite_keys'])
            ->and($items->firstWhere('key', 'SALUD-2')['prerequisite_keys'])->toBe(['SALUD-1'])
            ->and($items->firstWhere('key', 'SALUD-2')['state'])->toBe('locked');
    });

    test('the detail query count is identical for 1 and 5 items with prerequisites', function () {
        $headers = p2xHeaders($this->owner);
        $solo = p2xObjective($this->owner, 'SOLO');
        $plan = Plan::factory()->for($solo)->create();
        p2xItem($plan);
        $this->getJson('/api/v1/objectives/SOLO', $headers)->assertOk(); // warm the token

        $one = p2xQueries(fn () => $this->getJson('/api/v1/objectives/SOLO', $headers)->assertOk());

        $previous = Item::query()->where('objective_id', $solo->id)->first();
        foreach (range(1, 4) as $i) {
            $next = p2xItem(Plan::factory()->for($solo)->create());
            ItemDependency::query()->create(['prerequisite_id' => $previous->id, 'dependent_id' => $next->id]);
            ItemDependency::query()->create(['prerequisite_id' => $this->a->id, 'dependent_id' => $next->id]);
            $previous = $next;
        }

        $five = p2xQueries(fn () => $this->getJson('/api/v1/objectives/SOLO', $headers)->assertOk());

        expect($five)->toBe($one);
    });

    test('unknown, foreign, retired and draft keys are the same generic 404, never 403', function (string $key) {
        p2xObjective($this->owner, 'BORRADOR', ObjectiveState::Draft);

        $this->getJson("/api/v1/objectives/{$key}", p2xHeaders($this->owner))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Recurso no encontrado.']);
    })->with(['NADA', 'AJENO', 'RETIRO', 'BORRADOR']);

    test('a closed objective resolves on the API', function () {
        p2xObjective($this->owner, 'CERRADO', ObjectiveState::Closed);

        $this->getJson('/api/v1/objectives/CERRADO', p2xHeaders($this->owner))->assertOk()->assertJsonPath('data.state', 'closed');
    });

    test('the mobile ability boundary: no token 401 with a bearer challenge, mcp-only token 403 in Spanish', function (string $method, string $uri) {
        $this->json($method, $uri)->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');

        $forbidden = $this->json($method, $uri, [], p2xHeaders($this->owner, ['mcp']))->assertForbidden();
        expect($forbidden->json('message'))->toBeString()->not->toBe('This action is unauthorized.');
    })->with([
        ['GET', '/api/v1/objectives'],
        ['GET', '/api/v1/objectives/SALUD'],
        ['GET', '/api/v1/objectives/SALUD/items/SALUD-1'],
        ['POST', '/api/v1/objectives/SALUD/items/SALUD-1/check'],
    ]);
});

describe('api v1 items', function () {
    test('the detail shape is pinned and embeds prerequisites and unlocks', function () {
        $c = p2xItem($this->plan, ['title' => 'C']); // SALUD-4
        ItemDependency::query()->create(['prerequisite_id' => $this->a->id, 'dependent_id' => $c->id]);

        $data = $this->getJson('/api/v1/objectives/SALUD/items/SALUD-1', p2xHeaders($this->owner))->assertOk()->json('data');

        expect(array_keys($data))->toEqualCanonicalizing(['id', 'key', 'kind', 'title', 'description', 'two_minute_version', 'state', 'target_date', 'plan', 'prerequisites', 'unlocks', 'completed_at', 'evidence', 'updated_at'])
            ->and($data['plan'])->toBe(['id' => $this->plan->id, 'title' => 'Base'])
            ->and($data['prerequisites'])->toBe([])
            ->and($data['unlocks'])->toHaveCount(2)
            ->and(array_keys($data['unlocks'][0]))->toBe(['key', 'title', 'state'])
            ->and($data['evidence'])->toBeNull();
    });

    test('item keys fail closed identically', function (string $key) {
        $headers = p2xHeaders($this->owner);
        $reference = $this->getJson('/api/v1/objectives/SALUD/items/SALUD-99', $headers)->assertNotFound()->getContent();

        expect($this->getJson("/api/v1/objectives/SALUD/items/{$key}", $headers)->assertNotFound()->getContent())->toBe($reference);
        expect($this->postJson("/api/v1/objectives/SALUD/items/{$key}/check", [], $headers)->assertNotFound()->getContent())->toBe($reference);
    })->with(['nodash', 'SALUD-', 'SALUD-x', 'DINERO-1', 'SALUD-3', 'AJENO-1']);

    test('checking a task reports what it unlocked; a locked item is 422; an already-done one is 200 unchanged', function () {
        $headers = p2xHeaders($this->owner);

        $this->postJson('/api/v1/objectives/SALUD/items/SALUD-2/check', [], $headers)->assertUnprocessable();

        $response = $this->postJson('/api/v1/objectives/SALUD/items/SALUD-1/check', [], $headers)->assertOk();
        expect($response->json('data.state'))->toBe('done')
            ->and($response->json('data.unlocked'))->toBe([['key' => 'SALUD-2', 'title' => 'B', 'state' => 'available']]);

        $completedAt = $this->a->fresh()->completed_at->toIso8601String();
        $again = $this->postJson('/api/v1/objectives/SALUD/items/SALUD-1/check', [], $headers)->assertOk();
        expect($again->json('data.completed_at'))->toBe($completedAt)
            ->and($again->json('data.unlocked'))->toBe([]);
    });

    test('checking a milestone through the API requires non-empty evidence', function () {
        p2xItem($this->plan, ['kind' => 'milestone']); // SALUD-4
        $headers = p2xHeaders($this->owner);

        $this->postJson('/api/v1/objectives/SALUD/items/SALUD-4/check', ['evidence' => ''], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/objectives/SALUD/items/SALUD-4/check', [], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/objectives/SALUD/items/SALUD-4/check', ['evidence' => 'Listo'], $headers)
            ->assertOk()
            ->assertJsonPath('data.evidence.text', 'Listo');
    });

    test('an item of a closed objective is readable but not checkable through the API', function () {
        $closed = p2xObjective($this->owner, 'CERRADO', ObjectiveState::Closed);
        p2xItem(Plan::factory()->for($closed)->create());
        $headers = p2xHeaders($this->owner);

        $this->getJson('/api/v1/objectives/CERRADO/items/CERRADO-1', $headers)->assertOk();
        $this->postJson('/api/v1/objectives/CERRADO/items/CERRADO-1/check', [], $headers)->assertUnprocessable();
    });

    test('the item detail query count does not grow with neighbours', function () {
        $headers = p2xHeaders($this->owner);
        $this->getJson('/api/v1/objectives/SALUD/items/SALUD-1', $headers)->assertOk(); // warm the token
        $one = p2xQueries(fn () => $this->getJson('/api/v1/objectives/SALUD/items/SALUD-1', $headers)->assertOk());

        foreach (range(1, 5) as $i) {
            $dependent = p2xItem(Plan::factory()->for($this->dinero)->create());
            ItemDependency::query()->create(['prerequisite_id' => $this->a->id, 'dependent_id' => $dependent->id]);
        }

        $many = p2xQueries(fn () => $this->getJson('/api/v1/objectives/SALUD/items/SALUD-1', $headers)->assertOk());

        expect($many)->toBe($one);
    });
});

describe('retired api surface', function () {
    test('retired routes are unregistered and answer the generic 404 body', function (string $method, string $uri) {
        $this->json($method, $uri, [], p2xHeaders($this->owner))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Recurso no encontrado.']);
    })->with([
        ['GET', '/api/v1/projects'],
        ['GET', '/api/v1/projects/SALUD'],
        ['GET', '/api/v1/projects/SALUD/issues'],
        ['GET', '/api/v1/projects/SALUD/issues/SALUD-1'],
        ['GET', '/api/v1/projects/SALUD/board-columns'],
        ['GET', '/api/v1/projects/SALUD/sprints'],
        ['GET', '/api/v1/projects/SALUD/labels'],
    ]);

    test('DELETE on objective and item API paths is refused and changes nothing', function (string $uri) {
        $status = $this->deleteJson($uri, [], p2xHeaders($this->owner))->status();

        expect($status)->toBeIn([404, 405])
            ->and(Objective::query()->where('key', 'SALUD')->exists())->toBeTrue()
            ->and(Item::query()->whereKey($this->a->id)->exists())->toBeTrue();
    })->with(['/api/v1/objectives/SALUD', '/api/v1/objectives/SALUD/items/SALUD-1']);

    test('no registered route names or paths belong to a retired domain', function () {
        $routes = collect(Route::getRoutes()->getRoutes());
        $offending = $routes->filter(fn ($route) => preg_match('/project|issue|sprint|board|backlog|label|comment|calendar|trash/i', $route->uri().' '.$route->getName()));

        expect($offending->map(fn ($route) => $route->uri())->values()->all())->toBe([]);
    });

    test('no DELETE route exists for objectives, plans or items anywhere', function () {
        $deletes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('DELETE', $route->methods(), true))
            ->map(fn ($route) => $route->uri())
            ->filter(fn ($uri) => preg_match('#^(api/v1/)?objectives(/\{objective\})?(/plans/\{plan\})?(/items/\{item\})?$#', $uri))
            ->values();

        expect($deletes->all())->toBe([]);
    });

    test('openapi documents exactly the registered api routes, bumps the version and drops retired tags', function () {
        $doc = json_decode(file_get_contents(base_path('openapi/v1.json')), true);

        expect(version_compare($doc['info']['version'], '2.0.0', '>='))->toBeTrue()
            ->and(json_encode($doc))->not->toMatch('/"\/api\/v1\/projects/')
            ->and(collect($doc['tags'] ?? [])->pluck('name')->all())->not->toContain('Projects', 'Issues', 'Board', 'Sprints', 'Labels')
            ->and(collect($doc['tags'] ?? [])->pluck('name')->all())->toContain('Objectives', 'Items');

        foreach (['/api/v1/objectives', '/api/v1/objectives/{objective}', '/api/v1/objectives/{objective}/items/{item}', '/api/v1/objectives/{objective}/items/{item}/check'] as $path) {
            expect($doc['paths'])->toHaveKey($path);
            foreach ($doc['paths'][$path] as $operation) {
                expect($operation['security'] ?? null)->toBe([['bearerAuth' => []]]);
            }
        }
    });
});

describe('mcp parity', function () {
    test('retired tools are not listed and every Phase-2 tool is', function () {
        $token = $this->owner->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;

        $names = collect($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 100]], [
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream',
        ])->assertOk()->json('result.tools'))->pluck('name');

        expect($names)->toContain('list-objectives', 'show-objective', 'show-item', 'check-item', 'uncheck-item');

        foreach (['board-view', 'backlog-view', 'calendar-view', 'create-sprint', 'update-sprint', 'delete-sprint', 'create-label', 'attach-issue-label', 'create-comment', 'force-delete-project', 'list-trashed-projects', 'restore-project', 'archive-project', 'move-issue', 'create-issue', 'show-issue', 'list-projects', 'create-board-column'] as $retired) {
            expect($names)->not->toContain($retired);
        }
    });

    test('list-objectives returns the same keys, in the same order, as the web index and the API', function () {
        $this->salud->update(['position' => 2]);
        $this->dinero->update(['position' => 1]);

        $mcp = PosterServer::actingAs($this->owner)->tool(ListObjectives::class)->assertOk();
        $api = collect($this->getJson('/api/v1/objectives', p2xHeaders($this->owner))->json('data'))->pluck('key')->all();

        expect($api)->toBe(['DINERO', 'SALUD']);
        $mcp->assertSee('DINERO')->assertSee('SALUD')->assertDontSee('AJENO')->assertDontSee('RETIRO');
    });

    test('show-item returns the same payload as the API item detail plus the absolute url', function () {
        $api = $this->getJson('/api/v1/objectives/SALUD/items/SALUD-2', p2xHeaders($this->owner))->json('data');

        $response = PosterServer::actingAs($this->owner)->tool(ShowItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-2'])->assertOk();

        $response->assertSee(route('objectives.items.show', ['SALUD', 'SALUD-2']))
            ->assertSee('SALUD-2')
            ->assertSee($api['two_minute_version'])
            ->assertSee('locked');
    });

    test('mcp lookups are scoped: foreign, retired, prefix-mismatched and malformed keys are not found', function (string $objective, string $item) {
        PosterServer::actingAs($this->owner)
            ->tool(ShowItem::class, ['objective_key' => $objective, 'item_key' => $item])
            ->assertHasErrors();
    })->with([
        ['SALUD', 'DINERO-1'],
        ['SALUD', 'SALUD-3'],
        ['SALUD', 'nodash'],
        ['AJENO', 'AJENO-1'],
        ['RETIRO', 'RETIRO-1'],
    ]);

    test('check-item refuses a locked item exactly like the web and leaves it untouched', function () {
        PosterServer::actingAs($this->owner)
            ->tool(CheckItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-2'])
            ->assertHasErrors();

        expect($this->b->fresh()->completed_at)->toBeNull();
    });

    test('check-item reports unlocked items exactly as the web flash', function () {
        PosterServer::actingAs($this->owner)
            ->tool(CheckItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-1'])
            ->assertOk()
            ->assertSee('SALUD-2');

        expect($this->a->fresh()->completed_at)->not->toBeNull();
        expect(Item::query()->withState()->whereKey($this->b->id)->first()->state->value)->toBe('available');
    });

    test('check-item on a milestone without evidence is refused; uncheck-item relocks the dependent', function () {
        p2xItem($this->plan, ['kind' => 'milestone']); // SALUD-4

        PosterServer::actingAs($this->owner)
            ->tool(CheckItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-4'])
            ->assertHasErrors();

        $this->a->update(['completed_at' => now()]);

        PosterServer::actingAs($this->owner)
            ->tool(UncheckItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-1'])
            ->assertOk();

        expect($this->a->fresh()->completed_at)->toBeNull()
            ->and(Item::query()->withState()->whereKey($this->b->id)->first()->state->value)->toBe('locked');
    });

    test('mcp cannot check another user\'s item or an item in a closed objective', function () {
        $closed = p2xObjective($this->owner, 'CERRADO', ObjectiveState::Closed);
        $closedItem = p2xItem(Plan::factory()->for($closed)->create());

        PosterServer::actingAs($this->owner)->tool(CheckItem::class, ['objective_key' => 'AJENO', 'item_key' => 'AJENO-1'])->assertHasErrors();
        PosterServer::actingAs($this->owner)->tool(CheckItem::class, ['objective_key' => 'CERRADO', 'item_key' => 'CERRADO-1'])->assertHasErrors();

        expect($closedItem->fresh()->completed_at)->toBeNull()
            ->and(Item::query()->whereHas('objective', fn ($q) => $q->where('key', 'AJENO'))->first()->completed_at)->toBeNull();
    });

    test('show-objective hides retired items and matches the API tree keys', function () {
        $api = collect($this->getJson('/api/v1/objectives/SALUD', p2xHeaders($this->owner))->json('data.plans'))
            ->flatMap(fn ($plan) => collect($plan['items'])->pluck('key'))->all();

        PosterServer::actingAs($this->owner)->tool(ShowObjective::class, ['objective_key' => 'SALUD'])
            ->assertOk()
            ->assertSee('SALUD-1')
            ->assertSee('SALUD-2')
            ->assertDontSee('SALUD-3')
            ->assertDontSee('Retirada')
            ->assertSee(route('objectives.show', 'SALUD'));

        expect($api)->toBe(['SALUD-1', 'SALUD-2']);
    });

    test('item details present the same shape for web, api and mcp (single source)', function () {
        $item = ItemDetails::load(Item::query()->findOrFail($this->b->id));
        $shared = ItemDetails::present($item);

        $api = $this->getJson('/api/v1/objectives/SALUD/items/SALUD-2', p2xHeaders($this->owner))->json('data');

        expect($api)->toBe($shared);
    });
});
