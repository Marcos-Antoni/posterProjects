<?php

use App\Actions\Items\CheckItem;
use App\Actions\Items\StartItem;
use App\Actions\Items\StopItem;
use App\Actions\Support\Actor;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Enums\ItemState;
use App\Models\FocusSession;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| Task 3.1 — StartItem (now-focus "Exactly One Active Task At A Time", design
| D6): at most one active item per owner across all objectives, enforced by a
| partial unique index; starting releases the previous one and moves the
| focus session with it.
*/

function p3Item(User $owner, string $key = 'DIARIO', array $attributes = []): Item
{
    $objective = Objective::query()->where('user_id', $owner->id)->where('key', $key)->first()
        ?? Objective::factory()->for($owner)->withControlPlan()->create(['key' => $key]);
    $plan = $objective->plans()->first() ?? Plan::factory()->for($objective)->create();

    return Item::factory()->for($plan)->create($attributes);
}

test('starting an available item makes it active and opens a focus session at now', function () {
    Carbon::setTestNow('2026-09-27 15:12:00');
    $owner = User::factory()->create();
    $item = p3Item($owner);

    $started = app(StartItem::class)(Actor::ownerWeb($owner), $item);

    expect($started->state)->toBe(ItemState::Active)
        ->and($started->is_active)->toBeTrue()
        ->and($item->focusSessions()->whereNull('ended_at')->count())->toBe(1)
        ->and($item->focusSessions()->first()->started_at->toDateTimeString())->toBe('2026-09-27 15:12:00');
});

test('starting a second task pauses the first across objectives and closes its focus session', function () {
    $owner = User::factory()->create();
    $first = p3Item($owner, 'DIARIO');
    $second = p3Item($owner, 'FINALES');
    $start = app(StartItem::class);

    $start(Actor::ownerWeb($owner), $first);
    $start(Actor::ownerWeb($owner), $second);

    expect($first->refresh()->state)->toBe(ItemState::Available)
        ->and($second->refresh()->state)->toBe(ItemState::Active)
        ->and($first->focusSessions()->first()->ended_at)->not->toBeNull()
        ->and($first->focusSessions()->first()->end_reason)->toBe('switched')
        ->and($second->focusSessions()->whereNull('ended_at')->count())->toBe(1)
        ->and(Item::query()->where('is_active', true)->count())->toBe(1);
});

test('starting the already active item keeps its focus clock running', function () {
    Carbon::setTestNow('2026-09-27 15:00:00');
    $owner = User::factory()->create();
    $item = p3Item($owner);
    $start = app(StartItem::class);
    $start(Actor::ownerWeb($owner), $item);

    Carbon::setTestNow('2026-09-27 15:20:00');
    $start(Actor::ownerWeb($owner), $item->refresh());

    expect($item->focusSessions()->count())->toBe(1)
        ->and($item->focusSessions()->first()->started_at->toDateTimeString())->toBe('2026-09-27 15:00:00')
        ->and($item->focusSessions()->first()->ended_at)->toBeNull();
});

test('a locked or done item cannot be started', function (string $case) {
    $owner = User::factory()->create();
    $item = p3Item($owner);

    match ($case) {
        'locked' => ItemDependency::query()->create([
            'prerequisite_id' => p3Item($owner)->id,
            'dependent_id' => $item->id,
        ]),
        'done' => $item->update(['completed_at' => now()]),
    };

    $errors = mosErrors(fn () => app(StartItem::class)(Actor::ownerWeb($owner), $item->refresh()));

    expect($errors)->toHaveKey('item')
        ->and($item->refresh()->is_active)->toBeFalse()
        ->and($item->focusSessions()->count())->toBe(0);
})->with(['locked', 'done']);

test('a retired item cannot be started (it does not exist for the owner)', function () {
    $owner = User::factory()->create();
    $item = p3Item($owner, 'DIARIO', ['retired_at' => now()]);

    expect(fn () => app(StartItem::class)(Actor::ownerWeb($owner), $item))->toThrow(ModelNotFoundException::class)
        ->and($item->refresh()->is_active)->toBeFalse();
});

test('a failed start leaves the previously active item active', function () {
    $owner = User::factory()->create();
    $active = p3Item($owner);
    $done = p3Item($owner, 'DIARIO', ['completed_at' => now()]);
    app(StartItem::class)(Actor::ownerWeb($owner), $active);

    mosErrors(fn () => app(StartItem::class)(Actor::ownerWeb($owner), $done));

    expect($active->refresh()->state)->toBe(ItemState::Active)
        ->and($active->focusSessions()->whereNull('ended_at')->count())->toBe(1);
});

test('an item of a closed objective cannot be started', function () {
    $owner = User::factory()->create();
    $item = p3Item($owner);
    $item->objective->update(['state' => 'closed', 'closed_at' => now()]);

    expect(mosErrors(fn () => app(StartItem::class)(Actor::ownerWeb($owner), $item->refresh())))->toHaveKey('objective');
});

test('starting never touches another owner\'s active item', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $mine = p3Item($owner);
    $theirs = p3Item($other, 'AJENO');
    app(StartItem::class)(Actor::ownerWeb($other), $theirs);

    app(StartItem::class)(Actor::ownerWeb($owner), $mine);

    expect($theirs->refresh()->state)->toBe(ItemState::Active)
        ->and($mine->refresh()->state)->toBe(ItemState::Active);
});

