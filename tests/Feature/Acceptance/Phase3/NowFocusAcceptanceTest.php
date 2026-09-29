<?php

/*
| Phase 3 acceptance (independent tester) — now-focus, auth landing and MCP
| now-view, checked against the specs and design D6/D7/D14, not against the
| implementer's tests: one active task (routes + DB), Now selection rules,
| restart without debt on the America/Guatemala (UTC-6) calendar, the "estoy
| trabado" fallback with history, MCP parity and the Now landing.
*/

use App\Actions\Items\ShrinkTwoMinuteVersion;
use App\Actions\Items\StartItem;
use App\Actions\Support\Actor;
use App\Actions\Support\LastActivity;
use App\Actions\Support\WeeklyMainPriority;
use App\Enums\TwoMinuteSource;
use App\Http\Resources\NowView;
use App\Models\FocusSession;
use App\Models\Habit;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function p3aOwner(string $key = 'DIARIO', array $attributes = []): array
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => $key, 'title' => 'Marcos OS en uso diario', ...$attributes]);
    $plan = Plan::factory()->for($objective)->create(['title' => 'Semana 1']);

    return [$owner, $objective, $plan];
}

function p3aState(Item $item): string
{
    return Item::query()->withState()->whereKey($item->id)->firstOrFail()->state->value;
}

function p3aPriority(Objective|Plan|null $priority): void
{
    app()->instance(WeeklyMainPriority::class, new class($priority) implements WeeklyMainPriority
    {
        public function __construct(private Objective|Plan|null $priority) {}

        public function currentFor(User $owner): Objective|Plan|null
        {
            return $this->priority;
        }
    });
}

function p3aMcp(User $owner, string $method = 'tools/call', array $params = ['name' => 'now-view', 'arguments' => []]): array
{
    app('auth')->forgetGuards();
    $token = $owner->createToken('mcp', ['mcp'])->plainTextToken;
    $params['arguments'] = (object) ($params['arguments'] ?? []);

    return test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], [
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ])->assertOk()->json();
}

function p3aNowProps(User $owner): array
{
    app('auth')->forgetGuards();

    return test()->actingAs($owner)->get('/now')->assertOk()->viewData('page')['props'];
}

// ---------------------------------------------------------------- one active

