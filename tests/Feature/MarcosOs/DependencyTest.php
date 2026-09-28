<?php

use App\Actions\Items\AddDependency;
use App\Actions\Items\CheckItem;
use App\Actions\Items\RemoveDependency;
use App\Enums\ItemState;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| Task 2.11 — dependencies (unlock-graph spec): add/remove, duplicate and
| self-edge rejection, recursive-CTE cycle rejection naming the path,
| cross-objective edges, and derived locked/available.
*/

test('adding a dependency locks the dependent immediately; removing it unlocks', function () {
    $plan = Plan::factory()->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();

    app(AddDependency::class)(mosOwner($a), $a, $b);
    expect($b->fresh()->state)->toBe(ItemState::Locked);

    app(RemoveDependency::class)(mosOwner($a), $a, $b);
    expect($b->fresh()->state)->toBe(ItemState::Available)
        ->and(ItemDependency::query()->count())->toBe(0);
});

test('a duplicate edge is rejected', function () {
    $plan = Plan::factory()->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();
    app(AddDependency::class)(mosOwner($a), $a, $b);

    expect(fn () => app(AddDependency::class)(mosOwner($a), $a, $b))->toThrow(ValidationException::class, 'ya existe');
    expect(ItemDependency::query()->count())->toBe(1);
});

test('a self-dependency is rejected', function () {
    $a = Item::factory()->create();

    expect(fn () => app(AddDependency::class)(mosOwner($a), $a, $a))->toThrow(ValidationException::class, 'sí misma');
});

test('the database itself refuses a self-edge and a duplicate', function () {
    $plan = Plan::factory()->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    // Each attempt runs in its own savepoint so the first violation does not
    // abort the surrounding test transaction (PostgreSQL).
    expect(fn () => DB::transaction(fn () => ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id])))
        ->toThrow(QueryException::class, 'item_dependencies_prerequisite_id_dependent_id_unique');
    expect(fn () => DB::transaction(fn () => ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $a->id])))
        ->toThrow(QueryException::class, 'item_dependencies_no_self_edge');
});

test('a transitive cycle is rejected with a message naming A, B and C in order', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create(['title' => 'Mesa lista']);
    $b = Item::factory()->for($plan)->create(['title' => 'Captura 10 min']);
    $c = Item::factory()->for($plan)->create(['title' => 'Clasificar y elegir prioridad']);
    $add = app(AddDependency::class);

    $add(mosOwner($a), $a, $b);
    $add(mosOwner($b), $b, $c);

    $errors = mosErrors(fn () => $add(mosOwner($c), $c, $a));

    expect($errors['prerequisite'][0])
        ->toStartWith('No se puede: armaría un círculo.')
        ->toContain('DIARIO-3 Clasificar y elegir prioridad → DIARIO-1 Mesa lista → DIARIO-2 Captura 10 min → DIARIO-3 Clasificar y elegir prioridad')
        ->and(ItemDependency::query()->count())->toBe(2);
});

test('a direct two-node cycle is rejected too', function () {
    $plan = Plan::factory()->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();
    app(AddDependency::class)(mosOwner($a), $a, $b);

    expect(fn () => app(AddDependency::class)(mosOwner($b), $b, $a))->toThrow(ValidationException::class, 'círculo');
});

test('parallel branches that re-join are not a cycle', function () {
    $plan = Plan::factory()->create();
    [$a, $b, $c, $d] = Item::factory()->for($plan)->count(4)->create()->all();
    $add = app(AddDependency::class);

    $add(mosOwner($a), $a, $b);
    $add(mosOwner($a), $a, $c);
    $add(mosOwner($b), $b, $d);
    $add(mosOwner($c), $c, $d);

    expect(ItemDependency::query()->count())->toBe(4);
});

test('a cross-objective dependency locks the dependent', function () {
    $dinero = Objective::factory()->withControlPlan()->create(['key' => 'DINERO']);
    $salud = Objective::factory()->for($dinero->user)->withControlPlan()->create(['key' => 'SALUD']);
    $a = Item::factory()->for(Plan::factory()->for($dinero))->create();
    $b = Item::factory()->for(Plan::factory()->for($salud))->create();

    app(AddDependency::class)(mosOwner($a), $a, $b);

    expect($b->fresh()->state)->toBe(ItemState::Locked);

    app(CheckItem::class)(mosOwner($a), $a);

    expect($b->fresh()->state)->toBe(ItemState::Available);
});

test('another owner\'s items can never be linked', function () {
    $mine = Item::factory()->create();
    $theirs = Item::factory()->create();

    expect(fn () => app(AddDependency::class)(mosOwner($mine), $theirs, $mine))
        ->toThrow(ModelNotFoundException::class);
});

test('a retired item cannot be linked, and a retired prerequisite does not block', function () {
    $plan = Plan::factory()->create();
    $retired = Item::factory()->for($plan)->retired()->create();
    $b = Item::factory()->for($plan)->create();

    expect(fn () => app(AddDependency::class)(mosOwner($b), $retired, $b))->toThrow(ValidationException::class, 'retirada');

    ItemDependency::query()->create(['prerequisite_id' => $retired->id, 'dependent_id' => $b->id]);

    expect($b->fresh()->state)->toBe(ItemState::Available);
});

test('the web adds prerequisites and unlocks by key, and shows the cycle error', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();
    $c = Item::factory()->for($plan)->create();
    $this->actingAs($objective->user);

    $this->post(route('objectives.items.prerequisites.store', ['objective' => 'DIARIO', 'item' => $b->key]), ['key' => $a->key])
        ->assertSessionHasNoErrors();
    $this->post(route('objectives.items.unlocks.store', ['objective' => 'DIARIO', 'item' => $b->key]), ['key' => $c->key])
        ->assertSessionHasNoErrors();
    $this->post(route('objectives.items.prerequisites.store', ['objective' => 'DIARIO', 'item' => $a->key]), ['key' => $c->key])
        ->assertSessionHasErrors('prerequisite');

    expect($b->prerequisites->pluck('id')->all())->toBe([$a->id])
        ->and($b->dependents->pluck('id')->all())->toBe([$c->id]);

    $this->delete(route('objectives.items.prerequisites.destroy', ['objective' => 'DIARIO', 'item' => $b->key, 'prerequisite' => $a->key]))
        ->assertRedirect();

    expect($b->fresh()->prerequisites)->toBeEmpty();
});

test('an unknown or foreign key for a dependency is a validation error, not a leak', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $item = Item::factory()->for(Plan::factory()->for($objective))->create();
    $foreign = Item::factory()->create();

    $this->actingAs($objective->user)
        ->post(route('objectives.items.prerequisites.store', ['objective' => 'DIARIO', 'item' => $item->key]), ['key' => $foreign->key])
        ->assertSessionHasErrors(['key' => 'No encontramos esa tarea o hito.']);
});
