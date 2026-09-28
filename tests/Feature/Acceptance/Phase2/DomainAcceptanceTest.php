<?php

/*
| Phase 2 acceptance (independent tester) — specs `projects`, `issues`,
| `plans`, `control-plan`: objective / plan / item lifecycle through the web
| surface, key allocation, derived state, 5-point plan and control map.
| Derived from the specs, not from the implementation.
*/

use App\Enums\ItemState;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Models\ControlMapEntry;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, mixed> */
function p2aObjectivePayload(array $overrides = []): array
{
    return array_replace([
        'key' => 'SALUD',
        'title' => 'Correr una media maratón',
        'identity_statement' => 'Soy alguien que corre',
        'outcome' => 'Termino la media maratón de noviembre.',
        'deadline' => '2026-11-30',
        'metric' => ['name' => 'Kilómetros por semana', 'target' => 30, 'current' => 5],
        'risks' => ['Lesión de rodilla'],
        'contingency' => 'Si me duele la rodilla, cambio a bici esa semana.',
        'control_map' => [
            ['zone' => 'mine', 'text' => 'Salir a correr'],
            ['zone' => 'influence', 'text' => 'Horario del trabajo'],
            ['zone' => 'outside', 'text' => 'El clima'],
        ],
    ], $overrides);
}

function p2aObjective(User $owner, string $key = 'SALUD', ObjectiveState $state = ObjectiveState::Active): Objective
{
    return Objective::factory()->for($owner)->withControlPlan()->create(['key' => $key, 'state' => $state]);
}

function p2aPlan(Objective $objective, array $attributes = []): Plan
{
    return Plan::factory()->for($objective)->withControlPlan()->create($attributes);
}

function p2aItem(Plan $plan, array $attributes = []): Item
{
    return Item::factory()->for($plan)->create($attributes)->load('objective');
}

function p2aState(Item $item): ItemState
{
    return Item::query()->withState()->whereKey($item->id)->firstOrFail()->state;
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
});