describe('exactly one active task', function () {
    test('starting a second task over the web pauses the first, which becomes available again', function () {
        [$owner, , $plan] = p3aOwner();
        $a = Item::factory()->for($plan)->create(['title' => 'A']);
        $b = Item::factory()->for($plan)->create(['title' => 'B']);

        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start')->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/objectives/DIARIO/items/DIARIO-2/start')->assertRedirect()->assertSessionHasNoErrors();

        expect(p3aState($b))->toBe('active')
            ->and(p3aState($a))->toBe('available')
            ->and(Item::query()->where('user_id', $owner->id)->where('is_active', true)->pluck('id')->all())->toBe([$b->id])
            ->and(FocusSession::query()->where('item_id', $a->id)->sole()->end_reason)->toBe('switched')
            ->and(FocusSession::query()->where('item_id', $b->id)->whereNull('ended_at')->count())->toBe(1);
    });

    test('the paused task goes back to locked when its prerequisites changed while it was active', function () {
        [$owner, , $plan] = p3aOwner();
        $a = Item::factory()->for($plan)->create(['title' => 'A']);
        $b = Item::factory()->for($plan)->create(['title' => 'B']);
        app(StartItem::class)(Actor::ownerWeb($owner), $a);
        $newPrerequisite = Item::factory()->for($plan)->create(['title' => 'Nuevo requisito']);
        ItemDependency::query()->create(['prerequisite_id' => $newPrerequisite->id, 'dependent_id' => $a->id]);

        app(StartItem::class)(Actor::ownerWeb($owner), $b);

        expect(p3aState($a))->toBe('locked')->and(p3aState($b))->toBe('active');
    });

    test('a locked or done item is refused over the web and the active one stays active', function (string $case) {
        [$owner, , $plan] = p3aOwner();
        $active = Item::factory()->for($plan)->create(['title' => 'Activa']);
        app(StartItem::class)(Actor::ownerWeb($owner), $active);
        $target = match ($case) {
            'done' => Item::factory()->for($plan)->done()->create(),
            'locked' => tap(Item::factory()->for($plan)->create(), function (Item $locked) use ($plan) {
                ItemDependency::query()->create(['prerequisite_id' => Item::factory()->for($plan)->create()->id, 'dependent_id' => $locked->id]);
            }),
        };

        $this->actingAs($owner)->from('/now')->post("/objectives/DIARIO/items/{$target->key}/start")
            ->assertRedirect('/now')->assertSessionHasErrors('item');

        expect($target->refresh()->is_active)->toBeFalse()
            ->and($active->refresh()->is_active)->toBeTrue()
            ->and(FocusSession::query()->where('item_id', $target->id)->count())->toBe(0);
    })->with(['locked', 'done']);

    test('a retired item or another owner\'s item cannot be started (404) and nothing moves', function () {
        [$owner, , $plan] = p3aOwner();
        $retired = Item::factory()->for($plan)->retired()->create();
        [$other] = p3aOwner('OTRO');

        $this->actingAs($owner)->post("/objectives/DIARIO/items/{$retired->key}/start")->assertNotFound();
        $this->actingAs($other)->post('/objectives/DIARIO/items/DIARIO-1/start')->assertNotFound();
        auth()->logout();
        $this->post('/objectives/DIARIO/items/DIARIO-1/start')->assertRedirect('/login');

        expect(Item::query()->where('is_active', true)->count())->toBe(0);
    });

    test('whatever item the start action accepts, the Now screen shows it as the active task', function (string $case) {
        // now-focus: "The Now screen MUST show ... the active task". If
        // StartItem lets an item become active, NowView must not hide it
        // behind a different suggestion (that would make "Empezar" on the
        // suggestion silently release the real active task).
        [$owner, $objective, $plan] = p3aOwner();
        Item::factory()->for($plan)->create(['title' => 'Sugerible']);
        $target = match ($case) {
            'draft plan' => Item::factory()->for(Plan::factory()->for($objective)->draft()->create())->create(['title' => 'En plan borrador']),
            'draft objective' => Item::factory()->for(Plan::factory()->for(Objective::factory()->for($owner)->draft()->create(['key' => 'BORRADOR']))->create())->create(['title' => 'En objetivo borrador']),
        };

        if ($case === 'draft plan') {
            // Reachable from the web: the draft plan's objective is active.
            $this->actingAs($owner)->from('/now')->post("/objectives/DIARIO/items/{$target->key}/start");
        } else {
            try {
                app(StartItem::class)(Actor::ownerWeb($owner), $target);
            } catch (ValidationException) {
                // Refusing to start it is an acceptable answer too.
            }
        }

        if (! $target->refresh()->is_active) {
            expect(true)->toBeTrue();

            return;
        }

        expect(app(NowView::class)->present($owner))
            ->toMatchArray(['key' => $target->key, 'is_active' => true]);
    })->with(['draft plan', 'draft objective']);

    test('the database itself refuses a second active item of the same owner, but not of different owners', function () {
        [$owner, , $plan] = p3aOwner();
        $a = Item::factory()->for($plan)->create();
        $b = Item::factory()->for($plan)->create();
        [, , $otherPlan] = p3aOwner('OTRO');
        $foreign = Item::factory()->for($otherPlan)->create();

        DB::table('items')->whereIn('id', [$a->id, $foreign->id])->update(['is_active' => true]);

        expect(fn () => DB::transaction(fn () => DB::table('items')->where('id', $b->id)->update(['is_active' => true])))
            ->toThrow(QueryException::class);
        expect(DB::table('items')->where('is_active', true)->pluck('id')->sort()->values()->all())
            ->toBe(collect([$a->id, $foreign->id])->sort()->values()->all());
    });

    test('a raw insert with a wrong owner is corrected to the objective owner by the database', function () {
        [$owner, $objective, $plan] = p3aOwner();
        $stranger = User::factory()->create();

        $id = DB::table('items')->insertGetId([
            'user_id' => $stranger->id, 'objective_id' => $objective->id, 'plan_id' => $plan->id, 'number' => 99,
            'kind' => 'task', 'title' => 'Crudo', 'two_minute_version' => 'x', 'is_active' => false, 'position' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('items')->where('id', $id)->update(['user_id' => $stranger->id]);

        expect(DB::table('items')->where('id', $id)->value('user_id'))->toBe($owner->id);
    });

    test('starting the already active task again never resets its focus clock', function () {
        Carbon::setTestNow('2026-09-27 15:00:00');
        [$owner, , $plan] = p3aOwner();
        $task = Item::factory()->for($plan)->create();
        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start');

        Carbon::setTestNow('2026-09-27 15:20:00');
        $this->post('/objectives/DIARIO/items/DIARIO-1/start')->assertSessionHasNoErrors();

        expect(FocusSession::query()->where('item_id', $task->id)->count())->toBe(1)
            ->and(p3aNowProps($owner)['now']['focus_started_at'])->toBe('2026-09-27T15:00:00+00:00');
    });

    test('the plain stop route only releases the task; Now suggests it again without a focus clock', function () {
        [$owner, , $plan] = p3aOwner();
        $task = Item::factory()->for($plan)->create();
        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start');

        $this->post('/objectives/DIARIO/items/DIARIO-1/stop')->assertRedirect();

        $now = p3aNowProps($owner)['now'];
        expect($now['key'])->toBe('DIARIO-1')
            ->and($now['is_active'])->toBeFalse()
            ->and($now['focus_started_at'])->toBeNull()
            ->and(FocusSession::query()->where('item_id', $task->id)->sole()->end_reason)->toBe('closed-for-today');
    });
});

// ---------------------------------------------------------------- selection

describe('Now selection (design D6)', function () {
    test('the active item wins over the weekly main priority', function () {
        [$owner, $objective, $plan] = p3aOwner();
        $priorityObjective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'PRIO', 'position' => 3]);
        $priorityPlan = Plan::factory()->for($priorityObjective)->create();
        Item::factory()->for($priorityPlan)->create(['title' => 'De la prioridad']);
        $active = Item::factory()->for($plan)->create(['title' => 'La activa']);
        app(StartItem::class)(Actor::ownerWeb($owner), $active);
        p3aPriority($priorityObjective);

        expect(app(NowView::class)->select($owner)->id)->toBe($active->id);
    });

    test('with a weekly priority objective the first available item by plan position then item position is suggested', function () {
        [$owner, $objective] = p3aOwner();
        $later = Plan::factory()->for($objective)->create(['title' => 'Plan 2', 'position' => 5]);
        $first = Plan::factory()->for($objective)->create(['title' => 'Plan 1', 'position' => 1]);
        Item::factory()->for($later)->create(['title' => 'Del plan 2']);
        $done = Item::factory()->for($first)->create(['title' => 'Hecha', 'position' => 0]);
        $done->update(['completed_at' => now()]);
        Item::factory()->for($first)->create(['title' => 'Segunda', 'position' => 7]);
        Item::factory()->for($first)->create(['title' => 'Primera disponible', 'position' => 3]);
        // The first plan created in p3aOwner has position 0 but no items.
        p3aPriority($objective);

        expect(app(NowView::class)->select($owner)->title)->toBe('Primera disponible');
    });

    test('without a priority, the OLDEST available item of the first active objective is suggested, not the first by position', function () {
        [$owner, $objective, $plan] = p3aOwner('PRIMERO', ['position' => 0]);
        $second = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SEGUNDO', 'position' => 1]);
        $secondPlan = Plan::factory()->for($second)->create();
        Item::factory()->for($secondPlan)->create(['title' => 'Del segundo, muy vieja', 'created_at' => now()->subYear()]);

        Carbon::setTestNow('2026-09-20 12:00:00');
        $old = Item::factory()->for($plan)->create(['title' => 'Vieja', 'position' => 9]);
        Carbon::setTestNow('2026-09-25 12:00:00');
        $new = Item::factory()->for($plan)->create(['title' => 'Nueva']);
        $new->update(['position' => 0]);
        Carbon::setTestNow();

        expect(app(NowView::class)->select($owner)->id)->toBe($old->id);
    });

    test('with no active task exactly one suggestion is sent to the screen and no other available task leaks', function () {
        [$owner, , $plan] = p3aOwner();
        Item::factory()->for($plan)->create(['title' => 'Única sugerida']);
        foreach (range(1, 5) as $n) {
            Item::factory()->for($plan)->create(['title' => "Otra disponible {$n}"]);
        }

        $props = p3aNowProps($owner);

        expect($props['now']['title'])->toBe('Única sugerida')
            ->and($props['now']['is_active'])->toBeFalse()
            ->and(json_encode($props))->not->toContain('Otra disponible');
    });

    test('with nothing available the screen has no task (empty invitation), and no error', function () {
        [$owner, , $plan] = p3aOwner();
        Item::factory()->for($plan)->done()->create();
        $locked = Item::factory()->for($plan)->create();
        $open = Item::factory()->for($plan)->retired()->create();
        ItemDependency::query()->create(['prerequisite_id' => Item::factory()->for(Plan::factory()->for(Objective::factory()->for($owner)->closed()->create(['key' => 'CERRADO']))->create())->create()->id, 'dependent_id' => $locked->id]);

        $this->actingAs($owner)->get('/now')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('now')->where('now', null));
    });
});

