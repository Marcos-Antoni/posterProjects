<?php

use App\Enums\ControlZone;
use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Models\ControlMapEntry;
use App\Models\ControlPlan;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/*
| Tasks 2.4 / 2.5 — the Marcos OS domain models, their factories and the
| row-locked, never-reused item number allocation (issues spec: "Item Numbers
| Are Sequential And Scoped Per Objective"; projects spec: "Objectives Are
| Keyed And Owned By The Single Owner").
*/

test('an objective belongs to one owner and carries its plans, control plan and control map', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create();
    ControlMapEntry::factory()->for($objective, 'plannable')->create(['zone' => ControlZone::Mine]);

    $objective->refresh();

    expect($objective->user->is($owner))->toBeTrue()
        ->and($objective->state)->toBe(ObjectiveState::Active)
        ->and($objective->plans->pluck('id')->all())->toBe([$plan->id])
        ->and($objective->controlPlan)->toBeInstanceOf(ControlPlan::class)
        ->and($objective->controlMapEntries)->toHaveCount(1)
        ->and($plan->state)->toBe(PlanState::Active);
});

test('item number allocation is sequential per objective starting at one', function () {
    $objective = Objective::factory()->create();

    expect($objective->allocateNextItemNumber())->toBe(1)
        ->and($objective->allocateNextItemNumber())->toBe(2)
        ->and($objective->allocateNextItemNumber())->toBe(3)
        ->and($objective->fresh()->next_item_number)->toBe(4);
});

test('allocation never duplicates, even from stale copies of the same objective (row lock re-reads the counter)', function () {
    $objective = Objective::factory()->create();
    $staleA = Objective::query()->findOrFail($objective->id);
    $staleB = Objective::query()->findOrFail($objective->id);

    $numbers = [];

    foreach (range(1, 10) as $_) {
        $numbers[] = $staleA->allocateNextItemNumber();
        $numbers[] = $staleB->allocateNextItemNumber();
    }

    expect($numbers)->toBe(range(1, 20));
});

test('numbers are scoped per objective and may repeat across objectives', function () {
    $salud = Objective::factory()->create();
    $dinero = Objective::factory()->create();

    $a = Item::factory()->for(Plan::factory()->for($salud))->create();
    $b = Item::factory()->for(Plan::factory()->for($dinero))->create();

    expect($a->number)->toBe(1)->and($b->number)->toBe(1);
});

test('a retired item keeps its number and the next item never reuses it', function () {
    $objective = Objective::factory()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create();

    Item::factory()->for($plan)->count(2)->create();
    $third = Item::factory()->for($plan)->create();
    $third->update(['retired_at' => now()]);

    $next = Item::factory()->for($plan)->create();

    expect($third->key)->toBe('SALUD-3')
        ->and($next->number)->toBe(4)
        ->and($next->key)->toBe('SALUD-4');
});

test('the item factory keeps objective and plan consistent', function () {
    $item = Item::factory()->create();

    expect($item->objective_id)->toBe($item->plan->objective_id)
        ->and($item->kind)->toBe(ItemKind::Task)
        ->and($item->two_minute_version)->not->toBe('');
});

test('item dependencies expose prerequisites and dependents', function () {
    $plan = Plan::factory()->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();

    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    expect($b->prerequisites->pluck('id')->all())->toBe([$a->id])
        ->and($a->dependents->pluck('id')->all())->toBe([$b->id]);
});

test('the derived state scope and the php derivation agree for every state', function () {
    $plan = Plan::factory()->create();
    $done = Item::factory()->for($plan)->done()->create();
    $available = Item::factory()->for($plan)->create();
    $active = Item::factory()->for($plan)->create(['is_active' => true]);
    $retired = Item::factory()->for($plan)->create(['retired_at' => now()]);
    $locked = Item::factory()->for($plan)->create();
    $unlockedByDone = Item::factory()->for($plan)->create();
    $unlockedByRetired = Item::factory()->for($plan)->create();

    ItemDependency::query()->create(['prerequisite_id' => $available->id, 'dependent_id' => $locked->id]);
    ItemDependency::query()->create(['prerequisite_id' => $done->id, 'dependent_id' => $unlockedByDone->id]);
    ItemDependency::query()->create(['prerequisite_id' => $retired->id, 'dependent_id' => $unlockedByRetired->id]);

    $expected = [
        $done->id => ItemState::Done,
        $available->id => ItemState::Available,
        $active->id => ItemState::Active,
        $retired->id => ItemState::Retired,
        $locked->id => ItemState::Locked,
        $unlockedByDone->id => ItemState::Available,
        $unlockedByRetired->id => ItemState::Available,
    ];

    $fromSql = Item::query()->withState()->get()->mapWithKeys(fn (Item $item) => [$item->id => $item->state])->all();

    foreach ($expected as $id => $state) {
        expect($fromSql[$id])->toBe($state)
            ->and(Item::query()->findOrFail($id)->deriveState())->toBe($state);
    }
});