describe('objectives', function () {
    test('the direct path creates an active objective with a complete 5-point plan and lands on its tree', function () {
        $this->post('/objectives', p2aObjectivePayload())->assertRedirect('/objectives/SALUD');

        $objective = Objective::query()->where('key', 'SALUD')->firstOrFail();
        expect($objective->state)->toBe(ObjectiveState::Active)
            ->and($objective->user_id)->toBe($this->owner->id)
            ->and($objective->next_item_number)->toBe(1)
            ->and(ControlMapEntry::query()->count())->toBe(3);

        $this->get('/objectives/SALUD')->assertOk()->assertInertia(fn (Assert $page) => $page->component('objectives/show'));
    });

    test('an objective without a metric is rejected naming the missing point in Spanish and nothing is saved', function () {
        $response = $this->from('/objectives/create')->post('/objectives', p2aObjectivePayload(['metric' => null]));

        $response->assertRedirect('/objectives/create')->assertSessionHasErrors();
        $errors = collect(session('errors')->getBag('default')->messages())->flatten()->implode(' ');
        expect($errors)->toMatch('/m[ée]trica/iu')
            ->and(Objective::query()->count())->toBe(0);
    });

    test('each missing point of the 5-point plan is refused', function (array $missing, string $pattern) {
        $this->from('/objectives/create')->post('/objectives', p2aObjectivePayload($missing))->assertSessionHasErrors();

        $errors = collect(session('errors')->getBag('default')->messages())->flatten()->implode(' ');
        expect($errors)->toMatch($pattern)
            ->and(Objective::query()->count())->toBe(0);
    })->with([
        'outcome' => [['outcome' => ''], '/resultado/iu'],
        'deadline' => [['deadline' => null], '/fecha/iu'],
        'risks' => [['risks' => []], '/riesgo|salir mal/iu'],
        'contingency' => [['contingency' => ''], '/contingencia/iu'],
    ]);

    test('two metrics are rejected with a Spanish "only one metric" message', function () {
        $payload = p2aObjectivePayload(['metric' => [
            ['name' => 'Km', 'target' => 30],
            ['name' => 'Peso', 'target' => 70],
        ]]);

        $this->from('/objectives/create')->post('/objectives', $payload)->assertSessionHasErrors();

        $errors = collect(session('errors')->getBag('default')->messages())->flatten()->implode(' ');
        expect($errors)->toMatch('/una m[ée]trica/iu')
            ->and(Objective::query()->count())->toBe(0);
    });

    test('a duplicate key is rejected with a Spanish message', function () {
        p2aObjective($this->owner, 'SALUD');

        $this->from('/objectives/create')->post('/objectives', p2aObjectivePayload())->assertSessionHasErrors('key');

        expect(session('errors')->first('key'))->toMatch('/existe|clave/iu')
            ->and(Objective::query()->count())->toBe(1);
    });

    test('malformed keys are rejected', function (string $key) {
        $this->from('/objectives/create')->post('/objectives', p2aObjectivePayload(['key' => $key]))->assertSessionHasErrors('key');
    })->with(['S', 'salud', 'SALUD1', 'ABCDEFGHIJK', 'SA LUD', 'SA-LUD']);

    test('another user\'s objective is 404 (never 403) on every web route', function (string $method, string $uri) {
        $stranger = User::factory()->create();
        $plan = p2aPlan(p2aObjective($stranger, 'AJENO'));
        p2aItem($plan);

        $this->call($method, str_replace('{plan}', (string) $plan->id, $uri))->assertNotFound();
    })->with([
        ['GET', '/objectives/AJENO'],
        ['GET', '/objectives/AJENO/edit'],
        ['PATCH', '/objectives/AJENO'],
        ['POST', '/objectives/AJENO/reopen'],
        ['GET', '/objectives/AJENO/plans/{plan}'],
        ['POST', '/objectives/AJENO/plans/{plan}/items'],
        ['GET', '/objectives/AJENO/items/AJENO-1'],
        ['POST', '/objectives/AJENO/items/AJENO-1/check'],
        ['POST', '/objectives/AJENO/items/AJENO-1/uncheck'],
    ]);

    test('navigation shows only the owner\'s active objectives in manual order', function () {
        Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'BETA', 'position' => 2]);
        Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'ALFA', 'position' => 1]);
        p2aObjective($this->owner, 'CERRADO', ObjectiveState::Closed);
        p2aObjective($this->owner, 'RETIRADO', ObjectiveState::Retired);
        p2aObjective(User::factory()->create(), 'AJENO');

        $this->get('/objectives')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('navigationObjectives', fn ($nav) => collect($nav)->pluck('key')->all() === ['ALFA', 'BETA']));
    });

    test('navigation is empty for guests', function () {
        auth()->logout();

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('navigationObjectives', fn ($nav) => collect($nav)->isEmpty()));
    });

    test('a closed objective can be reopened; closed ones are read-only until then', function () {
        $objective = p2aObjective($this->owner, 'CERRADO', ObjectiveState::Closed);
        $item = p2aItem(p2aPlan($objective));

        $this->post('/objectives/CERRADO/items/CERRADO-1/check')->assertSessionHasErrors();
        expect($item->fresh()->completed_at)->toBeNull();

        $this->post('/objectives/CERRADO/reopen')->assertSessionHasNoErrors();
        expect($objective->fresh()->state)->toBe(ObjectiveState::Active);

        $this->post('/objectives/CERRADO/items/CERRADO-1/check')->assertSessionHasNoErrors();
        expect($item->fresh()->completed_at)->not->toBeNull();
    });

    test('no delete route exists for objectives, plans or items on the web', function (string $uri) {
        $objective = p2aObjective($this->owner, 'SALUD');
        $plan = p2aPlan($objective);
        p2aItem($plan);

        $this->delete(str_replace('{plan}', (string) $plan->id, $uri))->assertStatus(405);

        expect(Objective::query()->count())->toBe(1)
            ->and(Plan::query()->count())->toBe(1)
            ->and(Item::query()->count())->toBe(1);
    })->with(['/objectives/SALUD', '/objectives/SALUD/plans/{plan}', '/objectives/SALUD/items/SALUD-1']);
});