// ---------------------------------------------------------------- restart

describe('restart without debt on the UTC-6 calendar', function () {
    dataset('p3a boundaries', [
        // [last activity UTC, now UTC, restart expected]
        'last 23:59:59 GT two days ago, now 00:00:30 GT' => ['2026-09-27 05:59:59', '2026-09-28 06:00:30', true],
        'last 00:00:00 GT yesterday, now 23:59 GT today' => ['2026-09-27 06:00:00', '2026-09-29 05:59:00', false],
        'UTC already tomorrow but GT still yesterday+1' => ['2026-09-26 18:00:00', '2026-09-28 05:59:00', false],
        'one second later it is a new GT day' => ['2026-09-26 18:00:00', '2026-09-28 06:00:00', true],
        'activity earlier today (GT)' => ['2026-09-28 06:00:01', '2026-09-28 20:00:00', false],
    ]);

    test('the restart offer follows America/Guatemala days, whatever the activity', function (string $last, string $now, bool $expected, string $kind) {
        [$owner, , $plan] = p3aOwner();
        $task = Item::factory()->for($plan)->create(['title' => 'Captura 10 min', 'two_minute_version' => 'abrir el inbox']);
        Carbon::setTestNow('2026-09-01 12:00:00');
        Item::factory()->for($plan)->done()->create(); // ancient history so the owner is not new

        Carbon::setTestNow($last);
        match ($kind) {
            'check' => Item::factory()->for($plan)->done()->create(),
            'habit' => Habit::factory()->for($owner)->create()->recordEntry(1),
            'start' => FocusSession::query()->create(['item_id' => $task->id, 'started_at' => now(), 'ended_at' => now(), 'end_reason' => 'switched']),
        };

        Carbon::setTestNow($now);

        $props = p3aNowProps($owner);
        expect($props['restart'])->toBe($expected)
            ->and($props['now']['two_minute_version'])->toBe('abrir el inbox');
    })->with('p3a boundaries')->with(['check', 'habit', 'start']);

    test('the restart rule does not depend on the database session time zone', function () {
        [$owner, , $plan] = p3aOwner();
        Item::factory()->for($plan)->create();
        Carbon::setTestNow('2026-09-27 12:00:00'); // GT 06:00 on the 27th
        Item::factory()->for($plan)->done()->create();
        Habit::factory()->for($owner)->create()->recordEntry(1);
        Carbon::setTestNow('2026-09-28 12:00:00'); // GT 06:00 on the 28th: activity was yesterday

        DB::statement("SET TIME ZONE 'America/Guatemala'");

        try {
            expect(app(NowView::class)->needsRestart($owner))->toBeFalse();
        } finally {
            DB::statement("SET TIME ZONE 'UTC'");
        }
    });

    test('returning after three days: restart with the 2-minute action and no count of days anywhere', function () {
        [$owner, , $plan] = p3aOwner();
        Carbon::setTestNow('2026-09-24 18:00:00');
        Item::factory()->for($plan)->done()->create();
        Item::factory()->for($plan)->create(['title' => 'Captura 10 min', 'two_minute_version' => 'abrir el inbox']);
        Carbon::setTestNow('2026-09-28 18:00:00');

        $props = p3aNowProps($owner);
        $json = json_encode($props, JSON_UNESCAPED_UNICODE);

        expect($props['restart'])->toBeTrue()
            ->and($props['now']['two_minute_version'])->toBe('abrir el inbox')
            ->and($json)->not->toMatch('/\d+\s*d[ií]as?|missed|atrasad|vencid|streak_lost|days_(away|missed)/iu');
    });
});

