<?php

/*
| Phase 2 acceptance (independent tester) — spec `unlock-graph` (Phase-2
| parts) and design D4: add/remove edges from the web, duplicate / self-edge
| / cycle rejection with the path named, cross-objective edges, derived
| locked/available state, retired prerequisites never block.
*/

use App\Enums\ItemState;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function p2dItem(Plan $plan, string $title, array $attributes = []): Item
{
    return Item::factory()->for($plan)->create(['title' => $title, ...$attributes])->load('objective');
}

function p2dState(Item $item): ItemState
{
    return Item::query()->withState()->whereKey($item->id)->firstOrFail()->state;
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->salud = Plan::factory()->for(Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'SALUD']))->create();
    $this->dinero = Plan::factory()->for(Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'DINERO']))->create();
});

test('adding a prerequisite from the web locks the dependent immediately; removing it unlocks', function () {
    $a = p2dItem($this->salud, 'Comprar zapatillas');
    $b = p2dItem($this->salud, 'Correr 5 km');

    $this->post('/objectives/SALUD/items/SALUD-2/prerequisites', ['key' => 'SALUD-1'])->assertSessionHasNoErrors();
    expect(p2dState($b))->toBe(ItemState::Locked);

    $this->delete('/objectives/SALUD/items/SALUD-2/prerequisites/SALUD-1')->assertSessionHasNoErrors();
    expect(p2dState($b))->toBe(ItemState::Available)
        ->and(ItemDependency::query()->count())->toBe(0);
});

test('adding an unlock from the prerequisite side works the same way', function () {
    p2dItem($this->salud, 'A');
    $b = p2dItem($this->salud, 'B');

    $this->post('/objectives/SALUD/items/SALUD-1/unlocks', ['key' => 'SALUD-2'])->assertSessionHasNoErrors();
    expect(p2dState($b))->toBe(ItemState::Locked);

    $this->delete('/objectives/SALUD/items/SALUD-1/unlocks/SALUD-2')->assertSessionHasNoErrors();
    expect(p2dState($b))->toBe(ItemState::Available);
});

test('a duplicate edge is rejected', function () {
    p2dItem($this->salud, 'A');
    p2dItem($this->salud, 'B');

    $this->post('/objectives/SALUD/items/SALUD-2/prerequisites', ['key' => 'SALUD-1'])->assertSessionHasNoErrors();
    $this->post('/objectives/SALUD/items/SALUD-2/prerequisites', ['key' => 'SALUD-1'])->assertSessionHasErrors();
    $this->post('/objectives/SALUD/items/SALUD-1/unlocks', ['key' => 'SALUD-2'])->assertSessionHasErrors();

    expect(ItemDependency::query()->count())->toBe(1);
});

test('a self-edge is rejected, also in lowercase', function (string $key) {
    p2dItem($this->salud, 'A');

    $this->post('/objectives/SALUD/items/SALUD-1/prerequisites', ['key' => $key])->assertSessionHasErrors();

    expect(ItemDependency::query()->count())->toBe(0);
})->with(['SALUD-1', 'salud-1', ' SALUD-1 ']);