describe('item numbers', function () {
    test('numbers are sequential per objective, reusable across objectives, and a retired number is never reused', function () {
        $salud = p2aPlan(p2aObjective($this->owner, 'SALUD'));
        $dinero = p2aPlan(p2aObjective($this->owner, 'DINERO'));

        $payload = ['title' => 'Tarea', 'two_minute_version' => 'abrir la app'];
        foreach (range(1, 3) as $ignored) {
            $this->post("/objectives/SALUD/plans/{$salud->id}/items", $payload)->assertSessionHasNoErrors();
        }
        $this->post("/objectives/DINERO/plans/{$dinero->id}/items", $payload)->assertSessionHasNoErrors();

        expect(Item::query()->where('objective_id', $salud->objective_id)->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3])
            ->and(Item::query()->where('objective_id', $dinero->objective_id)->pluck('number')->all())->toBe([1]);

        Item::query()->where('objective_id', $salud->objective_id)->where('number', 3)->update(['retired_at' => now()]);

        $this->post("/objectives/SALUD/plans/{$salud->id}/items", $payload)->assertSessionHasNoErrors();

        expect(Item::query()->where('objective_id', $salud->objective_id)->max('number'))->toBe(4);
    });

    test('allocation is collision-free for stale in-memory copies and uses a row lock', function () {
        $objective = p2aObjective($this->owner, 'SALUD');
        $copyA = Objective::query()->findOrFail($objective->id);
        $copyB = Objective::query()->findOrFail($objective->id);

        DB::enableQueryLog();
        $numbers = [];
        foreach (range(1, 20) as $i) {
            $numbers[] = ($i % 2 ? $copyA : $copyB)->allocateNextItemNumber();
        }
        $log = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        expect($numbers)->toBe(range(1, 20))
            ->and($log)->toMatch('/for update/i');
    });

    test('moving an item to another plan of the same objective keeps its number', function () {
        $objective = p2aObjective($this->owner, 'SALUD');
        $a = p2aPlan($objective);
        $b = p2aPlan($objective);
        $item = p2aItem($a);

        $this->patch('/objectives/SALUD/items/SALUD-1', ['plan_id' => $b->id])->assertSessionHasNoErrors();

        expect($item->fresh()->plan_id)->toBe($b->id)
            ->and($item->fresh()->number)->toBe(1);
    });
});