// ---------------------------------------------------------------- stuck

describe('"estoy trabado" fallback without AI', function () {
    test('the smaller action becomes the 2-minute version; each previous one is kept in history, newest first, as the owner\'s', function () {
        [$owner, , $plan] = p3aOwner();
        $task = Item::factory()->for($plan)->create(['title' => 'Captura 10 min', 'description' => 'Volcar todo', 'two_minute_version' => 'abrir el inbox']);
        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start');

        Carbon::setTestNow('2026-09-28 15:00:00');
        $this->from('/now')->post('/objectives/DIARIO/items/DIARIO-1/two-minute', ['two_minute_version' => '  escribir un título  '])
            ->assertRedirect('/now')->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-09-28 15:05:00');
        $this->post('/objectives/DIARIO/items/DIARIO-1/two-minute', ['two_minute_version' => 'tomar el lápiz']);

        $task->refresh();
        $history = $task->twoMinuteHistory()->get();

        expect($task->two_minute_version)->toBe('tomar el lápiz')
            ->and($task->title)->toBe('Captura 10 min')
            ->and($task->description)->toBe('Volcar todo')
            ->and($task->is_active)->toBeTrue()
            ->and($history->pluck('text')->all())->toBe(['escribir un título', 'abrir el inbox'])
            ->and($history->pluck('source')->unique()->all())->toBe([TwoMinuteSource::Owner])
            ->and(p3aNowProps($owner)['now']['two_minute_version'])->toBe('tomar el lápiz');
    });

    test('empty, blank or unchanged actions are refused and nothing is recorded', function (mixed $value) {
        [$owner, , $plan] = p3aOwner();
        $task = Item::factory()->for($plan)->create(['two_minute_version' => 'abrir el inbox']);

        $this->actingAs($owner)->from('/now')->post('/objectives/DIARIO/items/DIARIO-1/two-minute', ['two_minute_version' => $value])
            ->assertSessionHasErrors('two_minute_version');

        expect($task->refresh()->two_minute_version)->toBe('abrir el inbox')
            ->and($task->twoMinuteHistory()->count())->toBe(0);
    })->with(['empty' => '', 'blank' => "   \n ", 'same' => 'abrir el inbox', 'same padded' => '  abrir el inbox ', 'too long' => str_repeat('a', 256), 'array' => [['x']]]);

    test('a done item, a closed objective and a foreign item are not shrunk', function () {
        [$owner, , $plan] = p3aOwner();
        Item::factory()->for($plan)->done()->create();
        [$closedOwner, $closed, $closedPlan] = p3aOwner('CERRADO');
        Item::factory()->for($closedPlan)->create();
        $closed->update(['state' => 'closed', 'closed_at' => now()]);

        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/two-minute', ['two_minute_version' => 'otra'])->assertSessionHasErrors('item');
        $this->actingAs($closedOwner)->post('/objectives/CERRADO/items/CERRADO-1/two-minute', ['two_minute_version' => 'otra'])->assertSessionHasErrors('objective');
        $this->actingAs($owner)->post('/objectives/CERRADO/items/CERRADO-1/two-minute', ['two_minute_version' => 'otra'])->assertNotFound();

        expect(DB::table('item_two_minute_history')->count())->toBe(0);
    });

    test('an AI actor shrinks directly (minor) and the history records the AI as the source', function () {
        [$owner, , $plan] = p3aOwner();
        $task = Item::factory()->for($plan)->create(['two_minute_version' => 'abrir el inbox']);

        app(ShrinkTwoMinuteVersion::class)(Actor::aiMcp($owner), $task, 'mirar el inbox');

        expect($task->refresh()->two_minute_version)->toBe('mirar el inbox')
            ->and($task->twoMinuteHistory()->sole()->source)->toBe(TwoMinuteSource::Ai);
    });
});