test('the database itself refuses self-edges and duplicates', function () {
    $a = p2dItem($this->salud, 'A');
    $b = p2dItem($this->salud, 'B');

    expect(fn () => DB::transaction(fn () => DB::table('item_dependencies')->insert(['prerequisite_id' => $a->id, 'dependent_id' => $a->id])))
        ->toThrow(QueryException::class);

    DB::table('item_dependencies')->insert(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    expect(fn () => DB::transaction(fn () => DB::table('item_dependencies')->insert(['prerequisite_id' => $a->id, 'dependent_id' => $b->id])))
        ->toThrow(QueryException::class);
});

test('a direct cycle is rejected with a Spanish message naming both items', function () {
    p2dItem($this->salud, 'Alfa');
    p2dItem($this->salud, 'Beta');

    $this->post('/objectives/SALUD/items/SALUD-2/prerequisites', ['key' => 'SALUD-1'])->assertSessionHasNoErrors();
    $this->post('/objectives/SALUD/items/SALUD-1/prerequisites', ['key' => 'SALUD-2'])->assertSessionHasErrors();

    $message = collect(session('errors')->getBag('default')->all())->implode(' ');
    expect($message)->toContain('SALUD-1')->toContain('SALUD-2')->toContain('Alfa')->toContain('Beta')->toMatch('/c[íi]rculo|ciclo/iu')
        ->and(ItemDependency::query()->count())->toBe(1);
});

test('a transitive cycle A→B→C plus C→A is rejected naming A, B and C in path order', function () {
    p2dItem($this->salud, 'Alfa');
    p2dItem($this->salud, 'Beta');
    p2dItem($this->salud, 'Gama');

    // A → B, B → C  (prerequisite → dependent)
    $this->post('/objectives/SALUD/items/SALUD-1/unlocks', ['key' => 'SALUD-2'])->assertSessionHasNoErrors();
    $this->post('/objectives/SALUD/items/SALUD-2/unlocks', ['key' => 'SALUD-3'])->assertSessionHasNoErrors();

    // C → A closes the circle
    $this->post('/objectives/SALUD/items/SALUD-3/unlocks', ['key' => 'SALUD-1'])->assertSessionHasErrors();

    $message = collect(session('errors')->getBag('default')->all())->implode(' ');
    expect($message)->toContain('SALUD-1 Alfa')->toContain('SALUD-2 Beta')->toContain('SALUD-3 Gama');

    // The closing edge first, then the existing path back to it.
    expect($message)->toContain('SALUD-3 Gama → SALUD-1 Alfa → SALUD-2 Beta → SALUD-3 Gama')
        ->and(ItemDependency::query()->count())->toBe(2);
});

test('a long cycle across objectives is still rejected', function () {
    $items = [];
    foreach (range(1, 12) as $i) {
        $items[] = p2dItem($i % 2 ? $this->salud : $this->dinero, "T{$i}");
    }
    foreach (range(0, 10) as $i) {
        ItemDependency::query()->create(['prerequisite_id' => $items[$i]->id, 'dependent_id' => $items[$i + 1]->id]);
    }

    $last = $items[11]->key;
    [$prefix] = explode('-', $last);

    $this->post("/objectives/{$prefix}/items/{$last}/unlocks", ['key' => $items[0]->key])->assertSessionHasErrors();

    expect(ItemDependency::query()->count())->toBe(11);
});

test('a diamond (two paths into one item) is not a cycle', function () {
    $a = p2dItem($this->salud, 'A');
    $b = p2dItem($this->salud, 'B');
    $c = p2dItem($this->salud, 'C');
    $d = p2dItem($this->salud, 'D');
    foreach ([[$a, $b], [$a, $c], [$b, $d]] as [$p, $q]) {
        ItemDependency::query()->create(['prerequisite_id' => $p->id, 'dependent_id' => $q->id]);
    }

    $this->post('/objectives/SALUD/items/SALUD-3/unlocks', ['key' => 'SALUD-4'])->assertSessionHasNoErrors();

    expect(ItemDependency::query()->count())->toBe(4);
});

test('a cross-objective dependency locks the dependent', function () {
    p2dItem($this->dinero, 'Cobrar');
    $b = p2dItem($this->salud, 'Pagar el gimnasio');

    $this->post('/objectives/SALUD/items/SALUD-1/prerequisites', ['key' => 'DINERO-1'])->assertSessionHasNoErrors();

    expect(p2dState($b))->toBe(ItemState::Locked);
});

test('a key of another user\'s objective is never linkable and never disclosed', function () {
    $stranger = User::factory()->create();
    p2dItem(Plan::factory()->for(Objective::factory()->for($stranger)->withControlPlan()->create(['key' => 'AJENO']))->create(), 'Secreto');
    $b = p2dItem($this->salud, 'B');

    $this->post('/objectives/SALUD/items/SALUD-1/prerequisites', ['key' => 'AJENO-1'])->assertSessionHasErrors('key');
    $unknown = session('errors')->first('key');
    $this->post('/objectives/SALUD/items/SALUD-1/prerequisites', ['key' => 'NOEXISTE-1'])->assertSessionHasErrors('key');

    expect(session('errors')->first('key'))->toBe($unknown)
        ->and(ItemDependency::query()->count())->toBe(0)
        ->and(p2dState($b))->toBe(ItemState::Available);
});

test('a retired prerequisite does not block; a dependent with only retired prerequisites is available', function () {
    $a = p2dItem($this->salud, 'A');
    $b = p2dItem($this->salud, 'B');
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
    expect(p2dState($b))->toBe(ItemState::Locked);

    $a->update(['retired_at' => now()]);

    expect(p2dState($b))->toBe(ItemState::Available)
        ->and($b->fresh()->deriveState())->toBe(ItemState::Available);

    $this->post('/objectives/SALUD/items/SALUD-2/check')->assertSessionHasNoErrors();
    expect($b->fresh()->completed_at)->not->toBeNull();
});

test('state precedence: retired > done > active > locked > available, and scope matches PHP derivation', function () {
    $open = p2dItem($this->salud, 'open');
    $done = p2dItem($this->salud, 'done', ['completed_at' => now()]);
    $retiredDone = p2dItem($this->salud, 'retired', ['completed_at' => now(), 'retired_at' => now()]);
    $activeLocked = p2dItem($this->salud, 'active', ['is_active' => true]);
    $locked = p2dItem($this->salud, 'locked');
    $available = p2dItem($this->salud, 'available');
    foreach ([$activeLocked, $locked] as $dependent) {
        ItemDependency::query()->create(['prerequisite_id' => $open->id, 'dependent_id' => $dependent->id]);
    }
    ItemDependency::query()->create(['prerequisite_id' => $done->id, 'dependent_id' => $available->id]);

    $expected = [
        $retiredDone->id => ItemState::Retired,
        $done->id => ItemState::Done,
        $activeLocked->id => ItemState::Active,
        $locked->id => ItemState::Locked,
        $available->id => ItemState::Available,
    ];

    // Phase 6 (retirement spec "Retired Elements Are Hidden"): the NotRetired global scope hides
    // retired items from default queries; the derivation is checked past it with withRetired().
    foreach ($expected as $id => $state) {
        $scoped = Item::withRetired()->withState()->whereKey($id)->firstOrFail();
        expect($scoped->state)->toBe($state)
            ->and(Item::withRetired()->findOrFail($id)->deriveState())->toBe($state);
    }

    expect(Item::query()->find($retiredDone->id))->toBeNull();
});

test('a dependency cannot be added to or from a retired item', function () {
    p2dItem($this->salud, 'A', ['retired_at' => now()]);
    p2dItem($this->salud, 'B');

    $this->post('/objectives/SALUD/items/SALUD-2/prerequisites', ['key' => 'SALUD-1'])->assertSessionHasErrors();
    $this->post('/objectives/SALUD/items/SALUD-1/prerequisites', ['key' => 'SALUD-2'])->assertNotFound();

    expect(ItemDependency::query()->count())->toBe(0);
});

test('checking a prerequisite reports only the dependents it actually unlocked', function () {
    $a = p2dItem($this->salud, 'A');
    $x = p2dItem($this->salud, 'X');
    $b = p2dItem($this->salud, 'B');
    $c = p2dItem($this->dinero, 'C');
    // B needs A only; C needs A and X.
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $c->id]);
    ItemDependency::query()->create(['prerequisite_id' => $x->id, 'dependent_id' => $c->id]);

    $this->post('/objectives/SALUD/items/SALUD-1/check')
        ->assertSessionHas('unlocked', [['key' => 'SALUD-3', 'title' => 'B']]);

    expect(p2dState($c))->toBe(ItemState::Locked);
});