describe('items', function () {
    test('an item without a 2-minute version is rejected in Spanish', function () {
        $plan = p2aPlan(p2aObjective($this->owner));

        $this->post("/objectives/SALUD/plans/{$plan->id}/items", ['title' => 'Solo título'])
            ->assertSessionHasErrors('two_minute_version');

        expect(session('errors')->first('two_minute_version'))->toMatch('/2 minutos/iu')
            ->and(Item::query()->count())->toBe(0);
    });

    test('a new item is appended at the end of its plan', function () {
        $plan = p2aPlan(p2aObjective($this->owner));
        p2aItem($plan);
        p2aItem($plan);

        $this->post("/objectives/SALUD/plans/{$plan->id}/items", ['title' => 'Tercera', 'two_minute_version' => 'x'])->assertSessionHasNoErrors();

        expect(Item::query()->where('plan_id', $plan->id)->orderBy('position')->orderBy('id')->pluck('title')->last())->toBe('Tercera');
    });

    test('a task is completed with a single check and gets a UTC timestamp', function () {
        $item = p2aItem(p2aPlan(p2aObjective($this->owner)));

        $this->post('/objectives/SALUD/items/SALUD-1/check')->assertSessionHasNoErrors();

        expect(p2aState($item))->toBe(ItemState::Done)
            ->and($item->fresh()->completed_at)->not->toBeNull();
    });

    test('a milestone cannot be completed with empty evidence, and can with a line', function () {
        $item = p2aItem(p2aPlan(p2aObjective($this->owner)), ['kind' => 'milestone']);

        $this->post('/objectives/SALUD/items/SALUD-1/check', ['evidence' => '   '])->assertSessionHasErrors('evidence');
        expect(session('errors')->first('evidence'))->toMatch('/evidencia/iu')
            ->and($item->fresh()->completed_at)->toBeNull();

        $this->post('/objectives/SALUD/items/SALUD-1/check', ['evidence' => 'Corrí 10 km'])->assertSessionHasNoErrors();
        expect($item->fresh()->completed_at)->not->toBeNull()
            ->and($item->fresh()->evidence->text)->toBe('Corrí 10 km');
    });

    test('a locked item cannot be checked and stays locked; checking the prerequisite unlocks it and flashes it', function () {
        $plan = p2aPlan(p2aObjective($this->owner));
        $a = p2aItem($plan, ['title' => 'A']);
        $b = p2aItem($plan, ['title' => 'B']);
        $b->prerequisites()->attach($a->id);

        $this->post('/objectives/SALUD/items/SALUD-2/check')->assertSessionHasErrors();
        expect(p2aState($b))->toBe(ItemState::Locked);

        $this->post('/objectives/SALUD/items/SALUD-1/check')->assertSessionHasNoErrors()->assertSessionHas('unlocked', [['key' => 'SALUD-2', 'title' => 'B']]);
        expect(p2aState($b))->toBe(ItemState::Available);
    });

    test('unchecking relocks a dependent; an active dependent stops being active but keeps its focus history', function () {
        $plan = p2aPlan(p2aObjective($this->owner));
        $a = p2aItem($plan, ['completed_at' => now()]);
        $b = p2aItem($plan, ['is_active' => true]);
        $b->prerequisites()->attach($a->id);
        $b->focusSessions()->create(['started_at' => now()->subMinutes(10)]);

        $this->post('/objectives/SALUD/items/SALUD-1/uncheck')->assertSessionHasNoErrors();

        expect(p2aState($a))->toBe(ItemState::Available)
            ->and(p2aState($b))->toBe(ItemState::Locked)
            ->and($b->focusSessions()->count())->toBe(1)
            ->and($b->focusSessions()->first()->ended_at)->not->toBeNull();
    });

    test('an already-done item checked again is unchanged (idempotent)', function () {
        $item = p2aItem(p2aPlan(p2aObjective($this->owner)), ['completed_at' => now()->subDay()]);
        $before = $item->fresh()->completed_at->toIso8601String();

        $this->post('/objectives/SALUD/items/SALUD-1/check')->assertSessionHasNoErrors();

        expect($item->fresh()->completed_at->toIso8601String())->toBe($before);
    });

    test('moving an item to a plan of another objective is rejected', function () {
        $item = p2aItem(p2aPlan(p2aObjective($this->owner, 'SALUD')));
        $foreignPlan = p2aPlan(p2aObjective($this->owner, 'DINERO'));

        $this->patch('/objectives/SALUD/items/SALUD-1', ['plan_id' => $foreignPlan->id])->assertSessionHasErrors('plan_id');

        expect($item->fresh()->plan_id)->not->toBe($foreignPlan->id);
    });

    test('the 2-minute version cannot be cleared; replacing it keeps the previous one in history', function () {
        $item = p2aItem(p2aPlan(p2aObjective($this->owner)), ['two_minute_version' => 'abrir el editor']);

        $this->patch('/objectives/SALUD/items/SALUD-1', ['two_minute_version' => ''])->assertSessionHasErrors('two_minute_version');
        expect($item->fresh()->two_minute_version)->toBe('abrir el editor');

        $this->patch('/objectives/SALUD/items/SALUD-1', ['two_minute_version' => 'escribir el título'])->assertSessionHasNoErrors();
        expect($item->fresh()->two_minute_version)->toBe('escribir el título')
            ->and(DB::table('item_two_minute_history')->where('item_id', $item->id)->pluck('text')->all())->toBe(['abrir el editor']);
    });

    test('the owner edits title, description and target date', function () {
        $item = p2aItem(p2aPlan(p2aObjective($this->owner)));

        $this->patch('/objectives/SALUD/items/SALUD-1', [
            'title' => 'Nuevo', 'description' => 'Notas', 'target_date' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $fresh = $item->fresh();
        expect($fresh->title)->toBe('Nuevo')
            ->and($fresh->description)->toBe('Notas')
            ->and($fresh->target_date->toDateString())->toBe('2026-10-01');
    });

    test('guests are redirected to login on item routes', function () {
        p2aItem(p2aPlan(p2aObjective($this->owner)));
        auth()->logout();

        $this->get('/objectives/SALUD/items/SALUD-1')->assertRedirect('/login');
        $this->patch('/objectives/SALUD/items/SALUD-1', ['title' => 'x'])->assertRedirect('/login');
    });
});

describe('plans', function () {
    test('a plan is appended after the existing ones', function () {
        $objective = p2aObjective($this->owner);
        p2aPlan($objective, ['title' => 'Uno']);
        p2aPlan($objective, ['title' => 'Dos']);

        $this->post('/objectives/SALUD/plans', array_replace(p2aObjectivePayload(), ['title' => 'Tres', 'activate' => true]))
            ->assertSessionHasNoErrors();

        expect($objective->plans()->pluck('title')->all())->toBe(['Uno', 'Dos', 'Tres']);
    });

    test('a plan cannot be activated without its complete 5-point plan', function () {
        $objective = p2aObjective($this->owner);

        $this->post('/objectives/SALUD/plans', ['title' => 'Sin plan', 'activate' => true, 'outcome' => 'algo'])
            ->assertSessionHasErrors();

        expect(Plan::query()->where('title', 'Sin plan')->where('state', PlanState::Active->value)->exists())->toBeFalse();
    });

    test('checking the last open item completes the plan; unchecking returns it to active; retired items do not count', function () {
        $plan = p2aPlan(p2aObjective($this->owner));
        p2aItem($plan, ['completed_at' => now()]);
        p2aItem($plan);
        p2aItem($plan, ['retired_at' => now()]);

        $this->post('/objectives/SALUD/items/SALUD-2/check')->assertSessionHasNoErrors();
        expect($plan->fresh()->state)->toBe(PlanState::Done);

        $this->post('/objectives/SALUD/items/SALUD-2/uncheck')->assertSessionHasNoErrors();
        expect($plan->fresh()->state)->toBe(PlanState::Active);
    });

    test('a plan id of another objective does not resolve under this objective', function () {
        p2aObjective($this->owner, 'SALUD');
        $foreign = p2aPlan(p2aObjective($this->owner, 'DINERO'));

        $this->get("/objectives/SALUD/plans/{$foreign->id}")->assertNotFound();
        $this->post("/objectives/SALUD/plans/{$foreign->id}/items", ['title' => 'x', 'two_minute_version' => 'y'])->assertNotFound();
    });

    test('the next level is suggested at 80% and never activated automatically', function () {
        $objective = p2aObjective($this->owner);
        $level1 = p2aPlan($objective, ['level' => 1]);
        foreach (range(1, 10) as $i) {
            p2aItem($level1, $i <= 8 ? ['completed_at' => now()] : []);
        }

        $this->get('/objectives/SALUD')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('levelSuggestion', fn ($value) => $value !== null && $value !== false));

        expect(Plan::query()->where('level', 2)->exists())->toBeFalse();
    });

    test('below 80% no level suggestion is shown', function () {
        $objective = p2aObjective($this->owner);
        $level1 = p2aPlan($objective, ['level' => 1]);
        foreach (range(1, 10) as $i) {
            p2aItem($level1, $i <= 7 ? ['completed_at' => now()] : []);
        }

        $this->get('/objectives/SALUD')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('levelSuggestion', fn ($value) => empty($value)));
    });
});