// ---------------------------------------------------------------- cue data

test('the Now screen gets the persisted focus start, the server clock and a 25-minute / 60-second cue', function () {
    Carbon::setTestNow('2026-09-28 15:12:00');
    [$owner, , $plan] = p3aOwner();
    Item::factory()->for($plan)->create();
    $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start');
    Carbon::setTestNow('2026-09-28 15:32:00');

    $props = p3aNowProps($owner);

    expect($props['now']['focus_started_at'])->toBe('2026-09-28T15:12:00+00:00')
        ->and($props['server_now'])->toBe('2026-09-28T15:32:00+00:00')
        ->and($props['cue'])->toBe(['minutes' => 25, 'visible_seconds' => 60]);
});

test('a milestone completed from Now requires its evidence (summit asks for it) and a task does not', function () {
    [$owner, , $plan] = p3aOwner();
    Item::factory()->for($plan)->milestone()->create(['title' => 'Semana 1']);
    $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start');

    $this->from('/now')->post('/objectives/DIARIO/items/DIARIO-1/check', [])->assertSessionHasErrors();
    expect(Item::query()->sole()->completed_at)->toBeNull();

    $this->from('/now')->post('/objectives/DIARIO/items/DIARIO-1/check', ['evidence' => 'Ahora anda en el celu'])->assertSessionHasNoErrors();
    expect(Item::query()->sole()->completed_at)->not->toBeNull()
        ->and(Item::query()->sole()->is_active)->toBeFalse();
});

// ---------------------------------------------------------------- MCP