test('an owner cannot start an item of another owner', function () {
    $owner = User::factory()->create();
    $foreign = p3Item(User::factory()->create());

    expect(fn () => app(StartItem::class)(Actor::ownerWeb($owner), $foreign))
        ->toThrow(ModelNotFoundException::class);
});

test('the database refuses a second active item for the same owner', function () {
    $owner = User::factory()->create();
    p3Item($owner, 'DIARIO', ['is_active' => true]);

    expect(fn () => p3Item($owner, 'FINALES', ['is_active' => true]))->toThrow(QueryException::class);
});

test('the database allows one active item per owner for different owners', function () {
    p3Item(User::factory()->create(), 'UNO', ['is_active' => true]);
    p3Item(User::factory()->create(), 'DOS', ['is_active' => true]);

    expect(Item::query()->where('is_active', true)->count())->toBe(2);
});

test('the owner column of an item is filled from its objective, even on raw inserts', function () {
    $owner = User::factory()->create();
    $item = p3Item($owner);
    $plan = $item->plan;

    DB::table('items')->insert([
        'objective_id' => $plan->objective_id,
        'plan_id' => $plan->id,
        'number' => 99,
        'kind' => 'task',
        'title' => 'Raw',
        'two_minute_version' => 'abrir',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('items')->where('id', $item->id)->value('user_id'))->toBe($owner->id)
        ->and(DB::table('items')->where('number', 99)->value('user_id'))->toBe($owner->id);
});

test('an AI actor cannot start an item without a proposal (major operation)', function () {
    $owner = User::factory()->create();
    $item = p3Item($owner);

    expect(fn () => app(StartItem::class)(Actor::aiMcp($owner), $item))->toThrow(MajorOperationRequiresProposal::class)
        ->and($item->refresh()->is_active)->toBeFalse();
});

test('checking the active item closes its focus session and leaves no active item', function () {
    $owner = User::factory()->create();
    $item = p3Item($owner);
    app(StartItem::class)(Actor::ownerWeb($owner), $item);

    app(CheckItem::class)(Actor::ownerWeb($owner), $item->refresh());

    expect(Item::query()->where('is_active', true)->count())->toBe(0)
        ->and(FocusSession::query()->whereNull('ended_at')->count())->toBe(0);
});

test('stopping the active item for today releases it and closes its focus session', function () {
    $owner = User::factory()->create();
    $item = p3Item($owner);
    app(StartItem::class)(Actor::ownerWeb($owner), $item);

    $stopped = app(StopItem::class)(Actor::ownerWeb($owner), $item->refresh());

    expect($stopped->state)->toBe(ItemState::Available)
        ->and($item->focusSessions()->first()->end_reason)->toBe('closed-for-today')
        ->and($item->focusSessions()->whereNull('ended_at')->count())->toBe(0);
});

test('stopping an item that is not active is a no-op', function () {
    $owner = User::factory()->create();
    $item = p3Item($owner);

    $stopped = app(StopItem::class)(Actor::ownerWeb($owner), $item);

    expect($stopped->state)->toBe(ItemState::Available)
        ->and($item->focusSessions()->count())->toBe(0);
});

test('the web start route starts the item and returns to where the owner was', function () {
    $owner = User::factory()->create();
    $item = p3Item($owner);

    $this->actingAs($owner)->from('/now')
        ->post(route('objectives.items.start', ['objective' => 'DIARIO', 'item' => $item->key]))
        ->assertRedirect('/now');

    expect($item->refresh()->state)->toBe(ItemState::Active);
});

test('the web start route refuses a locked item with a Spanish message', function () {
    $owner = User::factory()->create();
    $prerequisite = p3Item($owner, 'DIARIO', ['title' => 'Mesa lista']);
    $item = p3Item($owner);
    ItemDependency::query()->create(['prerequisite_id' => $prerequisite->id, 'dependent_id' => $item->id]);

    $this->actingAs($owner)->from('/now')
        ->post(route('objectives.items.start', ['objective' => 'DIARIO', 'item' => $item->key]))
        ->assertSessionHasErrors(['item' => 'Esta tarea está bloqueada: se abre al terminar Mesa lista.']);
});

test('the web start and stop routes 404 for another owner\'s item and for guests', function () {
    $item = p3Item(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->post(route('objectives.items.start', ['objective' => 'DIARIO', 'item' => $item->key]))
        ->assertNotFound();
    $this->actingAs(User::factory()->create())
        ->post(route('objectives.items.stop', ['objective' => 'DIARIO', 'item' => $item->key]))
        ->assertNotFound();

    auth()->logout();
    $this->post(route('objectives.items.start', ['objective' => 'DIARIO', 'item' => $item->key]))->assertRedirect('/login');
});

test('the web stop route releases the active item', function () {
    $owner = User::factory()->create();
    $item = p3Item($owner);
    app(StartItem::class)(Actor::ownerWeb($owner), $item);

    $this->actingAs($owner)->from('/now')
        ->post(route('objectives.items.stop', ['objective' => 'DIARIO', 'item' => $item->key]))
        ->assertRedirect('/now');

    expect($item->refresh()->state)->toBe(ItemState::Available);
});