describe('control map', function () {
    test('an outside-zone entry cannot be converted into a task; a mine-zone entry can', function () {
        $objective = p2aObjective($this->owner);
        $plan = p2aPlan($objective);
        $outside = $objective->controlMapEntries()->create(['zone' => 'outside', 'text' => 'El clima', 'position' => 0]);
        $mine = $objective->controlMapEntries()->create(['zone' => 'mine', 'text' => 'Correr', 'position' => 1]);

        $this->post("/objectives/SALUD/control-map/{$outside->id}/convert", ['plan_id' => $plan->id, 'two_minute_version' => 'x']);
        expect(Item::query()->count())->toBe(0);

        $this->post("/objectives/SALUD/control-map/{$mine->id}/convert", ['plan_id' => $plan->id, 'two_minute_version' => 'ponerme las zapatillas'])
            ->assertSessionHasNoErrors();
        expect(Item::query()->count())->toBe(1);
    });

    test('the objective screen does not offer conversion for outside entries', function () {
        $objective = p2aObjective($this->owner);
        $objective->controlMapEntries()->create(['zone' => 'outside', 'text' => 'El clima', 'position' => 0]);

        $this->get('/objectives/SALUD')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('controlMap', function ($map) {
                $flat = json_encode($map);

                return str_contains($flat, 'El clima') && ! preg_match('/"El clima"[^}]*"can_become_task":true/', $flat);
            }));
    });

    test('a control map entry of another objective is not found', function () {
        p2aObjective($this->owner, 'SALUD');
        $other = p2aObjective($this->owner, 'DINERO');
        $entry = $other->controlMapEntries()->create(['zone' => 'mine', 'text' => 'x', 'position' => 0]);

        $this->patch("/objectives/SALUD/control-map/{$entry->id}", ['text' => 'hack'])->assertNotFound();
        $this->delete("/objectives/SALUD/control-map/{$entry->id}")->assertNotFound();
        expect($entry->fresh()->text)->toBe('x');
    });
});