describe('MCP now-view', function () {
    test('is listed, states the read tier and never exposes a start/stop tool', function () {
        [$owner] = p3aOwner();

        $tools = collect(p3aMcp($owner, 'tools/list', [])['result']['tools'])->keyBy('name');

        expect($tools)->toHaveKey('now-view')
            ->and($tools['now-view']['description'])->toStartWith('Nivel IA: read')
            // Phase 8 (ai-operations spec) adds `start-item` as a minor,
            // audited tool — the one exception to "no start/stop tool".
            ->and($tools->keys()->filter(fn ($name) => ($name !== 'start-item') && (str_contains($name, 'start') || str_contains($name, 'stop')))->all())->toBe([]);
    });

    test('returns exactly what the web Now screen gets, for an active task and for a suggestion', function (bool $active) {
        Carbon::setTestNow('2026-09-28 15:00:00');
        [$owner, , $plan] = p3aOwner();
        $task = Item::factory()->for($plan)->create(['title' => 'Captura 10 min', 'two_minute_version' => 'abrir el inbox']);
        $next = Item::factory()->for($plan)->create(['title' => 'Clasificar']);
        $parallel = Item::factory()->for($plan)->create(['title' => 'Otra que abre']);
        ItemDependency::query()->create(['prerequisite_id' => $task->id, 'dependent_id' => $next->id]);
        ItemDependency::query()->create(['prerequisite_id' => $task->id, 'dependent_id' => $parallel->id]);
        if ($active) {
            app(StartItem::class)(Actor::ownerWeb($owner), $task);
        }

        $mcp = json_decode(p3aMcp($owner)['result']['content'][0]['text'], true);
        $web = p3aNowProps($owner);

        expect($mcp['now'])->toBe($web['now'])
            ->and($mcp['restart'])->toBe($web['restart'])
            ->and($mcp['now']['two_minute_version'])->toBe('abrir el inbox')
            ->and(collect($mcp['now']['unlocks'])->pluck('title')->sort()->values()->all())->toBe(['Clasificar', 'Otra que abre'])
            ->and($mcp['now']['url'])->toBe('http://localhost:8000/objectives/DIARIO/items/DIARIO-1')
            ->and($mcp['now_url'])->toMatch('#^https?://[^/]+/now$#');
    })->with(['active' => true, 'suggestion' => false]);

    test('is scoped to the token owner and returns null now when that owner has nothing', function () {
        [$owner, , $plan] = p3aOwner();
        Item::factory()->for($plan)->create();
        $stranger = User::factory()->create();

        $mcp = json_decode(p3aMcp($stranger)['result']['content'][0]['text'], true);

        expect($mcp['now'])->toBeNull()->and($mcp['restart'])->toBeFalse();
    });
});

// ---------------------------------------------------------------- landing

describe('auth landing is Now', function () {
    test('an authenticated root visit and the login screen redirect to Now; a guest goes to login', function () {
        $owner = User::factory()->create();

        $this->get('/')->assertRedirect('/login');
        $this->get('/now')->assertRedirect('/login');
        $this->actingAs($owner)->get('/')->assertRedirect('/now');
        $this->get('/login')->assertRedirect('/now');
    });

    test('a successful login lands on Now and a wrong password does not authenticate', function () {
        $owner = User::factory()->create(['password' => 'secreta-123']);

        $this->post('/login', ['email' => $owner->email, 'password' => 'mala'])->assertSessionHasErrors();
        $this->assertGuest();

        $this->post('/login', ['email' => $owner->email, 'password' => 'secreta-123'])->assertRedirect('/now');
        $this->assertAuthenticatedAs($owner);
    });
});

// ---------------------------------------------------------------- round 2

describe('round 2: start guards and "Cerrar por hoy" per UTC-6 day', function () {
    test('an item of a draft or retired plan or of a non-active objective is refused with a Spanish item error and the active task stays', function (string $case) {
        [$owner, $objective, $plan] = p3aOwner();
        $active = Item::factory()->for($plan)->create(['title' => 'Activa']);
        app(StartItem::class)(Actor::ownerWeb($owner), $active);
        $target = match ($case) {
            'draft plan' => Item::factory()->for(Plan::factory()->for($objective)->draft()->create())->create(),
            'retired plan' => Item::factory()->for(Plan::factory()->for($objective)->create(['state' => 'retired']))->create(),
            'draft objective' => Item::factory()->for(Plan::factory()->for(Objective::factory()->for($owner)->draft()->create(['key' => 'BORRADOR']))->create())->create(),
        };

        $errors = mosErrors(fn () => app(StartItem::class)(Actor::ownerWeb($owner), $target->refresh()));

        expect($errors)->toHaveKey('item')
            ->and($errors['item'][0])->toMatch('/borrador|retirad|no está activo/u')
            ->and($target->refresh()->is_active)->toBeFalse()
            ->and($active->refresh()->is_active)->toBeTrue()
            ->and(app(NowView::class)->present($owner)['key'])->toBe($active->key);
    })->with(['draft plan', 'retired plan', 'draft objective']);

    test('an active task whose objective is no longer active is still THE Now task (never hidden) in web and MCP', function () {
        [$owner, $objective, $plan] = p3aOwner();
        $active = Item::factory()->for($plan)->create(['title' => 'Activa']);
        Item::factory()->for(Plan::factory()->for(Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'OTRO']))->create())->create();
        app(StartItem::class)(Actor::ownerWeb($owner), $active);
        $objective->update(['state' => 'closed', 'closed_at' => now()]);

        $web = p3aNowProps($owner)['now'];
        $mcp = json_decode(p3aMcp($owner)['result']['content'][0]['text'], true)['now'];

        expect($web['key'])->toBe('DIARIO-1')->and($web['is_active'])->toBeTrue()->and($mcp)->toBe($web);
    });

    test('"Cerrar por hoy" on the active task stops it and Now does not suggest it again that day; the page knows it closed', function () {
        Carbon::setTestNow('2026-09-28 16:00:00'); // GT 10:00
        [$owner, , $plan] = p3aOwner();
        $task = Item::factory()->for($plan)->create(['title' => 'Primera']);
        Item::factory()->for($plan)->create(['title' => 'Segunda']);
        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start');

        $this->from('/now')->post('/now/close-for-today')->assertRedirect('/now')->assertSessionHasNoErrors();

        $props = p3aNowProps($owner);
        expect($task->refresh()->is_active)->toBeFalse()
            ->and(FocusSession::query()->where('item_id', $task->id)->sole()->end_reason)->toBe('closed-for-today')
            ->and($props['closed_today'])->toBeTrue()
            ->and($props['now']['title'])->toBe('Segunda');
    });

    test('a dismissal lasts until Guatemala midnight, not UTC midnight', function () {
        [$owner, , $plan] = p3aOwner();
        Item::factory()->for($plan)->create(['title' => 'Única']);

        Carbon::setTestNow('2026-09-28 05:59:00'); // GT 23:59 of the 27th (already the 28th in UTC)
        $this->actingAs($owner)->post('/now/close-for-today')->assertSessionHasNoErrors();
        expect(p3aNowProps($owner)['now'])->toBeNull();

        Carbon::setTestNow('2026-09-28 05:59:59');
        expect(p3aNowProps($owner)['now'])->toBeNull();

        Carbon::setTestNow('2026-09-28 06:00:00'); // GT 00:00 of the 28th
        $props = p3aNowProps($owner);
        expect($props['now']['title'])->toBe('Única')->and($props['closed_today'])->toBeFalse();

        Carbon::setTestNow('2026-09-28 17:00:00'); // dismiss on the 28th GT, still suggested again on the 29th GT
        $this->post('/now/close-for-today');
        Carbon::setTestNow('2026-09-29 05:59:00');
        expect(p3aNowProps($owner)['now'])->toBeNull();
        Carbon::setTestNow('2026-09-29 06:00:00');
        expect(p3aNowProps($owner)['now']['title'])->toBe('Única');
    });

    test('a dismissed item can still be started explicitly and then it is the Now task, in web and MCP', function () {
        [$owner, , $plan] = p3aOwner();
        Item::factory()->for($plan)->create(['title' => 'Cerrada hoy']);
        $this->actingAs($owner)->post('/now/close-for-today');
        expect(p3aNowProps($owner)['now'])->toBeNull();

        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start')->assertSessionHasNoErrors();

        $web = p3aNowProps($owner)['now'];
        $mcp = json_decode(p3aMcp($owner)['result']['content'][0]['text'], true)['now'];
        expect($web['title'])->toBe('Cerrada hoy')->and($web['is_active'])->toBeTrue()->and($mcp)->toBe($web);

        // Closing again for today stops it again; nothing breaks on the duplicate dismissal.
        $this->actingAs($owner)->post('/now/close-for-today')->assertSessionHasNoErrors();
        expect(Item::query()->sole()->is_active)->toBeFalse()
            ->and(DB::table('now_dismissals')->count())->toBe(1);
    });

    test('MCP now-view matches the web after a dismissal, and "Cerrar por hoy" is owner-scoped and guest-proof', function () {
        [$owner, , $plan] = p3aOwner();
        Item::factory()->for($plan)->create(['title' => 'A']);
        Item::factory()->for($plan)->create(['title' => 'B']);
        [$other, , $otherPlan] = p3aOwner('OTRO');
        Item::factory()->for($otherPlan)->create(['title' => 'Ajena']);

        $this->actingAs($owner)->post('/now/close-for-today');

        $mcp = json_decode(p3aMcp($owner)['result']['content'][0]['text'], true);
        expect($mcp['now'])->toBe(p3aNowProps($owner)['now'])->and($mcp['now']['title'])->toBe('B')
            ->and(p3aNowProps($other)['now']['title'])->toBe('Ajena')
            ->and(p3aNowProps($other)['closed_today'])->toBeFalse();

        expect(DB::table('now_dismissals')->pluck('user_id')->all())->toBe([$owner->id]);
    });

    test('a guest cannot close for today', function () {
        $this->post('/now/close-for-today')->assertRedirect('/login');
        expect(DB::table('now_dismissals')->count())->toBe(0);
    });

    test('the restart rule goes through the LastActivity seam', function () {
        [$owner, , $plan] = p3aOwner();
        Item::factory()->for($plan)->create();
        app()->instance(LastActivity::class, new class implements LastActivity
        {
            public function lastActivityAt(User $owner): ?CarbonInterface
            {
                return now()->subDays(5);
            }
        });

        expect(p3aNowProps($owner)['restart'])->toBeTrue();
    });
});