describe('the objective tree screen', function () {
    test('retired items are hidden, done and available shown', function () {
        $plan = p2aPlan(p2aObjective($this->owner));
        p2aItem($plan, ['title' => 'Hecha', 'completed_at' => now()]);
        p2aItem($plan, ['title' => 'Disponible']);
        p2aItem($plan, ['title' => 'Retirada', 'retired_at' => now()]);

        $this->get('/objectives/SALUD')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('plans', function ($plans) {
                // The mockup (screen 04) names hidden retired items in a footer
                // note; they must never be a row of the tree.
                $titles = collect($plans)->flatMap(fn ($plan) => collect($plan['items'])->pluck('title'))->all();

                return in_array('Hecha', $titles, true) && in_array('Disponible', $titles, true) && ! in_array('Retirada', $titles, true);
            }));
    });

    test('a past target date and a behind-schedule metric are never rendered with danger or overdue wording', function () {
        $plan = p2aPlan(p2aObjective($this->owner));
        p2aItem($plan, ['target_date' => now()->subDays(3)->toDateString()]);

        foreach (['/objectives/SALUD', '/objectives/SALUD/items/SALUD-1', "/objectives/SALUD/plans/{$plan->id}", '/objectives'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();
            expect($html)->not->toMatch('/vencid|atrasad|overdue|retrasad/iu');
        }
    });
});