// ---------------------------------------------------------------- round 3

describe('round 3: "Cerrar por hoy" acts on the task on screen', function () {
    test('after checking the task, closing with its (old) key closes the day without dismissing the task it just unlocked', function () {
        Carbon::setTestNow('2026-09-28 16:00:00');
        [$owner, , $plan] = p3aOwner();
        $task = Item::factory()->for($plan)->create(['title' => 'Hecha ahora']);
        $next = Item::factory()->for($plan)->create(['title' => 'Recién abierta']);
        ItemDependency::query()->create(['prerequisite_id' => $task->id, 'dependent_id' => $next->id]);
        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start');
        $this->post('/objectives/DIARIO/items/DIARIO-1/check')->assertSessionHasNoErrors();

        $this->from('/now')->post('/now/close-for-today', ['item' => 'DIARIO-1'])->assertRedirect('/now')->assertSessionHasNoErrors();

        $props = p3aNowProps($owner);
        expect($props['closed_today'])->toBeTrue()
            ->and($props['now']['title'])->toBe('Recién abierta')
            ->and(DB::table('now_dismissals')->pluck('item_id')->all())->toBe([$task->id])
            ->and(json_decode(p3aMcp($owner)['result']['content'][0]['text'], true)['now'])->toBe($props['now']);
    });

    test('a stale tab closing a task that is no longer the active one never stops the active task', function () {
        [$owner, , $plan] = p3aOwner();
        Item::factory()->for($plan)->create(['title' => 'Vieja pestaña']);
        $b = Item::factory()->for($plan)->create(['title' => 'Activa en otra pestaña']);
        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start');
        $this->post('/objectives/DIARIO/items/DIARIO-2/start');

        $this->post('/now/close-for-today', ['item' => 'DIARIO-1'])->assertSessionHasNoErrors();

        expect($b->refresh()->is_active)->toBeTrue()
            ->and(p3aNowProps($owner)['now']['key'])->toBe('DIARIO-2')
            ->and(FocusSession::query()->where('item_id', $b->id)->whereNull('ended_at')->count())->toBe(1);
    });

    test('closing the active task by its key stops it and dismisses only it', function () {
        [$owner, , $plan] = p3aOwner();
        $task = Item::factory()->for($plan)->create(['title' => 'Activa']);
        Item::factory()->for($plan)->create(['title' => 'Otra']);
        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start');

        $this->post('/now/close-for-today', ['item' => 'DIARIO-1'])->assertSessionHasNoErrors();

        expect($task->refresh()->is_active)->toBeFalse()
            ->and(DB::table('now_dismissals')->pluck('item_id')->all())->toBe([$task->id])
            ->and(p3aNowProps($owner)['now']['title'])->toBe('Otra');
    });

    test('a foreign, retired, unknown or malformed key is refused and nothing is recorded or stopped', function (string|array $key, int $status) {
        [$owner, , $plan] = p3aOwner();
        $mine = Item::factory()->for($plan)->create();
        Item::factory()->for($plan)->retired()->create();
        [, , $otherPlan] = p3aOwner('OTRO');
        Item::factory()->for($otherPlan)->create();
        $this->actingAs($owner)->post('/objectives/DIARIO/items/DIARIO-1/start');

        $response = $this->from('/now')->post('/now/close-for-today', ['item' => $key]);

        $status === 404 ? $response->assertNotFound() : $response->assertSessionHasErrors('item');
        expect(DB::table('now_dismissals')->count())->toBe(0)
            ->and($mine->refresh()->is_active)->toBeTrue();
    })->with([
        'foreign' => ['OTRO-1', 404],
        'retired' => ['DIARIO-2', 404],
        'unknown' => ['DIARIO-99', 404],
        'malformed' => ['diario 1; drop', 404],
        'array' => [['DIARIO-1'], 302],
        'too long' => [str_repeat('A', 41), 302],
    ]);
});
